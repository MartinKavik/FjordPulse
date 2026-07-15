import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const screenshotDirectory = resolve(repositoryRoot, "docs/screenshots");
const expectedFiles = new Map([
  ["production-focus-line-1-alesund.png", { width: 1_440, height: 900 }],
  ["production-forde-station.png", { width: 1_440, height: 900 }],
  ["production-admin-status.png", { width: 1_440, height: 900 }],
  ["production-admin-realtime.png", { width: 1_440, height: 900 }],
  ["production-admin-infrastructure.png", { width: 1_440, height: 900 }],
  ["production-mobile-map.png", { width: 390, height: 844 }],
]);

const manifest = JSON.parse(await readFile(resolve(screenshotDirectory, "capture.json"), "utf8"));
assert.equal(manifest.schemaVersion, 1, "unsupported production screenshot manifest schema");
assert.equal(manifest.source, "https://fjordpulse.kavik.cz", "screenshots must come from the production origin");
assert.match(manifest.buildVersion, /^[0-9a-f]{40}$/, "screenshot build must be an exact commit SHA");
assert.match(manifest.focusedVehicleId, /^MOR:Vehicle:/, "hero capture must record its Møre og Romsdal vehicle");
assert.ok(Number.isFinite(Date.parse(manifest.capturedAt)), "screenshot capture time must be RFC3339-compatible");
const captureDate = new Date(manifest.capturedAt);
const captureDateLabel = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "long",
  year: "numeric",
  timeZone: "Europe/Oslo",
}).format(captureDate);
assert.deepEqual(
  Object.keys(manifest.files).sort(),
  [...expectedFiles.keys()].sort(),
  "manifest must cover exactly the published production gallery",
);

for (const [filename, expected] of expectedFiles) {
  const contents = await readFile(resolve(screenshotDirectory, filename));
  assert.equal(contents.subarray(0, 8).toString("hex"), "89504e470d0a1a0a", `${filename} must be a PNG`);
  const actual = {
    width: contents.readUInt32BE(16),
    height: contents.readUInt32BE(20),
    sha256: createHash("sha256").update(contents).digest("hex"),
  };
  assert.deepEqual(actual, { ...expected, sha256: manifest.files[filename].sha256 }, `${filename} does not match its manifest`);
  assert.deepEqual(manifest.files[filename], actual, `${filename} metadata is inconsistent`);
}

const provenance = await readFile(resolve(screenshotDirectory, "README.md"), "utf8");
const readme = await readFile(resolve(repositoryRoot, "README.md"), "utf8");
for (const [filename, expected] of expectedFiles) {
  const row = provenance.split("\n").find((line) => line.includes(`\`${filename}\``));
  assert.ok(row, `${filename} is missing capture provenance`);
  assert.ok(row.includes(manifest.buildVersion), `${filename} provenance has the wrong build`);
  assert.ok(row.includes(captureDateLabel), `${filename} provenance has the wrong date`);
  assert.ok(row.includes(`${expected.width}×${expected.height}`), `${filename} provenance has the wrong viewport`);
  assert.ok(readme.includes(`docs/screenshots/${filename}`), `${filename} is missing from the public README gallery`);
}
assert.ok(readme.includes(manifest.buildVersion), "public README has the wrong screenshot build");
assert.ok(readme.includes(captureDateLabel), "public README has the wrong screenshot date");

console.log(`Production screenshot evidence verified: ${expectedFiles.size} PNGs from ${manifest.buildVersion}.`);
