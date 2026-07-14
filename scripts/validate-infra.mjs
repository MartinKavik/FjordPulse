import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import YAML from "yaml";

const REQUIRED_SERVICES = ["surrealdb", "migrate", "stations", "realtime", "app", "maintenance"];

async function readCompose(path) {
  const text = await readFile(new URL(path, import.meta.url), "utf8");
  const compose = YAML.parse(text);
  assert.equal(typeof compose, "object", `${path} must parse as an object`);
  assert.equal(typeof compose.services, "object", `${path} must define services`);
  for (const service of REQUIRED_SERVICES) {
    assert.ok(compose.services[service], `${path} is missing service ${service}`);
  }
  return compose;
}

function assertSharedTopology(compose, label) {
  const { services } = compose;
  assert.equal(services.surrealdb.image, "surrealdb/surrealdb:v3.2.0", `${label} must pin SurrealDB 3.2.0`);
  assert.equal(services.realtime.deploy.replicas, 1, `${label} must run exactly one realtime replica`);
  assert.deepEqual(
    Object.entries(services)
      .filter(([, service]) => Array.isArray(service.command) && service.command[0] === "scripts/realtime.sh")
      .map(([serviceName]) => serviceName),
    ["realtime"],
    `${label} must define exactly one realtime process`,
  );
  assert.equal(services.migrate.restart, "no", `${label} migrations must be one-shot`);
  assert.equal(services.stations.restart, "no", `${label} station import must be one-shot`);
  assert.deepEqual(
    services.stations.command,
    ["backend/bin/cake", "stations", "import"],
    `${label} must import the complete production catalog`,
  );
  assert.equal(services.migrate.depends_on.surrealdb.condition, "service_healthy");
  assert.equal(services.stations.depends_on.migrate.condition, "service_completed_successfully");
  assert.equal(services.realtime.depends_on.stations.condition, "service_completed_successfully");
  assert.equal(services.app.depends_on.stations.condition, "service_completed_successfully");
  assert.equal(services.app.depends_on.realtime.condition, "service_healthy");
  assert.equal(
    services.app.environment.MAPTILER_API_KEY,
    "${MAPTILER_API_KEY:?Set MAPTILER_API_KEY}",
    `${label} public app must receive the operator-managed MapTiler browser key`,
  );
  assert.equal(
    services.realtime.environment.MAPTILER_API_KEY,
    undefined,
    `${label} realtime worker must not receive the browser map key`,
  );
}

function assertNoComposeDomain(service, label) {
  assert.equal(service.domain, undefined, `${label} must not define a domain`);
  assert.equal(service.domains, undefined, `${label} must not define domains`);
  assert.equal(service.fqdn, undefined, `${label} must not define an FQDN`);
  assert.equal(service.labels, undefined, `${label} must not define static proxy labels`);
  const environmentKeys = Object.keys(service.environment ?? {});
  assert.ok(
    environmentKeys.every((key) => !/^(?:SERVICE_(?:FQDN|URL)_|COOLIFY_FQDN|VIRTUAL_HOST)/i.test(key)),
    `${label} must not use a Compose-defined proxy domain`,
  );
}

const genericCompose = await readCompose("../infra/compose.yaml");
assertSharedTopology(genericCompose, "Generic Compose");
const genericServices = genericCompose.services;
assert.equal(genericServices.surrealdb.ports, undefined, "Generic SurrealDB must not publish a host port");
assert.equal(genericServices.realtime.ports, undefined, "Generic realtime must be reachable only through /live");
assert.ok(genericServices.realtime.networks.includes("public"), "Generic realtime needs outbound Entur access");
assert.ok(genericServices.stations.networks.includes("public"), "Generic station import needs outbound Entur access");
assert.equal(genericCompose.networks.private.internal, true, "Generic private service network must be internal");

const coolifyCompose = await readCompose("../infra/compose.coolify.yaml");
assertSharedTopology(coolifyCompose, "Coolify Compose");
const coolifyServices = coolifyCompose.services;
assert.ok(coolifyServices.backup, "Coolify Compose must include the off-host backup service");
assert.equal(coolifyCompose.name, undefined, "Coolify Compose must not fix the project name");
assert.equal(coolifyCompose.networks, undefined, "Coolify Compose must let Coolify own its deployment network");
for (const [serviceName, service] of Object.entries(coolifyServices)) {
  assert.equal(service.networks, undefined, `Coolify service ${serviceName} must not define custom networks`);
  assert.equal(service.network_mode, undefined, `Coolify service ${serviceName} must not override Coolify networking`);
  assertNoComposeDomain(service, `Coolify service ${serviceName}`);
}
assert.deepEqual(coolifyServices.app.expose, ["8080"], "Coolify must route only to app container port 8080");
assert.equal(coolifyServices.app.ports, undefined, "Coolify app must not publish a host port");
assert.equal(coolifyServices.realtime.ports, undefined, "Coolify realtime must not publish a host port");
assert.equal(coolifyServices.realtime.expose, undefined, "Coolify realtime must not be assigned a proxy port");
assert.deepEqual(
  coolifyServices.surrealdb.ports,
  ["127.0.0.1:18000:8000"],
  "Coolify SurrealDB may bind only its loopback operator tunnel port",
);
assert.equal(coolifyServices.surrealdb.expose, undefined, "Coolify SurrealDB must not be assigned a proxy port");
for (const [serviceName, service] of Object.entries(coolifyServices)) {
  if (serviceName !== "surrealdb") {
    assert.equal(service.ports, undefined, `Coolify service ${serviceName} must not publish host ports`);
  }
}
assert.equal(coolifyServices.surrealdb.working_dir, "/data", "RocksDB must resolve inside the persistent volume");
assert.equal(
  coolifyServices.surrealdb.command.at(-1),
  "rocksdb://fjordpulse",
  "Coolify production must use the pinned server's RocksDB URI",
);
assert.ok(
  !coolifyServices.surrealdb.command.some((part) => String(part).includes("surrealkv")),
  "Coolify production must not use beta SurrealKV storage",
);
assert.deepEqual(coolifyServices.surrealdb.volumes, ["surreal-data:/data"]);
assert.deepEqual(
  coolifyCompose.volumes["surreal-data"],
  { name: "fjordpulse-production-surreal-data" },
  "Coolify SurrealDB volume must have a deployment-stable name",
);
assert.match(
  coolifyServices.app.healthcheck.test.join(" "),
  /\/api\/readiness/,
  "Coolify app readiness must gate deployment acceptance",
);
for (const serviceName of ["migrate", "stations", "maintenance"]) {
  assert.equal(
    coolifyServices[serviceName].exclude_from_hc,
    true,
    `${serviceName} must be excluded from aggregate health`,
  );
  assert.equal(coolifyServices[serviceName].restart, "no", `${serviceName} must remain a one-shot/tool service`);
}
assert.equal(coolifyServices.backup.exclude_from_hc, true, "backup must not block application health");
assert.equal(coolifyServices.backup.restart, "unless-stopped", "backup tools must remain available to scheduled jobs");
assert.equal(coolifyServices.backup.build.dockerfile, "infra/Dockerfile.backup");
assert.equal(coolifyServices.backup.depends_on.surrealdb.condition, "service_healthy");
assert.deepEqual(coolifyServices.backup.volumes, ["backup-work:/work"]);
assert.deepEqual(
  coolifyCompose.volumes["backup-work"],
  { name: "fjordpulse-production-backup-work" },
  "Coolify backup work volume must have a deployment-stable name",
);
for (const variable of [
  "SURREAL_ROOT_PASSWORD",
  "RESTIC_REPOSITORY",
  "RESTIC_PASSWORD",
  "AWS_ACCESS_KEY_ID",
  "AWS_SECRET_ACCESS_KEY",
]) {
  assert.match(
    coolifyServices.backup.environment[variable],
    /^\$\{[A-Z0-9_]+:\?Set [A-Z0-9_]+\}$/,
    `backup ${variable} must fail closed when missing`,
  );
}
assert.equal(
  coolifyServices.migrate.environment.SURREAL_OPERATOR_PASSWORD,
  "${SURREAL_OPERATOR_PASSWORD:?Set SURREAL_OPERATOR_PASSWORD}",
  "production migration must require the read-only operator password",
);
assert.equal(
  coolifyCompose["x-backend-environment"].TRUSTED_PROXIES,
  "${TRUSTED_PROXIES:?Set TRUSTED_PROXIES to the Coolify proxy network CIDR}",
  "production must explicitly identify its trusted reverse proxy network",
);
for (const variable of ["APP_VERSION", "APP_ORIGIN", "ALLOWED_ORIGINS"]) {
  assert.equal(
    coolifyCompose["x-backend-environment"][variable],
    `\${${variable}:?Set ${variable}}`,
    `production ${variable} must fail closed when missing`,
  );
}
assert.equal(
  coolifyCompose["x-backend-environment"].ADMIN_DEMO_ACCESS,
  "${ADMIN_DEMO_ACCESS:-true}",
  "the public read-only Admin demo must default on for the production demo",
);

const dockerfile = await readFile(new URL("../infra/Dockerfile", import.meta.url), "utf8");
assert.match(dockerfile, /FROM node:22\.22\.0-bookworm-slim AS frontend-build/);
assert.match(dockerfile, /FROM dunglas\/frankenphp:1\.12\.4-php8\.5\.8-bookworm AS runtime/);
assert.match(dockerfile, /--classmap-authoritative/);

const caddyfile = await readFile(new URL("../infra/Caddyfile", import.meta.url), "utf8");
assert.match(caddyfile, /^\s*bind \{\$HTTP_HOST:127\.0\.0\.1\}\s*$/m, "Caddy must bind the configured HTTP interface explicitly");
assert.match(caddyfile, /reverse_proxy \{\$REALTIME_UPSTREAM:/);
assert.match(caddyfile, /php_server/);
assert.match(caddyfile, /https:\/\/api\.maptiler\.com/);
assert.doesNotMatch(caddyfile, /^\s*worker\b/im, "Base deployment must use FrankenPHP normal mode");

console.log("Generic and Coolify infrastructure topology validation passed.");
