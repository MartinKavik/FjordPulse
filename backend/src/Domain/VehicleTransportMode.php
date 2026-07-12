<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum VehicleTransportMode: string
{
    case Air = 'air';
    case Bus = 'bus';
    case Coach = 'coach';
    case Ferry = 'ferry';
    case Metro = 'metro';
    case Taxi = 'taxi';
    case Tram = 'tram';
    case Rail = 'rail';
    case Unknown = 'unknown';

    public static function fromEntur(mixed $value): self
    {
        if (!is_string($value)) {
            return self::Unknown;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::Unknown;
    }
}
