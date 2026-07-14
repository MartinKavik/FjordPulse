<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use InvalidArgumentException;

final readonly class DatabaseUserCredentials
{
    public function __construct(
        public string $username,
        #[\SensitiveParameter]
        public string $password,
    ) {
        if (
            strlen($username) > 128
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $username) !== 1
        ) {
            throw new InvalidArgumentException('SurrealDB database username is not a valid identifier.');
        }

        if (
            $password === ''
            || trim($password) === ''
            || strlen($password) > 1_024
            || !mb_check_encoding($password, 'UTF-8')
        ) {
            throw new InvalidArgumentException('SurrealDB database password is outside the accepted bounds.');
        }
    }
}
