<?php

declare(strict_types=1);

namespace FjordPulse\Http;

use Cake\Http\Response;

final class JsonErrorResponse
{
    /** @param array<string, mixed> $details */
    public static function create(
        int $status,
        string $code,
        string $message,
        array $details = [],
        ?string $requestId = null,
    ): Response {
        $body = json_encode([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => (object)$details],
            'meta' => ['requestId' => $requestId ?? 'req_' . bin2hex(random_bytes(8))],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (new Response())
            ->withStatus($status)
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody($body);
    }
}
