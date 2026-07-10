<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Log\AbstractLogger;

final class JsonLineLogger extends AbstractLogger
{
    /** @param resource|null $stream */
    public function __construct(private $stream = null)
    {
        $this->stream ??= STDERR;
        if (!is_resource($this->stream)) {
            throw new \InvalidArgumentException('Structured log stream must be a resource.');
        }
    }

    /** @param mixed $level @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (!is_string($key) || preg_match('/password|secret|token|credential/i', $key) === 1) {
                continue;
            }
            $safe[$key] = self::normalize($value);
        }
        $record = [
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::RFC3339_EXTENDED),
            'level' => is_scalar($level) ? (string)$level : 'unknown',
            'service' => 'realtime',
            'message' => (string)$message,
            ...$safe,
        ];
        $stream = $this->stream;
        if (!is_resource($stream)) {
            throw new \LogicException('Structured log stream is closed.');
        }
        fwrite($stream, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \Throwable) {
            return ['type' => $value::class, 'message' => $value->getMessage()];
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                if (!is_string($key) || preg_match('/password|secret|token/i', $key) !== 1) {
                    $normalized[$key] = self::normalize($item);
                }
            }

            return $normalized;
        }
        if (is_object($value)) {
            return $value instanceof \Stringable ? (string)$value : $value::class;
        }
        if (is_resource($value)) {
            return get_resource_type($value);
        }

        return $value;
    }
}
