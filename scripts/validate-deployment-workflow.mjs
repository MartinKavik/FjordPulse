import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import YAML from "yaml";

const source = await readFile(new URL("../.github/workflows/deploy-production.yml", import.meta.url), "utf8");
const workflow = YAML.parse(source);
const deploy = workflow?.jobs?.deploy;

assert.equal(workflow?.name, "deploy-production");
assert.deepEqual(workflow?.on?.workflow_run?.workflows, ["quality"]);
assert.equal(workflow?.permissions?.contents, "write", "the workflow must be able to create immutable release branches");
assert.equal(workflow?.concurrency?.["cancel-in-progress"], false);
assert.equal(deploy?.timeout_minutes ?? deploy?.["timeout-minutes"], 45);
assert.ok(Array.isArray(deploy?.steps));

const scripts = deploy.steps.filter((step) => typeof step.run === "string");
const combined = scripts.map((step) => step.run).join("\n");
assert.doesNotMatch(source, /COOLIFY_WEBHOOK/);
assert.match(combined, /coolify-release\/\$\{TESTED_SHA\}/);
assert.match(combined, /git push origin "\$\{TESTED_SHA\}:refs\/heads\/\$\{release_branch\}"/);
assert.match(combined, /git_branch: \$branch, git_commit_sha: \$commit, is_auto_deploy_enabled: false/);
assert.match(combined, /BACKUP_KIND=pre_release_\$\{TESTED_SHA\} \/usr\/local\/bin\/backup-surrealdb/);
assert.match(combined, /pre_deployment_command: \$pre_deploy, pre_deployment_command_container: "backup"/);
assert.match(combined, /\/deployments\/\$\{deployment_uuid\}/);
assert.match(combined, /\[\[ "\$commit" == "\$TESTED_SHA" \]\]/);
assert.match(combined, /\.data\.version == \$commit/);

for (const step of scripts) {
  const result = spawnSync("bash", ["-n"], { input: step.run, encoding: "utf8" });
  assert.equal(result.status, 0, `${step.name} must be valid Bash: ${result.stderr}`);
}

console.log("Immutable-release deployment workflow validation passed.");
