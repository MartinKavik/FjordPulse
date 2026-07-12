<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Domain\VehicleFreshnessPolicy;
use FjordPulse\Domain\VehicleTransportMode;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\VehicleState;
use PHPUnit\Framework\TestCase;

final class VehicleFreshnessPolicyTest extends TestCase
{
    public function testFreshnessUsesStaleThenFiveMinuteLostGrace(): void
    {
        $now = new DateTimeImmutable('2026-07-12T01:05:00Z');
        $policy = new VehicleFreshnessPolicy(30, 300);

        self::assertSame(VehicleFreshness::Live, $policy->at($now->modify('-30 seconds'), $now));
        self::assertSame(VehicleFreshness::Stale, $policy->at($now->modify('-31 seconds'), $now));
        self::assertSame(VehicleFreshness::Stale, $policy->at($now->modify('-300 seconds'), $now));
        self::assertSame(VehicleFreshness::Lost, $policy->at($now->modify('-301 seconds'), $now));
    }

    public function testMissingFeedRowDoesNotImmediatelyLoseARecentVehicle(): void
    {
        $now = new DateTimeImmutable('2026-07-12T01:05:00Z');
        $policy = new VehicleFreshnessPolicy(30, 300);
        $recent = self::vehicle(VehicleFreshness::Live, $now->modify('-10 seconds'));

        self::assertSame($recent, $policy->withoutNewObservation($recent, $now));
        $alreadyLost = self::vehicle(VehicleFreshness::Lost, $now->modify('-10 seconds'));
        self::assertSame($alreadyLost, $policy->withoutNewObservation($alreadyLost, $now), 'Missing data cannot revive a vehicle without a new observation.');

        $stale = $policy->withoutNewObservation(self::vehicle(VehicleFreshness::Live, $now->modify('-31 seconds')), $now);
        self::assertSame(VehicleFreshness::Stale, $stale->state);
        self::assertSame('2026-07-12T01:04:29+00:00', $stale->lastSeenAt->format(DATE_RFC3339));
        self::assertNotSame('existing-hash', $stale->contentHash);

        $lost = $policy->withoutNewObservation(self::vehicle(VehicleFreshness::Stale, $now->modify('-301 seconds')), $now);
        self::assertSame(VehicleFreshness::Lost, $lost->state);
    }

    private static function vehicle(VehicleFreshness $state, DateTimeImmutable $lastSeenAt): VehicleState
    {
        return new VehicleState(
            'vehicle-15',
            '2026-07-12T01:04:00.000Z',
            'existing-hash',
            $state,
            new Coordinate(60.3917, 5.3245),
            '15',
            'Bergen sentrum - Bønes',
            'Bønes',
            204.9,
            -10,
            null,
            $lastSeenAt,
            new DateTimeImmutable('2026-07-12T01:04:00Z'),
            null,
            transportMode: VehicleTransportMode::Bus,
        );
    }
}
