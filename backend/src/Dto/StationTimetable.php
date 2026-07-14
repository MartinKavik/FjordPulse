<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class StationTimetable
{
    /** @param list<Departure> $departures */
    public function __construct(
        public string $stationId,
        public string $serviceDate,
        public string $timeZone,
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
        public array $departures,
        public bool $complete,
        public DateTimeImmutable $fetchedAt,
        public string $version,
    ) {
        if ($windowEnd <= $windowStart) {
            throw new \InvalidArgumentException('Timetable window end must follow its start.');
        }
    }

    /** @param list<Departure> $departures */
    public static function create(
        string $stationId,
        string $serviceDate,
        string $timeZone,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        array $departures,
        bool $complete,
        ?DateTimeImmutable $fetchedAt = null,
    ): self {
        $fetchedAt ??= new DateTimeImmutable();
        $version = hash('sha256', json_encode([
            'stationId' => $stationId,
            'serviceDate' => $serviceDate,
            'timeZone' => $timeZone,
            'windowStart' => $windowStart->format(DateTimeInterface::RFC3339_EXTENDED),
            'windowEnd' => $windowEnd->format(DateTimeInterface::RFC3339_EXTENDED),
            'complete' => $complete,
            'fetchedAt' => $fetchedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'departures' => array_map(static fn(Departure $departure): array => $departure->toArray(), $departures),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return new self(
            $stationId,
            $serviceDate,
            $timeZone,
            $windowStart,
            $windowEnd,
            $departures,
            $complete,
            $fetchedAt,
            $version,
        );
    }

    /**
     * Put useful future service first without making earlier calls unreachable.
     * The persisted fetchedAt anchor and version make offset cursors stable.
     *
     * @return list<Departure>
     */
    public function displayOrderedDepartures(): array
    {
        $upcoming = [];
        $earlier = [];
        foreach ($this->departures as $departure) {
            $effectiveAt = $departure->expectedDepartureAt ?? $departure->aimedDepartureAt;
            if ($effectiveAt >= $this->fetchedAt) {
                $upcoming[] = $departure;
            } else {
                $earlier[] = $departure;
            }
        }
        usort($upcoming, static fn(Departure $left, Departure $right): int => self::departureKey($left) <=> self::departureKey($right));
        usort($earlier, static fn(Departure $left, Departure $right): int => self::departureKey($right) <=> self::departureKey($left));

        return [...$upcoming, ...$earlier];
    }

    /** @return array{string, string, string} */
    private static function departureKey(Departure $departure): array
    {
        return [
            ($departure->expectedDepartureAt ?? $departure->aimedDepartureAt)->format('U.u'),
            $departure->id,
            $departure->platform ?? '',
        ];
    }
}
