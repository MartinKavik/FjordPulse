<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use InvalidArgumentException;

final readonly class Migration
{
    public const int MAX_NAME_LENGTH = 300;
    public const int MAX_SOURCE_LENGTH = 250_000;

    public string $checksum;

    public function __construct(
        public string $name,
        public string $surql,
    ) {
        if (preg_match('/^\d{3,}_[a-z0-9_]+\.surql$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid migration filename: {$name}");
        }

        if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Migration filename exceeds %d characters.', self::MAX_NAME_LENGTH),
            );
        }

        if (trim($surql) === '') {
            throw new InvalidArgumentException("Migration {$name} is empty.");
        }

        if (!mb_check_encoding($surql, 'UTF-8')) {
            throw new InvalidArgumentException("Migration {$name} is not valid UTF-8.");
        }

        if (mb_strlen($surql, 'UTF-8') > self::MAX_SOURCE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Migration %s source exceeds %d characters.',
                $name,
                self::MAX_SOURCE_LENGTH,
            ));
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
