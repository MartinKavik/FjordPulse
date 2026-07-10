import { spawn } from "node:child_process";
import { rm, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../..");
const frontendPort = Number(process.env.FJORDPULSE_LIVE_FRONTEND_PORT ?? "19073");
const httpPort = Number(process.env.FJORDPULSE_LIVE_HTTP_PORT ?? "19080");
const realtimePort = Number(process.env.FJORDPULSE_LIVE_REALTIME_PORT ?? "19081");
const surrealPort = Number(process.env.FJORDPULSE_LIVE_SURREAL_PORT ?? "19000");
const dataPath = path.join(root, ".data/playwright-live");
const frontendOrigin = `http://127.0.0.1:${frontendPort}`;
const commonEnv = {
  ...process.env,
  APP_ENV: "test",
  APP_DEBUG: "true",
  APP_VERSION: "playwright-live",
  APP_ORIGIN: frontendOrigin,
  ALLOWED_ORIGINS: frontendOrigin,
  DATA_MODE: "fake",
  SCENARIO: "normal",
  SURREAL_HTTP_URL: `http://127.0.0.1:${surrealPort}`,
  SURREAL_URL: `ws://127.0.0.1:${surrealPort}/rpc`,
  SURREAL_NAMESPACE: "fjordpulse_playwright",
  SURREAL_DATABASE: "fjordpulse_playwright",
  SURREAL_USERNAME: "fjordpulse_app",
  SURREAL_PASSWORD: "local-development-only",
  SURREAL_ROOT_USERNAME: "root",
  SURREAL_ROOT_PASSWORD: "root",
  ENTUR_CLIENT_NAME: "martinkavik-fjordpulse",
  ADMIN_USERNAME: "admin",
  ADMIN_PASSWORD: "local-development-only",
  ADMIN_SESSION_SECRET: "playwright-local-session-secret",
  REALTIME_PUBLIC_URL: `${frontendOrigin.replace("http:", "ws:")}/live`,
  HTTP_HOST: "127.0.0.1",
  HTTP_PORT: String(httpPort),
  REALTIME_HOST: "127.0.0.1",
  REALTIME_PORT: String(realtimePort),
  REALTIME_UPSTREAM: `127.0.0.1:${realtimePort}`,
  WATCH_TTL_SECONDS: "60",
  FALLBACK_POLL_SECONDS: "1",
  STATION_FRESH_SECONDS: "1",
  VEHICLE_FRESH_SECONDS: "1",
  VEHICLE_STALE_SECONDS: "30",
  VEHICLE_LOST_SECONDS: "120",
  VEHICLE_OBSERVATION_RETENTION_HOURS: "24",
  REALTIME_EVENT_RETENTION_HOURS: "24",
  BACKEND_WEBROOT: path.join(root, "backend/webroot"),
  FRONTEND_DIST: path.join(root, "frontend/dist"),
};

/** @type {Array<{name: string, child: import('node:child_process').ChildProcess}>} */
const services = [];
let stopping = false;

function forward(name, stream, destination) {
  let pending = "";
  stream?.setEncoding("utf8");
  stream?.on("data", (chunk) => {
    pending += chunk;
    const lines = pending.split(/\r?\n/);
    pending = lines.pop() ?? "";
    for (const line of lines) {
      if (line !== "") destination.write(`[live:${name}] ${line}\n`);
    }
  });
}

function start(name, command, args, options = {}) {
  const child = spawn(command, args, {
    cwd: options.cwd ?? root,
    env: { ...commonEnv, ...(options.env ?? {}) },
    detached: false,
    stdio: ["ignore", "pipe", "pipe"],
  });
  forward(name, child.stdout, process.stdout);
  forward(name, child.stderr, process.stderr);
  services.push({ name, child });
  child.once("exit", (code, signal) => {
    if (!stopping) {
      process.stderr.write(`[live:${name}] stopped unexpectedly (code=${code}, signal=${signal})\n`);
      void shutdown(1);
    }
  });
  return child;
}

async function run(name, command, args) {
  const child = spawn(command, args, { cwd: root, env: commonEnv, stdio: ["ignore", "pipe", "pipe"] });
  forward(name, child.stdout, process.stdout);
  forward(name, child.stderr, process.stderr);
  const code = await new Promise((resolve) => child.once("exit", resolve));
  if (code !== 0) throw new Error(`${name} failed with exit code ${code}`);
}

async function waitFor(url, name, timeoutMs = 30_000) {
  const deadline = Date.now() + timeoutMs;
  let lastError = "not ready";
  while (Date.now() < deadline) {
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(1_500) });
      if (response.ok) return;
      lastError = `HTTP ${response.status}`;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
    }
    await new Promise((resolve) => setTimeout(resolve, 150));
  }
  throw new Error(`${name} did not become ready at ${url}: ${lastError}`);
}

async function shutdown(exitCode = 0) {
  if (stopping) return;
  stopping = true;
  for (const { child } of [...services].reverse()) {
    if (child.pid === undefined || child.exitCode !== null) continue;
    try { child.kill("SIGTERM"); } catch { /* already stopped */ }
  }
  await new Promise((resolve) => setTimeout(resolve, 500));
  for (const { child } of services) {
    if (child.pid === undefined || child.exitCode !== null) continue;
    try { child.kill("SIGKILL"); } catch { /* already stopped */ }
  }
  process.exit(exitCode);
}

process.once("SIGINT", () => void shutdown(130));
process.once("SIGTERM", () => void shutdown(0));

try {
  await rm(dataPath, { recursive: true, force: true });
  await mkdir(dataPath, { recursive: true });

  start("surreal", path.join(root, "tools/surreal"), [
    "start", "--no-banner", "--log", "error", "--bind", `127.0.0.1:${surrealPort}`,
    "--user", "root", "--pass", "root", `surrealkv:${dataPath}`,
  ]);
  await waitFor(`http://127.0.0.1:${surrealPort}/health`, "SurrealDB");
  await run("migrations", path.join(root, "backend/bin/cake"), ["migrations", "migrate"]);
  await run("stations", path.join(root, "backend/bin/cake"), ["stations", "import", "--limit", "100"]);

  start("realtime", path.join(root, "scripts/realtime.sh"), [
    "--host", "127.0.0.1", "--port", String(realtimePort),
  ]);
  await waitFor(`http://127.0.0.1:${realtimePort}/health/realtime`, "realtime service");

  start("http", path.join(root, "tools/frankenphp"), [
    "run", "--config", "infra/Caddyfile", "--adapter", "caddyfile",
  ]);
  await waitFor(`http://127.0.0.1:${httpPort}/api/health`, "CakePHP HTTP service");

  start("vite", "npm", [
    "exec", "vite", "--", "--config", "vite.live.config.ts", "--host", "127.0.0.1", "--port", String(frontendPort),
  ], {
    cwd: path.join(root, "frontend"),
    env: {
      VITE_DATA_MODE: "api",
      VITE_ENABLE_FIXTURES: "false",
      VITE_API_BASE: "/api",
      VITE_REALTIME_PATH: "/live",
      VITE_FALLBACK_POLL_MS: "1000",
      VITE_MAP_STYLE_MODE: "local",
      FJORDPULSE_LIVE_HTTP_ORIGIN: `http://127.0.0.1:${httpPort}`,
      FJORDPULSE_LIVE_REALTIME_ORIGIN: `ws://127.0.0.1:${realtimePort}`,
    },
  });
  await waitFor(`${frontendOrigin}/api/health`, "Vite API proxy");
  process.stdout.write(`[live:stack] ready at ${frontendOrigin}\n`);
  await new Promise(() => {});
} catch (error) {
  process.stderr.write(`[live:stack] ${error instanceof Error ? error.stack : String(error)}\n`);
  await shutdown(1);
}
