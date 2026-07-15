import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import YAML from "yaml";

const source = await readFile(new URL("../.github/workflows/deploy-production.yml", import.meta.url), "utf8");
const workflow = YAML.parse(source);
const deploy = workflow?.jobs?.deploy;
const qualitySource = await readFile(new URL("../.github/workflows/quality.yml", import.meta.url), "utf8");
const qualityWorkflow = YAML.parse(qualitySource);
const productionImages = qualityWorkflow?.jobs?.["production-images"];

const node24ActionMajors = new Map([
  ["actions/checkout", "v6"],
  ["actions/setup-node", "v6"],
  ["actions/upload-artifact", "v7"],
]);

function workflowUsesReferences(parsedWorkflow) {
  const jobs = Object.values(parsedWorkflow?.jobs ?? {});
  return jobs.flatMap((job) => [
    ...(typeof job?.uses === "string" ? [job.uses] : []),
    ...(Array.isArray(job?.steps)
      ? job.steps.flatMap((step) => (typeof step?.uses === "string" ? [step.uses] : []))
      : []),
  ]);
}

function assertNode24ActionRuntimes(name, parsedWorkflow) {
  const actionReferences = workflowUsesReferences(parsedWorkflow);

  assert.ok(actionReferences.length > 0, `${name} must contain at least one action reference`);
  for (const reference of actionReferences) {
    const separator = reference.lastIndexOf("@");
    assert.notEqual(separator, -1, `${name} action ${reference} must pin a version`);
    const action = reference.slice(0, separator);
    const version = reference.slice(separator + 1);
    const requiredVersion = node24ActionMajors.get(action);
    assert.ok(requiredVersion, `${name} action ${action} must be added to the reviewed Node 24 action allowlist`);
    assert.equal(
      version,
      requiredVersion,
      `${name} action ${action} must use the reviewed Node 24 runtime major ${requiredVersion}`,
    );
  }
}

assert.deepEqual(
  workflowUsesReferences({
    jobs: {
      reusable: { uses: "fjordpulse/example/.github/workflows/reusable.yml@v1" },
      steps: { steps: [{ uses: "actions/checkout@v6" }] },
    },
  }),
  ["fjordpulse/example/.github/workflows/reusable.yml@v1", "actions/checkout@v6"],
  "workflow validation must inspect job-level reusable workflows and step-level actions",
);

assertNode24ActionRuntimes("deploy-production", workflow);
assertNode24ActionRuntimes("quality", qualityWorkflow);

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
assert.match(combined, /applications\/\$\{COOLIFY_APPLICATION_UUID\}\/envs\/bulk/);
assert.match(combined, /key: "APP_VERSION", value: \$commit/);
assert.match(combined, /is_runtime: true, is_buildtime: true/);
assert.match(combined, /\/deployments\/\$\{deployment_uuid\}/);
assert.match(combined, /\[\[ "\$commit" == "\$TESTED_SHA" \]\]/);
assert.match(combined, /\.data\.version == \$commit/);

for (const step of scripts) {
  const result = spawnSync("bash", ["-n"], { input: step.run, encoding: "utf8" });
  assert.equal(result.status, 0, `${step.name} must be valid Bash: ${result.stderr}`);
}

assert.equal(productionImages?.runs_on ?? productionImages?.["runs-on"], "ubuntu-24.04");
assert.equal(productionImages?.timeout_minutes ?? productionImages?.["timeout-minutes"], 30);
assert.ok(Array.isArray(productionImages?.steps));
const productionImageScripts = productionImages.steps.filter((step) => typeof step.run === "string");
const productionImageCommands = productionImageScripts.map((step) => step.run).join("\n");
assert.match(productionImageCommands, /docker build --pull --file infra\/Dockerfile --tag fjordpulse-app:ci \./);
assert.match(productionImageCommands, /docker build --pull --file infra\/Dockerfile\.backup --tag fjordpulse-backup:ci \./);
assert.match(productionImageCommands, /--network none --entrypoint \/app\/backend\/bin\/cake/);
assert.match(productionImageCommands, /class_exists\("FjordPulse\\\\Application"\)/);
assert.match(productionImageCommands, /--network none --entrypoint \/usr\/local\/bin\/surreal/);
assert.match(productionImageCommands, /--network none --entrypoint \/usr\/local\/bin\/restic/);
for (const step of productionImageScripts) {
  const result = spawnSync("bash", ["-n"], { input: step.run, encoding: "utf8" });
  assert.equal(result.status, 0, `${step.name} must be valid Bash: ${result.stderr}`);
}

console.log("Node 24 actions, immutable-release deployment, and production-image workflow validation passed.");
