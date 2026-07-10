<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Security\SignedToken;

final readonly class SignedRealtimeTokenVerifier implements TokenVerifier
{
    public function __construct(private SignedToken $tokens)
    {
    }

    public function verify(string $token): ?array
    {
        return $this->tokens->verify($token, 'realtime');
    }
}
