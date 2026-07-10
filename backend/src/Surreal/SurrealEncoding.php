<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

/**
 * The pinned alpha SDK uses SurrealDB's plain JSON RPC codec. SurrealDB may
 * interpret colon-shaped JSON strings as record IDs, so application strings
 * are bound as base64 and decoded explicitly in SurrealQL. Structured values
 * are bound as JSON text and decoded server-side for the same reason.
 */
final class SurrealEncoding
{
    private function __construct()
    {
    }

    public static function string(string $value): string
    {
        return base64_encode($value);
    }

    public static function nullableString(?string $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
