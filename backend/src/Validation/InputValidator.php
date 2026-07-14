<?php

declare(strict_types=1);

namespace FjordPulse\Validation;

use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Dto\BoundingBox;
use InvalidArgumentException;

final class InputValidator
{
    public static function stationId(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 200
            || preg_match('/^NSR:StopPlace:[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new ValidationFailure('invalid_station', 'Station id is invalid.', ['field' => 'stationId']);
        }

        return $value;
    }

    public static function vehicleId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,199}$/D', $value) !== 1) {
            throw new ValidationFailure('invalid_vehicle', 'Vehicle id is invalid.', ['field' => 'vehicleId']);
        }

        return $value;
    }

    public static function query(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ValidationFailure('invalid_query', 'Search query is required.', ['field' => 'q']);
        }
        $query = trim($value);
        if ($query === '' || mb_strlen($query) > 200) {
            throw new ValidationFailure('invalid_query', 'Search query must contain 1 to 200 characters.', ['field' => 'q']);
        }

        return $query;
    }

    public static function limit(mixed $value, int $default = 20, int $maximum = 50): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 1 || $value > $maximum) {
            throw new ValidationFailure('invalid_limit', "Limit must be between 1 and {$maximum}.", ['field' => 'limit']);
        }

        return $value;
    }

    public static function serviceDate(mixed $value, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new ValidationFailure('invalid_date', 'Date must use YYYY-MM-DD.', ['field' => 'date']);
        }
        $timeZone = new DateTimeZone('Europe/Oslo');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timeZone);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationFailure('invalid_date', 'Date must be a valid calendar date.', ['field' => 'date']);
        }
        $today = ($now ?? new DateTimeImmutable('now', $timeZone))->setTimezone($timeZone)->setTime(0, 0);
        if ($date < $today || $date > $today->modify('+7 days')) {
            throw new ValidationFailure(
                'invalid_date',
                'Date must be today or within the next seven days.',
                ['field' => 'date'],
            );
        }

        return $date;
    }

    public static function timetableCursor(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new ValidationFailure('invalid_cursor', 'Timetable cursor is invalid.', ['field' => 'cursor']);
        }

        return $value;
    }

    public static function boolean(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if ($value === true || $value === false) {
            return $value;
        }
        if (is_string($value) && in_array(strtolower($value), ['1', 'true'], true)) {
            return true;
        }
        if (is_string($value) && in_array(strtolower($value), ['0', 'false'], true)) {
            return false;
        }

        throw new ValidationFailure('invalid_boolean', 'Boolean parameter must be true or false.', ['field' => 'refresh']);
    }

    public static function zoom(mixed $value): float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float)$value;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new ValidationFailure('invalid_zoom', 'Zoom must be numeric.', ['field' => 'zoom']);
        }
        $zoom = (float)$value;
        if (!is_finite($zoom) || $zoom < 0.0 || $zoom > 24.0) {
            throw new ValidationFailure('invalid_zoom', 'Zoom must be between 0 and 24.', ['field' => 'zoom']);
        }

        return $zoom;
    }

    public static function boundingBox(mixed $value): BoundingBox
    {
        if (!is_string($value) || strlen($value) > 128
            || preg_match('/^-?[0-9]+(?:\\.[0-9]+)?(?:,-?[0-9]+(?:\\.[0-9]+)?){3}$/D', $value) !== 1) {
            throw new ValidationFailure('invalid_bbox', 'Bounding box must contain four comma-separated coordinates.', ['field' => 'bbox']);
        }
        $parts = array_map('floatval', explode(',', $value));

        try {
            return new BoundingBox($parts[0], $parts[1], $parts[2], $parts[3]);
        } catch (InvalidArgumentException $exception) {
            throw new ValidationFailure('invalid_bbox', $exception->getMessage(), ['field' => 'bbox'], $exception);
        }
    }
}
