import type { SearchResult } from "../types/domain";

export function normalizeSearchText(value: string): string {
  return value
    .toLocaleLowerCase("nb-NO")
    .replaceAll("ø", "o")
    .replaceAll("æ", "ae")
    .replaceAll("å", "a")
    .normalize("NFKD")
    .replace(/\p{Mark}+/gu, "")
    .replace(/[^a-z0-9]+/g, " ")
    .trim()
    .replace(/\s+/g, " ");
}

// Fixture-mode parity only. Production search candidates are selected and
// typo-validated by SurrealDB before they reach the browser.
function damerauLevenshtein(left: string, right: string): number {
  const rows = left.length + 1;
  const columns = right.length + 1;
  const matrix = Array.from({ length: rows }, (_, row) => Array.from({ length: columns }, (_, column) => row === 0 ? column : column === 0 ? row : 0));
  for (let row = 1; row < rows; row += 1) {
    for (let column = 1; column < columns; column += 1) {
      const substitution = matrix[row - 1]![column - 1]! + (left[row - 1] === right[column - 1] ? 0 : 1);
      matrix[row]![column] = Math.min(matrix[row - 1]![column]! + 1, matrix[row]![column - 1]! + 1, substitution);
      if (row > 1 && column > 1 && left[row - 1] === right[column - 2] && left[row - 2] === right[column - 1]) {
        matrix[row]![column] = Math.min(matrix[row]![column]!, matrix[row - 2]![column - 2]! + 1);
      }
    }
  }
  return matrix[left.length]![right.length]!;
}

function directScore(result: SearchResult, query: string): number | null {
  const label = normalizeSearchText(result.label);
  const secondary = normalizeSearchText(result.secondaryText ?? "");
  const line = normalizeSearchText(result.lineCode ?? "");
  if (label === query || line === query) return 500;
  if (label.startsWith(query)) return 400;
  if (label.split(" ").some((token) => token.startsWith(query))) return 350;
  if (secondary.split(" ").some((token) => token.startsWith(query))) return 300;
  if (label.includes(query) || secondary.includes(query)) return 200;
  return null;
}

export function rankFixtureSearch(results: readonly SearchResult[], rawQuery: string): readonly SearchResult[] {
  const query = normalizeSearchText(rawQuery);
  if (query.length === 0) return [];
  const direct = results.flatMap((result) => {
    const score = directScore(result, query);
    return score === null ? [] : [{ result, score }];
  });
  const candidates = direct.length >= 5 || query.includes(" ") || query.length < 4
    ? direct
    : [
        ...direct,
        ...results.flatMap((result) => {
          if (direct.some((candidate) => candidate.result.id === result.id && candidate.result.type === result.type)) return [];
          const threshold = 1;
          const distance = Math.min(...normalizeSearchText(result.label).split(" ").map((token) => damerauLevenshtein(token, query)));
          return distance <= threshold ? [{ result, score: 100 - distance }] : [];
        }),
      ];
  const typeOrder = { station: 0, place: 1, line: 2, vehicle: 3 } as const;
  return candidates
    .sort((left, right) => right.score - left.score || typeOrder[left.result.type] - typeOrder[right.result.type] || left.result.label.localeCompare(right.result.label, "nb-NO"))
    .map(({ result }) => result);
}
