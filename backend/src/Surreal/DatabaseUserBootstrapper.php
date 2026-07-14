<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Value;

final readonly class DatabaseUserBootstrapper
{
    public function __construct(private SurrealConnection $rootConnection)
    {
    }

    public function bootstrap(
        DatabaseUserCredentials $credentials,
        DatabaseUserRole $role,
    ): DatabaseUserBootstrapEvidence {
        $identifier = Table::escapeIdent($credentials->username);
        $passwordLiteral = Value::toSurql($credentials->password);
        $this->rootConnection->run(
            "DEFINE USER OVERWRITE {$identifier} ON DATABASE PASSWORD {$passwordLiteral} ROLES {$role->value};",
        );

        return new DatabaseUserBootstrapEvidence($credentials->username, $role);
    }
}
