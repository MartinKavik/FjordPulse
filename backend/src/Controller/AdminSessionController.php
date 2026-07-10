<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Cookie\Cookie;
use Cake\Http\Response;
use DateTimeImmutable;
use DateTimeInterface;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Middleware\AdminAuthMiddleware;
use FjordPulse\Security\SignedToken;

final class AdminSessionController extends AppController
{
    public function create(): Response
    {
        $config = RuntimeConfig::fromEnvironment();
        $data = $this->getRequest()->getData();
        if (!is_array($data)) {
            $data = [];
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['password', 'username']
            || !is_string($data['username'] ?? null)
            || mb_strlen($data['username']) < 1
            || mb_strlen($data['username']) > 200
            || !is_string($data['password'] ?? null)
            || strlen($data['password']) < 1
            || strlen($data['password']) > 1_024) {
            return $this->failure(
                'invalid_login_request',
                'Login requires only non-empty username and password fields.',
                ['field' => 'body'],
                400,
            );
        }
        $username = $data['username'];
        $password = $data['password'];
        if (!hash_equals($config->adminUsername, $username) || !hash_equals($config->adminPassword, $password)) {
            return $this->failure('invalid_credentials', 'Admin credentials are invalid.', [], 401);
        }
        $ttl = 28_800;
        $expires = (new DateTimeImmutable())->modify("+{$ttl} seconds");
        $token = (new SignedToken($config->adminSessionSecret))->issue(['username' => $username], $ttl, 'admin-session');
        $cookie = new Cookie(
            AdminAuthMiddleware::COOKIE,
            $token,
            $expires,
            '/',
            '',
            $config->environment === 'production',
            true,
            'Strict',
        );

        return $this->success([
            'authenticated' => true,
            'username' => $username,
            'expiresAt' => $expires->format(DateTimeInterface::RFC3339_EXTENDED),
        ])->withCookie($cookie);
    }

    public function view(): Response
    {
        $claims = $this->getRequest()->getAttribute('adminClaims');
        if (!is_array($claims) || !is_string($claims['username'] ?? null) || !is_int($claims['expiresAt'] ?? null)) {
            return $this->failure('admin_unauthorized', 'Admin authentication is required.', [], 401);
        }

        return $this->success([
            'authenticated' => true,
            'username' => $claims['username'],
            'expiresAt' => gmdate('Y-m-d\\TH:i:s\\Z', $claims['expiresAt']),
        ]);
    }

    public function delete(): Response
    {
        $expired = (new Cookie(AdminAuthMiddleware::COOKIE, '', new DateTimeImmutable('-1 day'), '/', '', false, true, 'Strict'));

        return (new Response())
            ->withStatus(204)
            ->withCookie($expired)
            ->withHeader('Cache-Control', 'no-store');
    }
}
