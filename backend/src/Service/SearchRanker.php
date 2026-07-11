<?php

declare(strict_types=1);

namespace FjordPulse\Service;

use FjordPulse\Dto\SearchCandidate;
use InvalidArgumentException;

final readonly class SearchRanker
{
    public function __construct(private SearchNormalizer $normalizer = new SearchNormalizer())
    {
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $aliases
     */
    public function candidate(string $query, array $result, array $aliases = [], int $entityPriority = 0): SearchCandidate
    {
        $type = $result['type'] ?? null;
        $id = $result['id'] ?? null;
        $label = $result['label'] ?? null;
        if (!is_string($type) || !is_string($id) || !is_string($label)) {
            throw new InvalidArgumentException('Search candidates require string type, id, and label fields.');
        }
        $secondaryText = is_string($result['secondaryText'] ?? null) ? $result['secondaryText'] : '';
        $normalizedQuery = $this->normalizer->normalize($query);
        $normalizedLabel = $this->normalizer->normalize($label);
        $normalizedSecondary = $this->normalizer->normalize($secondaryText);
        $normalizedAliases = array_values(array_filter(array_map(
            $this->normalizer->normalize(...),
            $aliases,
        ), static fn(string $alias): bool => $alias !== ''));

        return new SearchCandidate(
            $result,
            $this->rank($normalizedQuery, $normalizedLabel, $normalizedSecondary, $normalizedAliases),
            match ($type) {
                'station' => 0,
                'place' => 1,
                'line' => 2,
                'vehicle' => 3,
                default => 4,
            },
            max(0, $entityPriority),
            $normalizedLabel,
            $id,
        );
    }

    /**
     * @param list<SearchCandidate> $candidates
     * @return list<array<string, mixed>>
     */
    public function ordered(array $candidates, int $limit, bool $ensureLineCompanions = false): array
    {
        $candidates = array_values(array_filter(
            $candidates,
            static fn(SearchCandidate $candidate): bool => $candidate->rank < 1_000,
        ));
        $compare = static function (SearchCandidate $left, SearchCandidate $right): int {
            return [intdiv($left->rank, 100), $left->typePriority, $left->entityPriority, $left->rank % 100, $left->normalizedLabel, $left->id]
                <=> [intdiv($right->rank, 100), $right->typePriority, $right->entityPriority, $right->rank % 100, $right->normalizedLabel, $right->id];
        };
        usort($candidates, $compare);
        $selected = array_slice($candidates, 0, $limit);

        // A valid one-edit station correction must remain visible even when an
        // upstream geocoder returns many literal place/address matches for the
        // misspelling (for example Frode versus Førde).
        if ($limit >= 2) {
            $fuzzyStation = array_find($candidates, static fn(SearchCandidate $candidate): bool => $candidate->typePriority === 0 && $candidate->rank >= 500);
            if ($fuzzyStation !== null && count(array_filter($selected, static fn(SearchCandidate $candidate): bool => $candidate->id === $fuzzyStation->id)) === 0) {
                if (count($selected) >= $limit) {
                    $replacement = array_find_key(array_reverse($selected, true), static fn(SearchCandidate $candidate): bool => $candidate->typePriority !== 0);
                    if (is_int($replacement)) {
                        array_splice($selected, $replacement, 1);
                    } else {
                        array_pop($selected);
                    }
                }
                $selected[] = $fuzzyStation;
            }
        }
        if ($ensureLineCompanions) {
            $selected = $this->ensureLineCompanions($selected, $candidates, $limit);
        }

        return array_map(
            static fn(SearchCandidate $candidate): array => $candidate->result,
            $selected,
        );
    }

    /**
     * @param list<SearchCandidate> $selected
     * @param list<SearchCandidate> $candidates
     * @return list<SearchCandidate>
     */
    private function ensureLineCompanions(array $selected, array $candidates, int $limit): array
    {
        $lines = array_values(array_filter(
            $selected,
            // Route names can match ordinary place queries (for example a
            // vehicle route containing "Førde"). Only exact/prefix line-code
            // intent should reserve a slot for a selectable vehicle.
            static fn(SearchCandidate $candidate): bool => ($candidate->result['type'] ?? null) === 'line'
                && $candidate->rank < 200,
        ));
        $lines = array_slice($lines, 0, intdiv($limit, 2));
        $protectedVehicles = [];
        foreach ($lines as $line) {
            $lineCode = $line->result['lineCode'] ?? null;
            if (!is_string($lineCode)) {
                continue;
            }
            $companion = array_find($selected, static fn(SearchCandidate $candidate): bool => ($candidate->result['type'] ?? null) === 'vehicle' && ($candidate->result['lineCode'] ?? null) === $lineCode)
                ?? array_find($candidates, static fn(SearchCandidate $candidate): bool => ($candidate->result['type'] ?? null) === 'vehicle' && ($candidate->result['lineCode'] ?? null) === $lineCode);
            if ($companion === null) {
                continue;
            }
            $alreadySelected = count(array_filter($selected, static fn(SearchCandidate $candidate): bool => $candidate->id === $companion->id)) > 0;
            if (!$alreadySelected) {
                if (count($selected) >= $limit) {
                    $replacement = null;
                    for ($index = count($selected) - 1; $index >= 0; $index--) {
                        $candidate = $selected[$index];
                        if (($candidate->result['type'] ?? null) !== 'line' && !isset($protectedVehicles[$candidate->id])) {
                            $replacement = $index;
                            break;
                        }
                    }
                    if ($replacement === null) {
                        continue;
                    }
                    array_splice($selected, $replacement, 1);
                }
                $selected[] = $companion;
            }
            $protectedVehicles[$companion->id] = true;
        }

        return $selected;
    }

    /** @param list<string> $aliases */
    private function rank(string $query, string $label, string $secondary, array $aliases): int
    {
        $primary = [$label, ...$aliases];
        if (in_array($query, $primary, true)) {
            return 0;
        }
        $prefixCompletion = $this->shortestPrefixCompletion($primary, $query);
        if ($prefixCompletion !== null) {
            return 100 + min(49, $prefixCompletion);
        }
        $tokenCompletion = $this->shortestPrefixCompletion($this->tokens($primary), $query);
        if ($tokenCompletion !== null) {
            return 200 + min(49, $tokenCompletion);
        }
        if ($secondary === $query || ($secondary !== '' && str_starts_with($secondary, $query))) {
            return 300;
        }
        if ($this->anyContains([...$primary, $secondary], $query)) {
            return 400;
        }

        $maximumDistance = $this->normalizer->fuzzyDistance($query);
        if ($maximumDistance > 0) {
            $best = null;
            foreach ([...$this->tokens($primary), ...$this->tokens([$secondary])] as $token) {
                $distance = $this->normalizer->damerauLevenshtein($query, $token);
                if ($distance <= $maximumDistance && ($best === null || $distance < $best)) {
                    $best = $distance;
                }
            }
            if ($best !== null) {
                return 500 + ($best * 10);
            }
        }

        return 1_000;
    }

    /** @param list<string> $values */
    private function shortestPrefixCompletion(array $values, string $query): ?int
    {
        $lengths = array_map(
            static fn(string $value): int => mb_strlen($value) - mb_strlen($query),
            array_values(array_filter($values, static fn(string $value): bool => str_starts_with($value, $query))),
        );

        return $lengths === [] ? null : min($lengths);
    }

    /** @param list<string> $values */
    private function anyContains(array $values, string $query): bool
    {
        return count(array_filter($values, static fn(string $value): bool => str_contains($value, $query))) > 0;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function tokens(array $values): array
    {
        $tokens = [];
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }
            $tokens = [...$tokens, ...explode(' ', $value)];
        }

        return array_values(array_unique($tokens));
    }
}
