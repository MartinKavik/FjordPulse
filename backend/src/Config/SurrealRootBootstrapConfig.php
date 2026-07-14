<?php

declare(strict_types=1);

namespace FjordPulse\Config;

use FjordPulse\Surreal\DatabaseUserCredentials;
use InvalidArgumentException;

final readonly class SurrealRootBootstrapConfig
{
    private const int PRODUCTION_PASSWORD_MIN_BYTES = 32;

    public function __construct(public DatabaseUserCredentials $credentials)
    {
    }

    public static function fromEnvironment(RuntimeConfig $runtime): self
    {
        $credentials = new DatabaseUserCredentials(
            self::environment('SURREAL_ROOT_USERNAME', 'root'),
            self::environment('SURREAL_ROOT_PASSWORD', 'root'),
        );
        if ($runtime->environment !== 'production') {
            return new self($credentials);
        }

        if (strlen($credentials->password) < self::PRODUCTION_PASSWORD_MIN_BYTES) {
            throw new InvalidArgumentException(
                'Production SURREAL_ROOT_PASSWORD must contain at least 32 bytes.',
            );
        }

        $reservedUsernames = [
            $runtime->surreal->username,
            $runtime->adminUsername,
        ];
        $operatorUsername = self::optionalEnvironment('SURREAL_OPERATOR_USERNAME');
        if ($operatorUsername !== null) {
            $reservedUsernames[] = $operatorUsername;
        }
        if ($runtime->adminDemoAccess) {
            $reservedUsernames[] = $runtime->adminDemoUsername;
        }
        foreach ($reservedUsernames as $reservedUsername) {
            if (hash_equals($reservedUsername, $credentials->username)) {
                throw new InvalidArgumentException(
                    'Production SurrealDB root username must be distinct from application, viewer, and Admin identities.',
                );
            }
        }

        $reservedSecrets = [
            $credentials->username,
            $runtime->surreal->password,
            $runtime->adminPassword,
            $runtime->adminSessionSecret,
        ];
        $operatorPassword = self::optionalEnvironment('SURREAL_OPERATOR_PASSWORD');
        if ($operatorPassword !== null) {
            $reservedSecrets[] = $operatorPassword;
        }
        if ($runtime->adminDemoAccess) {
            $reservedSecrets[] = $runtime->adminDemoPassword;
        }
        foreach ($reservedSecrets as $reservedSecret) {
            if (hash_equals($reservedSecret, $credentials->password)) {
                throw new InvalidArgumentException(
                    'Production SurrealDB root password must not reuse a database or Admin credential.',
                );
            }
        }

        return new self($credentials);
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function optionalEnvironment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
