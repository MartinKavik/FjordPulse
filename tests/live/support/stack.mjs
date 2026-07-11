import { spawn } from "node:child_process";
import { rm, mkdir } from "node:fs/promises";
import { createServer } from "node:http";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../..");
const frontendPort = Number(process.env.FJORDPULSE_LIVE_FRONTEND_PORT ?? "19073");
const httpPort = Number(process.env.FJORDPULSE_LIVE_HTTP_PORT ?? "19080");
const realtimePort = Number(process.env.FJORDPULSE_LIVE_REALTIME_PORT ?? "19081");
const controlPort = Number(process.env.FJORDPULSE_LIVE_CONTROL_PORT ?? "19082");
const surrealPort = Number(process.env.FJORDPULSE_LIVE_SURREAL_PORT ?? "19000");
const dataPath = path.join(root, ".data/playwright-live");
const tempPath = path.join(dataPath, "tmp");
const frontendOrigin = `http://127.0.0.1:${frontendPort}`;
const commonEnv = {
  ...process.env,
  APP_ENV: "test",
  APP_DEBUG: "true",
  APP_VERSION: "playwright-live",
  TMPDIR: tempPath,
  APP_ORIGIN: frontendOrigin,
  ALLOWED_ORIGINS: frontendOrigin,
  DATA_MODE: "fake",
  SCENARIO: "normal",
  MAPTILER_API_KEY: "playwright-map-key",
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

/** @typedef {{name: string, child: import('node:child_process').ChildProcess, expectedExit: boolean}} Service */

/** @type {Service[]} */
const services = [];
/** @type {Service|null} */
let realtimeService = null;
/** @type {import('node:http').Server|null} */
let controlServer = null;
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
  const service = { name, child, expectedExit: false };
  services.push(service);
  child.once("exit", (code, signal) => {
    if (!stopping && !service.expectedExit) {
      process.stderr.write(`[live:${name}] stopped unexpectedly (code=${code}, signal=${signal})\n`);
      void shutdown(1);
    }
  });
  return service;
}

function realtimeRunning() {
  return realtimeService !== null
    && realtimeService.child.exitCode === null
    && realtimeService.child.signalCode === null;
}

async function stopService(service) {
  if (service.child.exitCode !== null || service.child.signalCode !== null) return;
  service.expectedExit = true;
  const exited = new Promise((resolve) => service.child.once("exit", resolve));
  service.child.kill("SIGTERM");
  const graceful = await Promise.race([
    exited.then(() => true),
    new Promise((resolve) => setTimeout(() => resolve(false), 5_000)),
  ]);
  if (!graceful && service.child.exitCode === null && service.child.signalCode === null) {
    service.child.kill("SIGKILL");
    await exited;
  }
}

async function startRealtime() {
  if (realtimeRunning()) return;
  realtimeService = start("realtime", path.join(root, "scripts/realtime.sh"), [
    "--host", "127.0.0.1", "--port", String(realtimePort),
  ]);
  await waitForJson(
    `http://127.0.0.1:${realtimePort}/health/realtime`,
    "healthy realtime service",
    (body) => body?.status === "healthy" && body?.bridge?.state === "healthy",
  );
}

function respondJson(response, status, body) {
  response.writeHead(status, {
    "Cache-Control": "no-store",
    "Content-Type": "application/json; charset=utf-8",
  });
  response.end(JSON.stringify(body));
}

function startControlServer() {
  controlServer = createServer(async (request, response) => {
    try {
      if (request.method === "GET" && request.url === "/health") {
        respondJson(response, 200, { ok: true, realtimeRunning: realtimeRunning() });
        return;
      }
      if (request.method === "POST" && request.url === "/realtime/stop") {
        if (realtimeService !== null) await stopService(realtimeService);
        realtimeService = null;
        respondJson(response, 200, { ok: true, realtimeRunning: false });
        return;
      }
      if (request.method === "POST" && request.url === "/realtime/start") {
        await startRealtime();
        respondJson(response, 200, { ok: true, realtimeRunning: true });
        return;
      }
      respondJson(response, 404, { ok: false, error: "not_found" });
    } catch (error) {
      respondJson(response, 500, {
        ok: false,
        error: error instanceof Error ? error.message : String(error),
      });
    }
  });
  controlServer.listen(controlPort, "127.0.0.1");
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

async function waitForJson(url, name, predicate, timeoutMs = 45_000) {
  const deadline = Date.now() + timeoutMs;
  let lastError = "not ready";
  while (Date.now() < deadline) {
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(1_500) });
      const contentType = response.headers.get("content-type") ?? "";
      if (!contentType.toLowerCase().startsWith("application/json")) {
        lastError = `unexpected Content-Type ${contentType || "(missing)"}`;
      } else {
        const body = await response.json();
        if (response.ok && predicate(body)) return;
        lastError = `HTTP ${response.status}: ${JSON.stringify(body)}`;
      }
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
  controlServer?.close();
  for (const service of [...services].reverse()) {
    const { child } = service;
    if (child.pid === undefined || child.exitCode !== null) continue;
    service.expectedExit = true;
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
  startControlServer();
  await rm(dataPath, { recursive: true, force: true });
  await mkdir(dataPath, { recursive: true });
  await mkdir(tempPath, { recursive: true });

  start("surreal", path.join(root, "tools/surreal"), [
    "start", "--no-banner", "--log", "error", "--bind", `127.0.0.1:${surrealPort}`,
    "--user", "root", "--pass", "root", `surrealkv:${dataPath}`,
  ]);
  await waitFor(`http://127.0.0.1:${surrealPort}/health`, "SurrealDB");
  await run("migrations", path.join(root, "backend/bin/cake"), ["migrations", "migrate"]);
  await run("stations", path.join(root, "backend/bin/cake"), ["stations", "import", "--limit", "100"]);

  await startRealtime();

  start("http", path.join(root, "tools/frankenphp"), [
    "run", "--config", "infra/Caddyfile", "--adapter", "caddyfile",
  ]);
  await waitForJson(
    `http://127.0.0.1:${httpPort}/api/health`,
    "healthy CakePHP application",
    (body) => body?.ok === true
      && body?.data?.status === "healthy"
      && body?.data?.dependencies?.http?.status === "healthy"
      && body?.data?.dependencies?.realtime?.status === "healthy"
      && body?.data?.dependencies?.surrealdb?.status === "healthy"
      && body?.data?.dependencies?.liveQueryBridge?.status === "healthy",
  );

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
