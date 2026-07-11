<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use InvalidArgumentException;

final readonly class SurrealConnectionConfig
{
    public function __construct(
        public string $httpUrl,
        public string $webSocketUrl,
        public string $namespace,
        public string $database,
        public string $username,
        public string $password,
    ) {
        self::assertUrl($httpUrl, ['http', 'https'], 'HTTP');
        self::assertUrl($webSocketUrl, ['ws', 'wss'], 'WebSocket');

        foreach (['namespace' => $namespace, 'database' => $database, 'username' => $username, 'password' => $password] as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException("SurrealDB {$name} must not be empty.");
            }
        }
    }

    /** @param list<string> $schemes */
    private static function assertUrl(string $url, array $schemes, string $kind): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($scheme) || !in_array(strtolower($scheme), $schemes, true) || !is_string($host) || $host === '') {
            throw new InvalidArgumentException("SurrealDB {$kind} URL has an invalid origin.");
        }
    }
}
