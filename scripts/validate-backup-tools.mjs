import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFile } from "node:fs/promises";

const root = new URL("../", import.meta.url);
const dockerfile = await readFile(new URL("infra/Dockerfile.backup", root), "utf8");
const backup = await readFile(new URL("infra/scripts/backup-surrealdb.sh", root), "utf8");
const restore = await readFile(new URL("infra/scripts/restore-surrealdb.sh", root), "utf8");
const resticWrapper = await readFile(new URL("tools/restic", root), "utf8");
const smoke = await readFile(new URL("scripts/test-backup-restore.sh", root), "utf8");

assert.match(dockerfile, /^FROM debian:bookworm-20260623-slim$/m);
assert.match(dockerfile, /libstdc\+\+6/, "the pinned SurrealDB CLI is a glibc binary linked to libstdc++");
assert.doesNotMatch(dockerfile, /^FROM alpine:/m, "the official SurrealDB CLI binary does not run on musl-only Alpine");
assert.match(dockerfile, /SURREAL_VERSION=3\.2\.0/);
assert.match(dockerfile, /RESTIC_VERSION=0\.19\.1/);
assert.match(dockerfile, /SURREAL_SHA256=9c0a9ae29444f3b144a1261fc923116b0e10a3cbadc478cabc9009b3beb9bb3a/);
assert.match(dockerfile, /RESTIC_SHA256=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c/);
assert.match(resticWrapper, /VERSION='0\.19\.1'/);
assert.match(resticWrapper, /SHA256='f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c'/);
assert.match(resticWrapper, /BINARY_SHA256='20d4142678d0d95ec11a4759def1b73fd9190abc9ca19e4b62d067c0b387e639'/);
assert.match(resticWrapper, /bunzip2 --stdout "\$\{ARCHIVE\}" >"\$\{binary_temporary\}"/);
assert.doesNotMatch(
  resticWrapper,
  /cp "\$\{ARCHIVE\}" "\$\{BINARY\}\.bz2"/,
  "the cached archive and the old decompression path resolve to the same file",
);

for (const [name, source] of [["backup", backup], ["restore", restore]]) {
  assert.match(source, /^set -euo pipefail$/m, `${name} must fail closed`);
  assert.match(source, /^unset TERM$/m, `${name} must keep machine-readable output non-interactive`);
  assert.match(source, /flock --nonblock/, `${name} must prevent overlapping runs`);
  assert.doesNotMatch(source, /--pass(?:word)?[= ]/, `${name} must not put a database password on the command line`);
}

assert.match(backup, /\.fjordpulse-maintenance\.lock/);
assert.match(restore, /\.fjordpulse-maintenance\.lock/);
assert.doesNotMatch(backup, /\.fjordpulse-backup\.lock/);
assert.doesNotMatch(restore, /\.fjordpulse-restore\.lock/);

assert.match(backup, /sha256sum/);
assert.match(backup, /export \\\n\s+--log none/);
assert.match(backup, /--tag "\$BACKUP_KIND"/);
assert.match(backup, /--keep-daily "\$KEEP_DAILY"/);
assert.match(backup, /--keep-weekly "\$KEEP_WEEKLY"/);
assert.match(backup, /if \[\[ "\$BACKUP_KIND" == 'scheduled' \]\]/, "retention must not delete protected pre-release snapshots");
assert.match(restore, /RESTORE_CONFIRM_ISOLATED/);
assert.match(restore, /RESTORE_HTTP_URL/);
assert.match(restore, /RESTORE_ROOT_USERNAME/);
assert.match(restore, /RESTORE_ROOT_PASSWORD/);
assert.match(restore, /Refusing to restore to the configured source\/production endpoint/);
assert.match(restore, /Restore root username must differ from the source\/production root username/);
assert.match(restore, /Restore root password must differ from the source\/production root password/);
assert.match(restore, /Refusing to restore into the configured production namespace\/database/);
assert.match(restore, /INFO FOR DB/);
assert.match(restore, /Restore target database is not empty/);
assert.match(restore, /sha256sum --check/);
assert.match(restore, /import \\\n\s+--log none/);
assert.match(restore, /restore "\$RESTORE_SNAPSHOT" --target "\$work_dir" --verify --json/);
assert.match(restore, /\/api\/readiness/);
assert.match(restore, /--hide-welcome/);
assert.match(restore, /Restored database verification query returned invalid evidence/);
assert.doesNotMatch(restore, /--query/, "SurrealDB 3.2.0 sql reads queries from stdin");
assert.match(restore, /printf '%s\\n'[\s\S]+\| "\$SURREAL_BIN" sql/);

assert.match(smoke, /RESTORE_CONFIRM_ISOLATED=true/);
assert.match(smoke, /unset TERM/, "scripted SurrealDB JSON must not inherit a pseudo-terminal mode");
assert.match(smoke, /SOURCE_ENDPOINT/);
assert.match(smoke, /RESTORE_ENDPOINT/);
assert.match(smoke, /RESTORE_ROOT_USERNAME/);
assert.match(smoke, /Restore unexpectedly accepted the source endpoint/);
assert.match(smoke, /Restore unexpectedly accepted the source root username through an endpoint alias/);
assert.match(smoke, /Restore unexpectedly accepted the source root password through an endpoint alias/);
assert.match(smoke, /Restore unexpectedly accepted a non-empty target database/);
assert.match(smoke, /Encrypted backup and independent-endpoint restore smoke passed/);

const aliasGuardStart = smoke.indexOf('alias_guard_info=');
const aliasGuardEnd = smoke.indexOf("\nprintf '%s\\n' \"$alias_guard_info\"", aliasGuardStart);
assert.notEqual(aliasGuardStart, -1, "the endpoint-alias guard must be inspected after the rejection checks");
assert.notEqual(aliasGuardEnd, -1, "the endpoint-alias guard inspection must remain parseable");
const aliasGuardInspection = smoke.slice(aliasGuardStart, aliasGuardEnd);
assert.match(
  aliasGuardInspection,
  /--endpoint "\$SOURCE_ENDPOINT"/,
  "inspect alias-guard side effects through the IPv4 endpoint that the smoke server actually binds",
);
assert.doesNotMatch(
  aliasGuardInspection,
  /--endpoint "\$alias_endpoint"/,
  "localhost can resolve to IPv6 on CI while the smoke server intentionally binds IPv4 only",
);

for (const path of [
  "infra/scripts/backup-surrealdb.sh",
  "infra/scripts/restore-surrealdb.sh",
  "scripts/test-backup-restore.sh",
  "tools/restic",
]) {
  execFileSync("bash", ["-n", path], { cwd: new URL("../", import.meta.url), stdio: "inherit" });
}

console.log("Backup and independent-endpoint restore tooling validation passed.");
