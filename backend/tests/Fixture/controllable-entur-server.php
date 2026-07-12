<?php

declare(strict_types=1);

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Psr\Log\NullLogger;

use function Amp\delay;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = getenv('FJORDPULSE_ENTUR_FAKE_PORT');
$statePath = getenv('FJORDPULSE_ENTUR_FAKE_STATE');
$logPath = getenv('FJORDPULSE_ENTUR_FAKE_LOG');
$shutdownPath = getenv('FJORDPULSE_ENTUR_FAKE_SHUTDOWN');
if (!is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65_535
    || !is_string($statePath) || $statePath === ''
    || !is_string($logPath) || $logPath === ''
    || !is_string($shutdownPath) || $shutdownPath === '') {
    throw new RuntimeException('Controlled Entur server configuration is incomplete.');
}

$handler = new class($statePath, $logPath) implements RequestHandler {
    public function __construct(private readonly string $statePath, private readonly string $logPath)
    {
    }

    public function handleRequest(Request $request): Response
    {
        $body = $request->getBody()->buffer(limit: 1_000_000);
        $payload = json_decode($body, true);
        $payload = is_array($payload) ? $payload : [];
        $clientName = $request->getHeader('ET-Client-Name');
        $entry = json_encode([
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'clientName' => $clientName,
            'query' => $payload['query'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);

        if ($clientName !== 'martinkavik-fjordpulse-recovery-test') {
            return $this->json(HttpStatus::BAD_REQUEST, ['error' => 'missing_client_identity']);
        }
        $state = json_decode((string)file_get_contents($this->statePath), true);
        if (!is_array($state) || ($state['available'] ?? false) !== true) {
            return $this->json(HttpStatus::SERVICE_UNAVAILABLE, ['error' => 'controlled_entur_outage']);
        }

        $query = is_string($payload['query'] ?? null) ? $payload['query'] : '';
        if (str_contains($query, 'query StationBoard') || str_contains($query, 'query Departures')) {
            $aimed = gmdate(DATE_RFC3339, time() + 600);
            $expected = gmdate(DATE_RFC3339, time() + 660);
            $departure = [
                'aimedDepartureTime' => $aimed,
                'expectedDepartureTime' => $expected,
                'actualDepartureTime' => null,
                'cancellation' => false,
                'date' => gmdate('Y-m-d'),
                'quay' => ['id' => 'NSR:Quay:recovery', 'publicCode' => 'A'],
                'destinationDisplay' => ['frontText' => 'Recovered service'],
                'serviceJourney' => [
                    'id' => 'ENT:ServiceJourney:recovered',
                    'journeyPattern' => ['line' => [
                        'id' => 'ENT:Line:55',
                        'publicCode' => '55',
                        'name' => 'Recovery line',
                    ]],
                ],
            ];
            $calls = str_contains($query, 'query StationBoard')
                ? [
                    'departureCalls' => [$departure],
                    'recentVehicleCalls' => [],
                    'upcomingVehicleCalls' => [],
                ]
                : ['estimatedCalls' => [$departure]];

            return $this->json(HttpStatus::OK, ['data' => ['stopPlace' => $calls]]);
        }
        if (str_contains($query, 'query StationVehicles')) {
            return $this->json(HttpStatus::OK, ['data' => ['nearby' => []]]);
        }
        if (str_contains($query, 'query Nearby')) {
            return $this->json(HttpStatus::OK, ['data' => ['vehicles' => []]]);
        }

        return $this->json(HttpStatus::BAD_REQUEST, ['error' => 'unexpected_query']);
    }

    /** @param array<string, mixed> $payload */
    private function json(int $status, array $payload): Response
    {
        return new Response(
            $status,
            ['content-type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
};

$server = SocketHttpServer::createForDirectAccess(new NullLogger());
$server->expose(new InternetAddress('127.0.0.1', (int)$port));
$server->start($handler, new DefaultErrorHandler());
while (!is_file($shutdownPath)) {
    delay(0.02);
}
$server->stop();
