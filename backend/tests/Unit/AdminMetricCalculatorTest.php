<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Service\AdminMetricCalculator;
use PHPUnit\Framework\TestCase;

final class AdminMetricCalculatorTest extends TestCase
{
    public function testRealtimeActivityUsesOnlyExplicitRollingWindowCounters(): void
    {
        self::assertSame(11, AdminMetricCalculator::realtimeMessagesLastMinute([
            'messagesReceivedLastMinute' => 3,
            'messagesSentLastMinute' => 8,
            'messagesReceived' => 20_000,
            'messagesSent' => 30_000,
            'startedAt' => '2026-01-01T00:00:00Z',
        ]));
        self::assertSame(0, AdminMetricCalculator::realtimeMessagesLastMinute([
            'messagesReceived' => 20_000,
            'messagesSent' => 30_000,
            'startedAt' => '2026-01-01T00:00:00Z',
        ]));
    }

    public function testEnturMetricsSeparateOutboundCallsFromCacheAndExpiredBackoff(): void
    {
        $now = new DateTimeImmutable('2026-07-15T12:00:00Z');
        $entries = [
            self::entry('outbound-fast', 'success', 'miss', 10, $now->sub(new DateInterval('PT10S'))),
            self::entry('outbound-slow', 'error', 'miss', 100, $now->sub(new DateInterval('PT20S'))),
            self::entry('cached', 'cache_hit', 'hit', 0, $now->sub(new DateInterval('PT5S'))),
            self::entry('budget-skip', 'skipped_budget', 'miss', 1, $now->sub(new DateInterval('PT2S')), $now->add(new DateInterval('PT15S'))),
            self::entry('old-rate-limit', 'rate_limited', 'miss', 500, $now->sub(new DateInterval('PT2M')), $now->sub(new DateInterval('PT1M'))),
        ];

        $outbound = array_values(array_filter(
            $entries,
            static fn(EnturRequestLog $entry): bool => !in_array(
                $entry->outcome,
                ['cache_hit', 'skipped_budget', 'backoff'],
                true,
            ),
        ));
        $metrics = AdminMetricCalculator::enturRequestMetrics($entries, $outbound, true, $now);

        self::assertSame(2, $metrics['requestsPerMinute']);
        self::assertSame(0.2, $metrics['cacheHitRate']);
        self::assertSame(500.0, $metrics['p95LatencyMs']);
        self::assertTrue($metrics['inBackoff']);

        $afterDeadline = AdminMetricCalculator::enturRequestMetrics(
            $entries,
            $outbound,
            false,
            $now->add(new DateInterval('PT16S')),
        );
        self::assertFalse($afterDeadline['inBackoff']);
    }

    public function testOutboundSampleCannotBeCrowdedOutByTheDisplaySample(): void
    {
        $now = new DateTimeImmutable('2026-07-15T12:00:00Z');
        $cacheSample = [
            self::entry('cached-a', 'cache_hit', 'hit', 0, $now->sub(new DateInterval('PT2S'))),
            self::entry('cached-b', 'cache_hit', 'hit', 0, $now->sub(new DateInterval('PT1S'))),
        ];
        $outboundSample = [
            self::entry('outbound', 'success', 'miss', 37, $now->sub(new DateInterval('PT3S'))),
        ];

        $metrics = AdminMetricCalculator::enturRequestMetrics($cacheSample, $outboundSample, false, $now);

        self::assertSame(1, $metrics['requestsPerMinute']);
        self::assertSame(1.0, $metrics['cacheHitRate']);
        self::assertSame(37.0, $metrics['p95LatencyMs']);
        self::assertFalse($metrics['inBackoff']);
    }

    private static function entry(
        string $id,
        string $outcome,
        string $cache,
        int $latencyMs,
        DateTimeImmutable $requestedAt,
        ?DateTimeImmutable $retryAt = null,
    ): EnturRequestLog {
        return new EnturRequestLog(
            $id,
            'journey_planner',
            'station:test',
            $requestedAt,
            $outcome === 'success' ? 200 : null,
            $latencyMs,
            1,
            $cache,
            $outcome,
            $retryAt,
            $id,
        );
    }
}
