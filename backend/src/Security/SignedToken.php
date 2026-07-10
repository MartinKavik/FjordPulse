<?php

declare(strict_types=1);

namespace FjordPulse\Security;

use JsonException;

final readonly class SignedToken
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('Signed-token secret must contain at least 16 characters.');
        }
    }

    /** @param array<string, mixed> $claims */
    public function issue(array $claims, int $ttlSeconds, string $purpose): string
    {
        $payload = [
            ...$claims,
            'purpose' => $purpose,
            'issuedAt' => time(),
            'expiresAt' => time() + $ttlSeconds,
            'nonce' => bin2hex(random_bytes(12)),
        ];
        $encoded = self::encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = self::encode(hash_hmac('sha256', $encoded, $this->secret, true));

        return $encoded . '.' . $signature;
    }

    /** @return array<string, mixed>|null */
    public function verify(string $token, string $purpose): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $signature] = $parts;
        $expected = self::encode(hash_hmac('sha256', $encoded, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $json = self::decode($encoded);
        if ($json === null) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded) || ($decoded['purpose'] ?? null) !== $purpose
            || !is_int($decoded['expiresAt'] ?? null) || $decoded['expiresAt'] < time()) {
            return null;
        }

        $claims = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $claims[$key] = $value;
        }

        return $claims;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
