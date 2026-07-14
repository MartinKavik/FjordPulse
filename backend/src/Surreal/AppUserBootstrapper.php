<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class AppUserBootstrapper
{
    private DatabaseUserBootstrapper $users;

    public function __construct(SurrealConnection $rootConnection)
    {
        $this->users = new DatabaseUserBootstrapper($rootConnection);
    }

    public function bootstrap(
        string $username,
        #[\SensitiveParameter]
        string $password,
    ): DatabaseUserBootstrapEvidence
    {
        return $this->users->bootstrap(
            new DatabaseUserCredentials($username, $password),
            DatabaseUserRole::Editor,
        );
    }
}
