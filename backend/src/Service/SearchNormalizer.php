<?php

declare(strict_types=1);

namespace FjordPulse\Service;

use Normalizer;
use RuntimeException;

final class SearchNormalizer
{
    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'ø' => 'o',
            'æ' => 'ae',
            'å' => 'a',
        ]);
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_KD);
        if (!is_string($decomposed)) {
            throw new RuntimeException('Unable to normalize search text with ICU.');
        }
        $withoutMarks = preg_replace('/\p{Mn}+/u', '', $decomposed);
        if (!is_string($withoutMarks)) {
            throw new RuntimeException('Unable to remove combining marks from search text.');
        }
        $words = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $withoutMarks);
        if (!is_string($words)) {
            throw new RuntimeException('Unable to normalize search punctuation.');
        }
        $collapsed = preg_replace('/\s+/u', ' ', trim($words));
        if (!is_string($collapsed)) {
            throw new RuntimeException('Unable to normalize search whitespace.');
        }

        return $collapsed;
    }

    /** @return list<string> */
    public function tokens(string $value): array
    {
        $normalized = $this->normalize($value);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    public function fuzzyDistance(string $query): int
    {
        $normalized = $this->normalize($query);
        if ($normalized === '' || str_contains($normalized, ' ')) {
            return 0;
        }
        $length = mb_strlen($normalized, 'UTF-8');
        if ($length < 4) {
            return 0;
        }

        return $length <= 7 ? 1 : 2;
    }

    public function exactVehicleId(string $query): ?string
    {
        $trimmed = trim($query);
        $prefixed = preg_match('/^(?:vehicle|kj[øo]ret[øo]y)\s+(.+)$/iu', $trimmed, $matches) === 1;
        $candidate = $prefixed ? trim($matches[1]) : $trimmed;
        if (!$prefixed && !str_contains($candidate, ':') && preg_match('/\d/u', $candidate) !== 1) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,199}$/D', $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }

    public function damerauLevenshtein(string $left, string $right): int
    {
        $leftCharacters = mb_str_split($left);
        $rightCharacters = mb_str_split($right);
        $leftLength = count($leftCharacters);
        $rightLength = count($rightCharacters);
        $distance = [];
        for ($leftIndex = 0; $leftIndex <= $leftLength; $leftIndex++) {
            $distance[$leftIndex] = [$leftIndex];
        }
        for ($rightIndex = 0; $rightIndex <= $rightLength; $rightIndex++) {
            $distance[0][$rightIndex] = $rightIndex;
        }
        for ($leftIndex = 1; $leftIndex <= $leftLength; $leftIndex++) {
            for ($rightIndex = 1; $rightIndex <= $rightLength; $rightIndex++) {
                $substitutionCost = $leftCharacters[$leftIndex - 1] === $rightCharacters[$rightIndex - 1] ? 0 : 1;
                $value = min(
                    $distance[$leftIndex - 1][$rightIndex] + 1,
                    $distance[$leftIndex][$rightIndex - 1] + 1,
                    $distance[$leftIndex - 1][$rightIndex - 1] + $substitutionCost,
                );
                if (
                    $leftIndex > 1
                    && $rightIndex > 1
                    && $leftCharacters[$leftIndex - 1] === $rightCharacters[$rightIndex - 2]
                    && $leftCharacters[$leftIndex - 2] === $rightCharacters[$rightIndex - 1]
                ) {
                    $value = min($value, $distance[$leftIndex - 2][$rightIndex - 2] + 1);
                }
                $distance[$leftIndex][$rightIndex] = $value;
            }
        }

        return $distance[$leftLength][$rightLength];
    }
}
