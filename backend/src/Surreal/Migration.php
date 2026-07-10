<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use InvalidArgumentException;

final readonly class Migration
{
    public string $checksum;

    public function __construct(
        public string $name,
        public string $surql,
    ) {
        if (preg_match('/^\d{3,}_[a-z0-9_]+\.surql$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid migration filename: {$name}");
        }

        if (trim($surql) === '') {
            throw new InvalidArgumentException("Migration {$name} is empty.");
        }

        $this->checksum = hash('sha256', $surql);
    }

    /** @return list<self> */
    public static function discover(string $directory): array
    {
        $paths = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.surql');

        if ($paths === false) {
            throw new \RuntimeException("Unable to read migration directory: {$directory}");
        }

        sort($paths, SORT_STRING);
        $migrations = [];

        foreach ($paths as $path) {
            $surql = file_get_contents($path);

            if ($surql === false) {
                throw new \RuntimeException("Unable to read migration: {$path}");
            }

            $migrations[] = new self(basename($path), $surql);
        }

        return $migrations;
    }
}
