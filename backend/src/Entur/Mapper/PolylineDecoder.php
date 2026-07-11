<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Mapper;

use FjordPulse\Dto\Coordinate;
use FjordPulse\Entur\SourceUnavailable;

final class PolylineDecoder
{
    /** @return list<Coordinate> */
    public function decode(string $encoded, int $maximumPoints = 20_000): array
    {
        if ($encoded === '' || $maximumPoints < 2 || $maximumPoints > 20_000) {
            throw new SourceUnavailable('Entur returned invalid journey geometry metadata.');
        }

        $length = strlen($encoded);
        $index = 0;
        $latitude = 0;
        $longitude = 0;
        $coordinates = [];

        while ($index < $length) {
            $latitude += $this->component($encoded, $index);
            $longitude += $this->component($encoded, $index);
            try {
                $coordinates[] = new Coordinate($latitude / 100_000.0, $longitude / 100_000.0);
            } catch (\InvalidArgumentException $error) {
                throw new SourceUnavailable('Entur journey geometry contains an out-of-range coordinate.', previous: $error);
            }
            if (count($coordinates) > $maximumPoints) {
                throw new SourceUnavailable('Entur journey geometry exceeds the supported 20,000-point limit.');
            }
        }

        if (count($coordinates) < 2) {
            throw new SourceUnavailable('Entur journey geometry contains fewer than two points.');
        }

        return $coordinates;
    }

    private function component(string $encoded, int &$index): int
    {
        $result = 0;
        $shift = 0;
        $length = strlen($encoded);

        do {
            if ($index >= $length || $shift > 30) {
                throw new SourceUnavailable('Entur returned malformed encoded journey geometry.');
            }
            $byte = ord($encoded[$index++]) - 63;
            if ($byte < 0 || $byte > 63) {
                throw new SourceUnavailable('Entur returned malformed encoded journey geometry.');
            }
            $result |= ($byte & 0x1f) << $shift;
            $shift += 5;
        } while ($byte >= 0x20);

        return ($result & 1) !== 0 ? ~($result >> 1) : ($result >> 1);
    }
}
