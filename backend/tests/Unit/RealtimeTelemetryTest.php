<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateTimeImmutable;
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
}
