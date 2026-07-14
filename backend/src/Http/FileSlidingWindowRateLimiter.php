<?php

declare(strict_types=1);

namespace FjordPulse\Http;

use JsonException;
use RuntimeException;

/**
 * A process-independent, single-host limiter for FrankenPHP classic mode.
 *
 * Keys are HMACs produced by the middleware. The first byte selects one of at
 * most 256 lock-protected files; stale buckets in that shard are removed on
 * every access, bounding file count without another service or cleanup job.
 */
final readonly class FileSlidingWindowRateLimiter
{
    private const int MAX_SHARD_BYTES = 4_194_304;

    public function __construct(private string $directory)
    {
        if ($directory === '') {
            throw new \InvalidArgumentException('Rate-limit directory cannot be empty.');
        }
    }

    public function consume(
        string $key,
        int $limit,
        float $now,
        float $windowSeconds = 60.0,
    ): RateLimitDecision {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            throw new \InvalidArgumentException('Rate-limit key must be a SHA-256 HMAC.');
        }
        if ($limit < 1 || !is_finite($now) || !is_finite($windowSeconds) || $windowSeconds <= 0) {
            throw new \InvalidArgumentException('Rate-limit window configuration is invalid.');
        }
        $this->ensureDirectory();
        $path = $this->directory . DIRECTORY_SEPARATOR . substr($key, 0, 2) . '.json';
        $handle = fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open the rate-limit state shard.');
        }
        @chmod($path, 0o600);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the rate-limit state shard.');
            }
            $contents = stream_get_contents($handle);
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read the rate-limit state shard.');
            }
            if (strlen($contents) > self::MAX_SHARD_BYTES) {
                throw new RuntimeException('Rate-limit state shard exceeds its safety bound.');
            }
            $state = $this->decode($contents);
            $threshold = $now - $windowSeconds;
            foreach ($state as $storedKey => $timestamps) {
                $recent = array_values(array_filter(
                    $timestamps,
                    static fn(float $timestamp): bool => $timestamp > $threshold && $timestamp <= $now,
                ));
                if ($recent === []) {
                    unset($state[$storedKey]);
                } else {
                    $state[$storedKey] = $recent;
                }
            }

            $entries = $state[$key] ?? [];
            $allowed = count($entries) < $limit;
            if ($allowed) {
                $entries[] = $now;
                $state[$key] = $entries;
            }
            $retryAfter = $allowed
                ? 0
                : max(1, (int)ceil(($entries[0] + $windowSeconds) - $now));
            $this->write($handle, $state);

            return new RateLimitDecision($allowed, $retryAfter);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the rate-limit state directory.');
        }
        @chmod($this->directory, 0o700);
        if (!is_writable($this->directory)) {
            throw new RuntimeException('Rate-limit state directory is not writable.');
        }
    }

    /** @return array<string, list<float>> */
    private function decode(string $contents): array
    {
        if ($contents === '') {
            return [];
        }
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Rate-limit state shard is corrupt.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Rate-limit state shard is invalid.');
        }

        $state = [];
        foreach ($decoded as $key => $timestamps) {
            if (!is_string($key) || preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1 || !is_array($timestamps)) {
                throw new RuntimeException('Rate-limit state shard is invalid.');
            }
            $state[$key] = [];
            foreach ($timestamps as $timestamp) {
                if (!is_int($timestamp) && !is_float($timestamp)) {
                    throw new RuntimeException('Rate-limit state shard is invalid.');
                }
                $state[$key][] = (float)$timestamp;
            }
        }

        return $state;
    }

    /**
     * @param resource $handle
     * @param array<string, list<float>> $state
     */
    private function write($handle, array $state): void
    {
        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Unable to encode rate-limit state.', 0, $error);
        }
        if (strlen($encoded) > self::MAX_SHARD_BYTES) {
            throw new RuntimeException('Rate-limit state shard exceeds its safety bound.');
        }
        if (!rewind($handle) || !ftruncate($handle, 0)) {
            throw new RuntimeException('Unable to reset the rate-limit state shard.');
        }
        $written = fwrite($handle, $encoded);
        if ($written !== strlen($encoded) || !fflush($handle)) {
            throw new RuntimeException('Unable to persist the rate-limit state shard.');
        }
    }
}
