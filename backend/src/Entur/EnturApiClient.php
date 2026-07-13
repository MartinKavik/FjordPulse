<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use DateTimeImmutable;
use FjordPulse\Domain\EnturService;
use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Entur\Http\TransportInterface;
use JsonException;

final readonly class EnturApiClient
{
    public function __construct(
        private TransportInterface $transport,
        private RequestBudgetInterface $budget,
        private EnturRequestObserverInterface $observer,
        private string $clientName,
    ) {
        if (preg_match('/^[A-Za-z0-9_]+-[A-Za-z0-9_-]+$/D', $clientName) !== 1) {
            throw new \InvalidArgumentException('ENTUR_CLIENT_NAME must use company-application format.');
        }
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<mixed>
     */
    public function json(EnturService $service, string $method, string $url, string $scope, ?array $json = null): array
    {
        $requestId = 'entur_' . bin2hex(random_bytes(8));
        $started = hrtime(true);
        try {
            $this->budget->acquire($service, $requestId);
        } catch (RateLimited $exception) {
            $latencyMs = (int)round((hrtime(true) - $started) / 1_000_000);
            $this->observe($requestId, $service, $scope, null, $latencyMs, 'skipped_budget', $exception->retryAt, 'internal_budget');
            throw $exception;
        }

        try {
            $response = $this->transport->request($method, $url, [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'ET-Client-Name' => $this->clientName,
                'X-Correlation-Id' => $requestId,
            ], $json);
            $latencyMs = (int)round((hrtime(true) - $started) / 1_000_000);
            if ($response->status === 429) {
                $retrySeconds = max(1, (int)($response->header('Retry-After') ?? 60));
                $retryAt = (new DateTimeImmutable())->modify("+{$retrySeconds} seconds");
                $this->observe($requestId, $service, $scope, $response->status, $latencyMs, 'rate_limited', $retryAt, 'entur_429');
                throw new RateLimited($retryAt, 'Entur returned HTTP 429.');
            }
            if ($response->status < 200 || $response->status >= 300) {
                $this->observe($requestId, $service, $scope, $response->status, $latencyMs, 'error', null, 'entur_http_error');
                throw new SourceUnavailable("Entur {$service->value} returned HTTP {$response->status}.");
            }
            try {
                $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->observe($requestId, $service, $scope, $response->status, $latencyMs, 'error', null, 'invalid_json');
                throw new SourceUnavailable('Entur returned invalid JSON.', previous: $exception);
            }
            if (!is_array($decoded)) {
                throw new SourceUnavailable('Entur JSON root must be an object or array.');
            }
            $this->observe($requestId, $service, $scope, $response->status, $latencyMs, 'success', null, null, self::itemCount($decoded));

            return $decoded;
        } catch (RateLimited | SourceUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $latencyMs = (int)round((hrtime(true) - $started) / 1_000_000);
            $this->observe($requestId, $service, $scope, null, $latencyMs, 'error', null, 'transport_error');
            throw new SourceUnavailable("Entur {$service->value} request failed.", previous: $exception);
        }
    }

    private function observe(
        string $requestId,
        EnturService $service,
        string $scope,
        ?int $httpStatus,
        int $latencyMs,
        string $outcome,
        ?DateTimeImmutable $retryAt,
        ?string $errorCode,
        int $itemCount = 0,
    ): void {
        $this->observer->record(new EnturRequestLog(
            $requestId,
            $service->value,
            $scope,
            new DateTimeImmutable(),
            $httpStatus,
            $latencyMs,
            $itemCount,
            'miss',
            $outcome,
            $retryAt,
            $requestId,
            $errorCode,
        ));
    }

    /** @param array<mixed> $payload */
    private static function itemCount(array $payload): int
    {
        foreach (['features', 'stopPlaces', 'vehicles', 'estimatedCalls'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value) && array_is_list($value)) {
                return count($value);
            }
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                $count = self::itemCount($value);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        return 0;
    }
}
