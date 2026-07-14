<?php

declare(strict_types=1);

namespace FjordPulse\Dto;

final readonly class StationTimetableCursor
{
    private const int FORMAT_VERSION = 1;

    public function __construct(
        public string $stationId,
        public string $serviceDate,
        public string $timetableVersion,
        public int $offset,
    ) {
        if ($offset < 0 || preg_match('/^[a-f0-9]{64}$/D', $timetableVersion) !== 1) {
            throw new \InvalidArgumentException('Timetable cursor is invalid.');
        }
    }

    public function encode(): string
    {
        $json = json_encode([
            'v' => self::FORMAT_VERSION,
            'stationId' => $this->stationId,
            'date' => $this->serviceDate,
            'version' => $this->timetableVersion,
            'offset' => $this->offset,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): self
    {
        if ($encoded === '' || strlen($encoded) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw new \InvalidArgumentException('Timetable cursor is invalid.');
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Timetable cursor is invalid.');
        }
        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Timetable cursor is invalid.', previous: $error);
        }
        if (!is_array($payload)
            || ($payload['v'] ?? null) !== self::FORMAT_VERSION
            || !is_string($payload['stationId'] ?? null)
            || !is_string($payload['date'] ?? null)
            || !is_string($payload['version'] ?? null)
            || !is_int($payload['offset'] ?? null)) {
            throw new \InvalidArgumentException('Timetable cursor is invalid.');
        }

        return new self(
            $payload['stationId'],
            $payload['date'],
            $payload['version'],
            $payload['offset'],
        );
    }
}
