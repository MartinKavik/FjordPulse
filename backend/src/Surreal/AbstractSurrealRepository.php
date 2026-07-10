<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

abstract readonly class AbstractSurrealRepository
{
    public function __construct(protected SurrealConnection $connection)
    {
    }

    protected static function timestamp(DateTimeInterface $dateTime): string
    {
        return DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param list<mixed> $results
     * @return array<string, mixed>
     */
    protected static function lastRecord(array $results, string $operation): array
    {
        $index = count($results) - 1;
        $record = DatabaseRecord::one($index >= 0 ? $results[$index] : null);

        if ($record === null) {
            throw new \RuntimeException("SurrealDB {$operation} did not return a record.");
        }

        return $record;
    }
}
