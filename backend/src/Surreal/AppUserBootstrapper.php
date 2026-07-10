<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use InvalidArgumentException;
use SurrealDB\SDK\Types\Value;

final readonly class AppUserBootstrapper
{
    public function __construct(private SurrealConnection $rootConnection)
    {
    }

    public function bootstrap(string $username, string $password): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $username) !== 1) {
            throw new InvalidArgumentException('SurrealDB application username is not a valid identifier.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('SurrealDB application password must not be empty.');
        }

        $identifier = '`' . str_replace('`', '\\`', $username) . '`';
        $passwordLiteral = Value::toSurql($password);
        $this->rootConnection->run(
            "DEFINE USER OVERWRITE {$identifier} ON DATABASE PASSWORD {$passwordLiteral} ROLES EDITOR;",
        );
    }
}
