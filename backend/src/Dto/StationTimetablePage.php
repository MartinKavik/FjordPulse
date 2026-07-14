<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

use DateTimeInterface;

final readonly class StationTimetablePage
{
    /** @param list<Departure> $departures */
    public function __construct(
        public StationTimetable $timetable,
        public array $departures,
        public int $limit,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('Timetable page limit must be between 1 and 50.');
        }
        if ($hasMore !== ($nextCursor !== null)) {
            throw new \InvalidArgumentException('Timetable page cursor must be present if and only if another page exists.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stationId' => $this->timetable->stationId,
            'state' => 'fresh',
            'version' => $this->timetable->fetchedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'updatedAt' => $this->timetable->fetchedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'lastSuccessfulAt' => $this->timetable->fetchedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'warning' => $this->timetable->complete
                ? null
                : 'Some departures could not be included because an upstream result window reached its safety limit.',
            'mode' => 'day',
            'date' => $this->timetable->serviceDate,
            'timeZone' => $this->timetable->timeZone,
            'windowStart' => $this->timetable->windowStart->format(DateTimeInterface::RFC3339_EXTENDED),
            'windowEnd' => $this->timetable->windowEnd->format(DateTimeInterface::RFC3339_EXTENDED),
            'page' => [
                'limit' => $this->limit,
                'hasMore' => $this->hasMore,
                'nextCursor' => $this->nextCursor,
            ],
            'complete' => $this->timetable->complete,
            'totalCount' => $this->timetable->complete ? count($this->timetable->departures) : null,
            'departures' => array_map(static fn(Departure $departure): array => $departure->toArray(), $this->departures),
        ];
    }
}
