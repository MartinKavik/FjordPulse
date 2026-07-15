<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Service\HttpApiService;
use FjordPulse\Service\HttpApiServiceFactory;
use JsonException;

abstract class AppController extends Controller
{
    public function initialize(): void
    {
        parent::initialize();
        $this->disableAutoRender();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    final protected function success(array $data, array $meta = [], int $status = 200): Response
    {
        $requestId = $this->getRequest()->getAttribute('requestId');

        return $this->json([
            'ok' => true,
            'data' => $data,
            'meta' => [
                'requestId' => is_string($requestId) ? $requestId : self::requestId(),
                'updatedAt' => self::now(),
                ...$meta,
            ],
        ], $status);
    }

    /**
     * @param array<string, mixed> $details
     */
    final protected function failure(string $code, string $message, array $details, int $status): Response
    {
        $requestId = $this->getRequest()->getAttribute('requestId');

        return $this->json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object)$details,
            ],
            'meta' => [
                'requestId' => is_string($requestId) ? $requestId : self::requestId(),
            ],
        ], $status);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws JsonException
     */
    private function json(array $payload, int $status): Response
    {
        return $this->getResponse()
            ->withStatus($status)
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function requestId(): string
    {
        return 'req_' . bin2hex(random_bytes(8));
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z');
    }

    final protected function serviceFactory(): HttpApiServiceFactory
    {
        return new HttpApiServiceFactory(RuntimeConfig::fromEnvironment());
    }

    final protected function openService(?HttpApiServiceFactory $factory = null): HttpApiService
    {
        return ($factory ?? $this->serviceFactory())->create();
    }
}
