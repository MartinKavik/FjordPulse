<?php

declare(strict_types=1);

namespace FjordPulse\Service;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Dto\EnturRequestLog;

final class AdminMetricCalculator
{
    /**
     * @param array<string, mixed> $telemetry
     */
    public static function realtimeMessagesLastMinute(array $telemetry): int
    {
        return self::nonNegativeInt($telemetry['messagesReceivedLastMinute'] ?? null)
            + self::nonNegativeInt($telemetry['messagesSentLastMinute'] ?? null);
    }

    /**
     * @param list<EnturRequestLog> $entries
     * @param list<EnturRequestLog> $outboundEntries
     * @return array{requestsPerMinute: int, cacheHitRate: float, p95LatencyMs: float|null, inBackoff: bool}
     */
    public static function enturRequestMetrics(
        array $entries,
        array $outboundEntries,
        bool $inBackoff,
        DateTimeImmutable $now,
    ): array {
        $outbound = array_values(array_filter($outboundEntries, self::isOutboundRequest(...)));
        $latencies = array_map(static fn(EnturRequestLog $entry): int => $entry->latencyMs, $outbound);
        sort($latencies);
        $p95Index = $latencies === [] ? null : max(0, (int)ceil(count($latencies) * 0.95) - 1);
        $cacheHits = count(array_filter($entries, static fn(EnturRequestLog $entry): bool => $entry->cache === 'hit'));
        $minuteAgo = $now->sub(new DateInterval('PT60S'));

        return [
            'requestsPerMinute' => count(array_filter(
                $outbound,
                static fn(EnturRequestLog $entry): bool => $entry->requestedAt > $minuteAgo,
            )),
            'cacheHitRate' => $entries === [] ? 0.0 : (float)($cacheHits / count($entries)),
            'p95LatencyMs' => $p95Index === null ? null : (float)$latencies[$p95Index],
            'inBackoff' => $inBackoff,
        ];
    }

    private static function isOutboundRequest(EnturRequestLog $entry): bool
    {
        return !in_array($entry->outcome, ['cache_hit', 'skipped_budget', 'backoff'], true);
    }

    private static function nonNegativeInt(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }
}
