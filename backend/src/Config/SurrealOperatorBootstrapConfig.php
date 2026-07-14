<?php

declare(strict_types=1);

namespace FjordPulse\Config;

use FjordPulse\Surreal\DatabaseUserCredentials;
use InvalidArgumentException;

final readonly class SurrealOperatorBootstrapConfig
{
    private const int PRODUCTION_PASSWORD_MIN_BYTES = 32;

    public function __construct(public DatabaseUserCredentials $credentials)
    {
    }

    public static function fromEnvironment(
        RuntimeConfig $runtime,
        string $rootUsername,
        #[\SensitiveParameter]
        string $rootPassword,
    ): ?self {
        $username = self::optionalEnvironment('SURREAL_OPERATOR_USERNAME');
        $password = self::optionalEnvironment('SURREAL_OPERATOR_PASSWORD');

        if ($username === null && $password === null) {
            if ($runtime->environment === 'production') {
                throw new InvalidArgumentException(
                    'Production migrations require SURREAL_OPERATOR_USERNAME and SURREAL_OPERATOR_PASSWORD.',
                );
            }

            return null;
        }

        if ($username === null || $password === null) {
            throw new InvalidArgumentException(
                'SURREAL_OPERATOR_USERNAME and SURREAL_OPERATOR_PASSWORD must be configured together.',
            );
        }

        $credentials = new DatabaseUserCredentials($username, $password);
        if (
            $runtime->environment === 'production'
            && strlen($credentials->password) < self::PRODUCTION_PASSWORD_MIN_BYTES
        ) {
            throw new InvalidArgumentException(
                'Production SURREAL_OPERATOR_PASSWORD must contain at least 32 bytes.',
            );
        }

        if (
            hash_equals($runtime->surreal->username, $credentials->username)
            || hash_equals($rootUsername, $credentials->username)
        ) {
            throw new InvalidArgumentException(
                'The SurrealDB operator must use a username distinct from application and root users.',
            );
        }
        if (hash_equals($credentials->username, $credentials->password)) {
            throw new InvalidArgumentException(
                'The SurrealDB operator password must differ from its username.',
            );
        }

        $reservedSecrets = [
            $runtime->surreal->password,
            $rootPassword,
            $runtime->adminPassword,
            $runtime->adminSessionSecret,
        ];
        if ($runtime->adminDemoAccess) {
            $reservedSecrets[] = $runtime->adminDemoPassword;
        }
        foreach ($reservedSecrets as $reservedSecret) {
            if (hash_equals($reservedSecret, $credentials->password)) {
                throw new InvalidArgumentException(
                    'The SurrealDB operator password must not reuse an application, root, or Admin secret.',
                );
            }
        }

        return new self($credentials);
    }

    private static function optionalEnvironment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
