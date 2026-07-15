<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
use DateInterval;
use FjordPulse\Realtime\RealtimeTelemetry;
use PHPUnit\Framework\TestCase;

final class RealtimeTelemetryTest extends TestCase
{
    public function testUnusedSourcesAreReportedWithoutClaimingFailureOrSuccess(): void
    {
        self::assertSame('idle', (new RealtimeTelemetry('real'))->enturState());
        self::assertSame('not_used', (new RealtimeTelemetry('fake'))->enturState());
    }

    public function testObservedSourceOutcomesDriveTruthfulState(): void
    {
        $telemetry = new RealtimeTelemetry('real');

        $telemetry->sourceOutcome('success');
        self::assertSame('ok', $telemetry->enturState());

        $telemetry->sourceOutcome('error');
        self::assertSame('delayed', $telemetry->enturState());

        $telemetry->sourceOutcome('rate_limited', new DateTimeImmutable('+1 minute'));
        self::assertSame('rate_limited', $telemetry->enturState());

        $telemetry->sourceOutcome('success');
        self::assertSame('ok', $telemetry->enturState());
    }

    public function testSchedulerBackoffOverridesTheLatestSuccessfulOutcome(): void
    {
        $telemetry = new RealtimeTelemetry('real');
        $telemetry->sourceOutcome('success');
        $telemetry->sourceBackoff(new DateTimeImmutable('+1 minute'));

        self::assertSame('backoff', $telemetry->enturState());
    }

    public function testMessageActivityUsesARollingMinuteInsteadOfProcessLifetimeAverage(): void
    {
        $now = new DateTimeImmutable('2026-07-15T12:00:00Z');
        $telemetry = new RealtimeTelemetry('real', static function () use (&$now): DateTimeImmutable {
            return $now;
        });

        $telemetry->received();
        $telemetry->received();
        $telemetry->sent(3);
        $telemetry->broadcast(2);
        $atActivity = $telemetry->toArray();

        self::assertSame(2, $atActivity['messagesReceivedLastMinute']);
        self::assertSame(5, $atActivity['messagesSentLastMinute']);
        self::assertSame('2026-07-15T12:00:00.000+00:00', $atActivity['lastBroadcastAt']);

        $now = $now->add(new DateInterval('PT60S'));
        $afterWindow = $telemetry->toArray();

        self::assertSame(0, $afterWindow['messagesReceivedLastMinute']);
        self::assertSame(0, $afterWindow['messagesSentLastMinute']);
        self::assertSame(2, $afterWindow['messagesReceived']);
        self::assertSame(5, $afterWindow['messagesSent']);
    }

    public function testBroadcastTimestampMeansAtLeastOneBrowserReceivedTheMessage(): void
    {
        $now = new DateTimeImmutable('2026-07-15T12:00:00Z');
        $telemetry = new RealtimeTelemetry('real', static function () use (&$now): DateTimeImmutable {
            return $now;
        });

        $telemetry->broadcast(0);
        self::assertNull($telemetry->toArray()['lastBroadcastAt']);

        $now = $now->add(new DateInterval('PT5S'));
        $telemetry->broadcast(1);
        self::assertSame('2026-07-15T12:00:05.000+00:00', $telemetry->toArray()['lastBroadcastAt']);

        $now = $now->add(new DateInterval('PT5S'));
        $telemetry->broadcast(0);
        self::assertSame('2026-07-15T12:00:05.000+00:00', $telemetry->toArray()['lastBroadcastAt']);
    }

    public function testRollingWindowKeepsSubsecondActivityUntilAFullMinuteElapsed(): void
    {
        $now = new DateTimeImmutable('2026-07-15T12:00:00.999999Z');
        $telemetry = new RealtimeTelemetry('real', static function () use (&$now): DateTimeImmutable {
            return $now;
        });
        $telemetry->received();

        $now = new DateTimeImmutable('2026-07-15T12:01:00.000000Z');
        self::assertSame(1, $telemetry->toArray()['messagesReceivedLastMinute']);

        $now = new DateTimeImmutable('2026-07-15T12:01:00.999999Z');
        self::assertSame(0, $telemetry->toArray()['messagesReceivedLastMinute']);
    }
}
