import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import YAML from "yaml";

const composeText = await readFile(new URL("../infra/compose.yaml", import.meta.url), "utf8");
const compose = YAML.parse(composeText);
assert.equal(typeof compose, "object", "Compose must parse as an object");

const services = compose.services;
assert.equal(typeof services, "object", "Compose services are required");
for (const service of ["surrealdb", "migrate", "stations", "realtime", "app", "maintenance"]) {
  assert.ok(services[service], `Missing Compose service: ${service}`);
}
assert.equal(services.surrealdb.image, "surrealdb/surrealdb:v3.2.0");
assert.equal(services.surrealdb.ports, undefined, "SurrealDB must not publish a host port");
assert.equal(services.realtime.ports, undefined, "Realtime must be reachable only through /live");
assert.equal(services.realtime.deploy.replicas, 1, "v1 requires exactly one realtime replica");
assert.equal(services.migrate.restart, "no");
assert.equal(services.stations.restart, "no");
assert.equal(services.migrate.depends_on.surrealdb.condition, "service_healthy");
assert.equal(services.stations.depends_on.migrate.condition, "service_completed_successfully");
assert.equal(services.realtime.depends_on.stations.condition, "service_completed_successfully");
assert.equal(services.app.depends_on.realtime.condition, "service_healthy");
assert.equal(compose.networks.private.internal, true, "Private service network must be internal");

const dockerfile = await readFile(new URL("../infra/Dockerfile", import.meta.url), "utf8");
assert.match(dockerfile, /FROM node:22\.22\.0-bookworm-slim AS frontend-build/);
assert.match(dockerfile, /FROM dunglas\/frankenphp:1\.12\.4-php8\.5\.8-bookworm AS runtime/);
assert.match(dockerfile, /--classmap-authoritative/);

const caddyfile = await readFile(new URL("../infra/Caddyfile", import.meta.url), "utf8");
assert.match(caddyfile, /reverse_proxy \{\$REALTIME_UPSTREAM:/);
assert.match(caddyfile, /php_server/);
assert.doesNotMatch(caddyfile, /worker/i, "Base deployment must use FrankenPHP normal mode");

console.log("Infrastructure topology validation passed.");
