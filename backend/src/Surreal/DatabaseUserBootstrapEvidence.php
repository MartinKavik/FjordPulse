<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class DatabaseUserBootstrapEvidence
{
    public function __construct(
        public string $username,
        public DatabaseUserRole $role,
    ) {
    }

    /** @return array{username: string, role: string, bootstrapped: true} */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'role' => $this->role->value,
            'bootstrapped' => true,
        ];
    }
}
