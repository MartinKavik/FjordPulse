#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

required=(
  AGENTS.md GOAL.md FINAL_READINESS_REVIEW.md README.md
  docs/ARCHITECTURE.md docs/SURREALDB_LIVE_QUERY_FLOW.md docs/DEPENDENCY_SPIKES.md
  docs/design/00_README.md docs/user-stories/00_README.md
  contracts/http/openapi.yaml contracts/realtime/envelope.schema.json
)

for file in "${required[@]}"; do
  [[ -f "$file" ]] || { echo "Missing required file: $file" >&2; exit 1; }
done

design_png_count="$(find docs/design -maxdepth 1 -type f -name '*.png' | wc -l | tr -d ' ')"
design_md_count="$(find docs/design -maxdepth 1 -type f -name '[0-9][0-9]_*.md' ! -name '00_*' | wc -l | tr -d ' ')"
story_count="$(python3 - <<'PY2'
import json
from pathlib import Path
p=Path('docs/user-stories/00_manifest.json')
data=json.loads(p.read_text())
print(len(data.get('stories', [])))
PY2
)"
scenario_count="$(python3 - <<'PY2'
import json
from pathlib import Path
p=Path('docs/user-stories/00_manifest.json')
data=json.loads(p.read_text())
print(sum(story.get('test_scenario_count', 0) for story in data.get('stories', [])))
PY2
)"
nested_zip_count="$(find . \
  \( -path './.git' -o -path './node_modules' -o -path './frontend/node_modules' -o -path './backend/vendor' -o -path './.tools' -o -path './.data' -o -path './test-results' -o -path './playwright-report' \) -prune \
  -o -type f -name '*.zip' -print | wc -l | tr -d ' ')"

echo "Design PNG count: $design_png_count (expected 25)"
echo "Design note count: $design_md_count (expected 26)"
echo "Story count: $story_count (expected 108)"
echo "Black-box scenario count: $scenario_count (expected 340)"
echo "Nested ZIP count: $nested_zip_count (expected 0)"

[[ "$design_png_count" == "25" ]]
[[ "$design_md_count" == "26" ]]
[[ "$story_count" == "108" ]]
[[ "$scenario_count" == "340" ]]
[[ "$nested_zip_count" == "0" ]]

echo "Planning corpus verification PASSED."
