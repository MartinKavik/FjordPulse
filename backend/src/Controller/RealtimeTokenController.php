<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Security\SignedToken;

final class RealtimeTokenController extends AppController
{
    public function create(): Response
    {
        $config = RuntimeConfig::fromEnvironment();
        $data = $this->getRequest()->getData();
        if (!is_array($data)) {
            $data = [];
        }
        $clientId = is_string($data['clientId'] ?? null) ? $data['clientId'] : 'browser_' . bin2hex(random_bytes(8));
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $clientId) !== 1) {
            return $this->failure('invalid_client', 'Client id is invalid.', ['field' => 'clientId'], 400);
        }
        $ttl = 300;
        $token = (new SignedToken($config->adminSessionSecret))->issue(['clientId' => $clientId], $ttl, 'realtime');
        $expiresAt = (new DateTimeImmutable())->modify("+{$ttl} seconds");
        $configuredUrl = getenv('REALTIME_PUBLIC_URL');
        $webSocketUrl = is_string($configuredUrl) && $configuredUrl !== ''
            ? $configuredUrl
            : preg_replace('/^http/', 'ws', rtrim($config->appOrigin, '/')) . '/live';
        if (preg_match('#^wss?://#D', $webSocketUrl) !== 1) {
            throw new \RuntimeException('Realtime public URL is not configured as ws:// or wss://.');
        }
        if ($config->environment === 'production' && !str_starts_with($webSocketUrl, 'wss://')) {
            throw new \RuntimeException('Production realtime public URL must use wss://.');
        }

        return $this->success([
            'token' => $token,
            'expiresAt' => $expiresAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'webSocketUrl' => $webSocketUrl,
            'protocolVersion' => 1,
        ], status: 201);
    }
}
