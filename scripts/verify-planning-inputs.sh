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
nested_zip_count="$(find . -type f -name '*.zip' | wc -l | tr -d ' ')"

echo "Design PNG count: $design_png_count (expected 23)"
echo "Design note count: $design_md_count (expected 23)"
echo "Story count: $story_count (expected 108)"
echo "Nested ZIP count: $nested_zip_count (expected 0)"

[[ "$design_png_count" == "23" ]]
[[ "$design_md_count" == "23" ]]
[[ "$story_count" == "108" ]]
[[ "$nested_zip_count" == "0" ]]

echo "Planning skeleton verification PASSED."
