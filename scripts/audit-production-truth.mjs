import { readdir, readFile } from "node:fs/promises";
import { extname, join, relative } from "node:path";

const root = new URL("../", import.meta.url).pathname;
const sourceRoot = join(root, "frontend/src");
const distRoot = join(root, "frontend/dist");
const explicitFixtureFiles = new Set([
  "components/FixtureRouter.tsx",
  "components/ScenarioPages.tsx",
]);

async function filesUnder(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const nested = await Promise.all(entries.map((entry) => entry.isDirectory() ? filesUnder(join(directory, entry.name)) : [join(directory, entry.name)]));
  return nested.flat();
}

const failures = [];
for (const file of await filesUnder(sourceRoot)) {
  if (![".ts", ".tsx"].includes(extname(file))) continue;
  const name = relative(sourceRoot, file);
  if (name.startsWith("fixtures/") || explicitFixtureFiles.has(name)) continue;
  const contents = await readFile(file, "utf8");
  if (/from\s+["'][^"']*fixtures\//.test(contents)) failures.push(`${name}: production-reachable fixture import`);
  if (/\b\d+(?:s|m|h|\s+min)\s+ago\b/.test(contents)) failures.push(`${name}: literal relative age`);
}

const sentinels = ["SKY:Vehicle:100-2142", "Førde rutebilstasjon"];
for (const file of await filesUnder(distRoot)) {
  if (![".js", ".css", ".html"].includes(extname(file))) continue;
  const contents = await readFile(file, "utf8");
  for (const sentinel of sentinels) if (contents.includes(sentinel)) failures.push(`${relative(distRoot, file)}: fixture sentinel ${sentinel}`);
}

if (failures.length > 0) {
  process.stderr.write(`Production truth audit failed:\n${failures.map((failure) => `- ${failure}`).join("\n")}\n`);
  process.exit(1);
}

process.stdout.write("Production truth audit passed.\n");
