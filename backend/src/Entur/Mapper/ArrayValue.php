<?php

declare(strict_types=1);

namespace FjordPulse\Entur\Mapper;

final class ArrayValue
{
    /**
     * @param array<mixed> $values
     * @param list<int|string> $path
     */
    public static function get(array $values, array $path): mixed
    {
        $current = $values;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }
}
