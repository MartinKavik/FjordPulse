<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\SourceState;
use FjordPulse\Dto\JourneySnapshot;
use FjordPulse\Dto\VehicleJourneyReference;
use FjordPulse\Entur\DegradedJourneyFactory;
use PHPUnit\Framework\TestCase;

final class DegradedJourneyFactoryTest extends TestCase
{
    public function testRepeatedDegradedRefreshDoesNotRecursivelyChangeContentHash(): void
    {
        $reference = new VehicleJourneyReference('SKY:ServiceJourney:15', '2026-07-12');
        $firstCached = self::cached('version-1', 'recursive-hash-1', '2026-07-12T01:00:00Z');
        $secondCached = self::cached('version-2', 'recursive-hash-2', '2026-07-12T01:01:00Z');

        $first = DegradedJourneyFactory::create(
            $reference,
            $firstCached,
            SourceState::Unavailable,
            'Journey unavailable.',
            new DateTimeImmutable('2026-07-12T01:02:00Z'),
        );
        $second = DegradedJourneyFactory::create(
            $reference,
            $secondCached,
            SourceState::Unavailable,
            'Journey unavailable.',
            new DateTimeImmutable('2026-07-12T01:03:00Z'),
        );

        self::assertSame($first->contentHash, $second->contentHash);
        self::assertNotSame($first->version, $second->version);

        $changedWarning = DegradedJourneyFactory::create(
            $reference,
            $secondCached,
            SourceState::Unavailable,
            'Different failure.',
            new DateTimeImmutable('2026-07-12T01:04:00Z'),
        );
        self::assertNotSame($first->contentHash, $changedWarning->contentHash);
    }

    private static function cached(string $version, string $contentHash, string $refreshedAt): JourneySnapshot
    {
        return new JourneySnapshot(
            'SKY:ServiceJourney:15',
            '2026-07-12',
            null,
            $version,
            $contentHash,
            SourceState::Unavailable,
            null,
            [],
            new DateTimeImmutable($refreshedAt),
            new DateTimeImmutable('2026-07-12T00:59:00Z'),
            'Journey unavailable.',
        );
    }
}
