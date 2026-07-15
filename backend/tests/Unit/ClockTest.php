<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use FjordPulse\Time\FixedClock;
use FjordPulse\Time\MonotonicTimestamp;
use FjordPulse\Time\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixedClock::class)]
#[CoversClass(MonotonicTimestamp::class)]
#[CoversClass(SystemClock::class)]
final class ClockTest extends TestCase
{
    public function testFixedClockAlwaysReturnsTheInjectedInstant(): void
    {
        $instant = new DateTimeImmutable('2026-07-14T13:00:00+02:00');
        $clock = new FixedClock($instant);

        self::assertSame('2026-07-14T11:00:00+00:00', $clock->now()->format(\DateTimeInterface::RFC3339));
        self::assertSame($clock->now(), $clock->now());
    }

    public function testSystemClockReturnsTheCurrentInstant(): void
    {
        $before = new DateTimeImmutable();
        $now = (new SystemClock())->now();
        $after = new DateTimeImmutable();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }

    public function testMonotonicTimestampAdvancesAnEqualVersionByOneMillisecond(): void
    {
        $next = MonotonicTimestamp::afterVersion(
            new DateTimeImmutable('2026-07-14T11:00:00.000Z'),
            '2026-07-14T13:00:00.000+02:00',
        );

        self::assertSame('2026-07-14T11:00:00.001+00:00', $next->format('Y-m-d\\TH:i:s.vP'));
    }

    public function testMonotonicTimestampAdvancesWhenOnlySubMillisecondWallTimeMoved(): void
    {
        $next = MonotonicTimestamp::afterVersion(
            new DateTimeImmutable('2026-07-14T11:00:00.000900Z'),
            '2026-07-14T11:00:00.000Z',
        );

        self::assertSame('2026-07-14T11:00:00.001+00:00', $next->format('Y-m-d\\TH:i:s.vP'));
    }

    public function testMonotonicTimestampAdvancesFromThePreviousVersionWhenClockMovesBackward(): void
    {
        $next = MonotonicTimestamp::afterVersion(
            new DateTimeImmutable('2026-07-14T10:59:59.500Z'),
            '2026-07-14T11:00:00.123Z',
        );

        self::assertSame('2026-07-14T11:00:00.124+00:00', $next->format('Y-m-d\\TH:i:s.vP'));
    }

    public function testMonotonicTimestampKeepsANewerCandidateAndNormalizesItToUtc(): void
    {
        $next = MonotonicTimestamp::afterVersion(
            new DateTimeImmutable('2026-07-14T13:00:01.000+02:00'),
            '2026-07-14T11:00:00.999Z',
        );

        self::assertSame('2026-07-14T11:00:01.000+00:00', $next->format('Y-m-d\\TH:i:s.vP'));
    }
}
