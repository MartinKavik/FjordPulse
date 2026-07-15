<?php

declare(strict_types=1);

namespace FjordPulse\Time;

use DateTimeImmutable;
use DateTimeZone;

final class MonotonicTimestamp
{
    public static function afterVersion(DateTimeImmutable $candidate, ?string $previousVersion): DateTimeImmutable
    {
        $candidate = self::millisecond($candidate);
        if ($previousVersion === null) {
            return $candidate;
        }

        $previous = self::millisecond(new DateTimeImmutable($previousVersion));

        return $candidate > $previous ? $candidate : $previous->modify('+1 millisecond');
    }

    private static function millisecond(DateTimeImmutable $timestamp): DateTimeImmutable
    {
        $utc = $timestamp->setTimezone(new DateTimeZone('UTC'));

        return new DateTimeImmutable($utc->format('Y-m-d\\TH:i:s.v\\Z'));
    }
}
