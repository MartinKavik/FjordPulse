<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Service\SearchNormalizer;
use FjordPulse\Service\SearchRanker;
use PHPUnit\Framework\TestCase;

final class SearchNormalizerTest extends TestCase
{
    public function testNorwegianCharactersPunctuationAndWhitespaceFoldDeterministically(): void
    {
        $normalizer = new SearchNormalizer();

        self::assertSame('forde alesund aenes', $normalizer->normalize('  FØRDE,  Ålesund / Ænes '));
        self::assertSame(['forde', 'alesund', 'aenes'], $normalizer->tokens('Førde Ålesund Ænes'));
        self::assertSame(0, $normalizer->fuzzyDistance('Fo'));
        self::assertSame(1, $normalizer->fuzzyDistance('Frode'));
        self::assertSame(1, $normalizer->damerauLevenshtein('frode', 'forde'));
    }

    public function testRankingPrefersExactAndPrefixMatchesAcrossResultTypes(): void
    {
        $ranker = new SearchRanker(new SearchNormalizer());
        $station = $ranker->candidate('Forde', [
            'type' => 'station',
            'id' => 'station',
            'label' => 'Førde rutebilstasjon',
            'secondaryText' => 'Sunnfjord',
        ]);
        $place = $ranker->candidate('Forde', [
            'type' => 'place',
            'id' => 'place',
            'label' => 'Ytre Førde',
            'secondaryText' => 'Sunnfjord',
        ]);

        self::assertSame('station', $ranker->ordered([$place, $station], 2)[0]['id']);
    }

    public function testFuzzyStationRemainsVisibleAmongLiteralGeocoderMatches(): void
    {
        $ranker = new SearchRanker(new SearchNormalizer());
        $candidates = [];
        foreach (range(1, 5) as $index) {
            $candidates[] = $ranker->candidate('Frode', [
                'type' => 'place',
                'id' => 'place-' . $index,
                'label' => 'Frode place ' . $index,
                'secondaryText' => 'Norway',
            ]);
        }
        $candidates[] = $ranker->candidate('Frode', [
            'type' => 'station',
            'id' => 'forde-station',
            'label' => 'Førde rutebilstasjon',
            'secondaryText' => 'Sunnfjord',
        ]);

        $ids = array_column($ranker->ordered($candidates, 5), 'id');
        self::assertContains('forde-station', $ids);
    }

    public function testIncidentalRouteMatchesDoNotEvictAPlaceStation(): void
    {
        $ranker = new SearchRanker(new SearchNormalizer());
        $candidates = [
            $ranker->candidate('Forde', [
                'type' => 'station',
                'id' => 'forde-station',
                'label' => 'Førde rutebilstasjon',
                'secondaryText' => 'Sunnfjord',
            ]),
            $ranker->candidate('Forde', [
                'type' => 'place',
                'id' => 'forde-place',
                'label' => 'Førde sentrum',
                'secondaryText' => 'Sunnfjord',
            ]),
        ];
        foreach (['100', '110', 'FB59'] as $lineCode) {
            $aliases = [$lineCode, 'Førde–destination'];
            $candidates[] = $ranker->candidate('Forde', [
                'type' => 'line',
                'id' => 'line:' . $lineCode,
                'label' => 'Line ' . $lineCode,
                'secondaryText' => 'Førde–destination',
                'lineCode' => $lineCode,
            ], $aliases);
            $candidates[] = $ranker->candidate('Forde', [
                'type' => 'vehicle',
                'id' => 'vehicle:' . $lineCode,
                'label' => 'Vehicle ' . $lineCode,
                'secondaryText' => 'Line ' . $lineCode,
                'lineCode' => $lineCode,
            ], $aliases);
        }

        self::assertContains('forde-station', array_column($ranker->ordered($candidates, 5), 'id'));
    }

    public function testExactLineSearchKeepsASelectableVehicleCompanion(): void
    {
        $ranker = new SearchRanker(new SearchNormalizer());
        $candidates = [
            $ranker->candidate('Line 100', [
                'type' => 'line',
                'id' => 'line:100',
                'label' => 'Line 100',
                'secondaryText' => 'Førde–Florø',
                'lineCode' => '100',
            ]),
            $ranker->candidate('Line 100', [
                'type' => 'place',
                'id' => 'line-place',
                'label' => 'Line 100 place',
                'secondaryText' => 'Norway',
            ]),
            $ranker->candidate('Line 100', [
                'type' => 'vehicle',
                'id' => 'vehicle:100',
                'label' => 'Vehicle 100',
                'secondaryText' => 'Line 100 · Florø',
                'lineCode' => '100',
            ]),
        ];

        self::assertSame(['line:100', 'vehicle:100'], array_column($ranker->ordered($candidates, 2, true), 'id'));
    }
}
