<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Http\Client\HttpClientBuilder;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use DateTimeImmutable;
use FjordPulse\Domain\RealtimeType;
use FjordPulse\Dto\RealtimeEvent;
use FjordPulse\Realtime\ActiveWatchRegistry;
use FjordPulse\Realtime\NullWatchRefreshHandler;
use FjordPulse\Realtime\NullWatchStore;
use FjordPulse\Realtime\ProtocolDecoder;
use FjordPulse\Realtime\ProtocolRouter;
use FjordPulse\Realtime\RealtimeEventSink;
use FjordPulse\Realtime\RealtimeService;
use FjordPulse\Realtime\RealtimeServiceConfig;
use FjordPulse\Realtime\RealtimeTelemetry;
use FjordPulse\Realtime\RoomEventSink;
use FjordPulse\Realtime\RoomRegistry;
use FjordPulse\Realtime\SignedRealtimeTokenVerifier;
use FjordPulse\Realtime\WatchScheduler;
use FjordPulse\Security\SignedToken;
use FjordPulse\Surreal\LiveQueryBridge;
use FjordPulse\Surreal\LiveQueryBridgeState;
use FjordPulse\Surreal\LiveQueryBridgeStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function Amp\delay;

final class RealtimeWebsocketIntegrationTest extends TestCase
{
    public function testMultipleClientsPingInvalidMessageRoomIsolationAndGracefulShutdown(): void
    {
        $port = self::availablePort();
        [$service, $tokens, $sink] = self::service($port);
        $service->start();
        $first = null;
        $second = null;

        try {
            $first = self::connect($port, $tokens->issue(['clientId' => 'browser-a'], 60, 'realtime'));
            $second = self::connect($port, $tokens->issue(['clientId' => 'browser-b'], 60, 'realtime'));

            $first->sendText(self::command('ping-a', 'ping', ['sentAt' => '2026-07-10T10:00:00Z']));
            self::assertSame('pong', self::receive($first)['type']);
            $first->sendText('{"protocolVersion":1,"id":"bad-a","type":"watch_everything","payload":{}}');
            $error = self::receive($first);
            self::assertSame('error', $error['type']);
            self::assertSame('unknown_message_type', self::errorCode($error));

            $first->sendText(self::command('watch-a', 'watch_station', ['stationId' => 'NSR:StopPlace:548']));
            self::assertSame('watch_station_ack', self::receive($first)['type']);
            self::assertSame('station_snapshot', self::receive($first)['type']);
            $second->sendText(self::command('watch-b', 'watch_station', ['stationId' => 'NSR:StopPlace:337']));
            self::assertSame('watch_station_ack', self::receive($second)['type']);
            self::assertSame('station_snapshot', self::receive($second)['type']);
            self::assertSame([
                ['scope' => 'station:NSR:StopPlace:337', 'clientCount' => 1],
                ['scope' => 'station:NSR:StopPlace:548', 'clientCount' => 1],
            ], $service->health()['roomDetails']);

            $sink->publish(new RealtimeEvent(
                'evt_ws_station',
                'NSR:StopPlace:548',
                'station:NSR:StopPlace:548',
                RealtimeType::StationSnapshotChanged,
                '2026-07-10T10:02:00Z',
                new DateTimeImmutable('2026-07-10T10:02:00Z'),
                [
                    'stationId' => 'NSR:StopPlace:548',
                    'state' => 'empty',
                    'version' => '2026-07-10T10:02:00Z',
                    'updatedAt' => '2026-07-10T10:02:00Z',
                    'departures' => [],
                    'departureBoard' => ['windowStart' => '2026-07-10T10:02:00Z', 'windowEnd' => '2026-07-11T00:00:00+02:00', 'limit' => 20, 'hasMore' => false],
                    'nearbyVehicles' => [],
                    'servingVehicles' => [],
                    'servingVehicleCoverage' => ['windowStart' => null, 'windowEnd' => null, 'candidateJourneyCount' => 0, 'queriedJourneyCount' => 0, 'truncated' => false],
                ],
            ));
            self::assertSame('station_snapshot_changed', self::receive($first)['type']);

            try {
                $second->receive(new TimeoutCancellation(0.05));
                self::fail('Unrelated room subscriber received the event.');
            } catch (CancelledException) {
                // Expected: no frame is available for the unrelated room.
            }

            $service->stop();
            self::assertTrue($first->isClosed());
            self::assertTrue($second->isClosed());
        } finally {
            $first?->close();
            $second?->close();
            $service->stop();
        }
    }

    public function testHandshakeRejectsUnauthorizedOriginAndMissingToken(): void
    {
        $port = self::availablePort();
        [$service, $tokens] = self::service($port);
        $service->start();
        try {
            $token = $tokens->issue(['clientId' => 'browser-bad-origin'], 60, 'realtime');
            $handshakes = [
                (new WebsocketHandshake('ws://127.0.0.1:' . $port . '/live?token=' . rawurlencode($token)))
                    ->withHeader('Origin', 'https://attacker.invalid'),
                (new WebsocketHandshake('ws://127.0.0.1:' . $port . '/live'))
                    ->withHeader('Origin', 'http://127.0.0.1:5173'),
            ];
            foreach ($handshakes as $handshake) {
                try {
                    (new Rfc6455Connector(httpClient: (new HttpClientBuilder())->build()))->connect($handshake);
                    self::fail('Protected realtime handshake unexpectedly succeeded.');
                } catch (WebsocketConnectException $error) {
                    self::assertContains($error->getResponse()->getStatus(), [401, 403]);
                }
            }
        } finally {
            $service->stop();
        }
    }

    public function testHealthyBridgeStatusIsPublishedBeforeThePeriodicTelemetryInterval(): void
    {
        $port = self::availablePort();
        $statuses = [];
        [$service] = self::service($port, static function (array $health) use (&$statuses): void {
            $statuses[] = $health['status'] ?? null;
        });
        $service->start();
        try {
            $deadline = microtime(true) + 2.0;
            while (!in_array('healthy', $statuses, true) && microtime(true) < $deadline) {
                delay(0.05);
            }
            self::assertContains('healthy', $statuses);
        } finally {
            $service->stop();
        }
    }

    /**
     * @param (\Closure(array<string, mixed>): void)|null $statusSink
     * @return array{RealtimeService, SignedToken, RealtimeEventSink}
     */
    private static function service(int $port, ?\Closure $statusSink = null): array
    {
        $logger = new NullLogger();
        $telemetry = new RealtimeTelemetry();
        $rooms = new RoomRegistry($telemetry, $logger);
        $registry = new ActiveWatchRegistry(new NullWatchStore(), 2);
        $router = new ProtocolRouter(
            new ProtocolDecoder(),
            $rooms,
            $registry,
            new FixedSnapshotProvider(),
            $telemetry,
            $logger,
        );
        $scheduler = new WatchScheduler($registry, new NullWatchRefreshHandler(), $logger);
        $bridge = new TestLiveQueryBridge();
        $sink = new RoomEventSink($rooms, $logger);
        $tokens = new SignedToken('test-realtime-secret-value');
        $service = new RealtimeService(
            new RealtimeServiceConfig(
                '127.0.0.1',
                $port,
                ['http://127.0.0.1:5173'],
                schedulerIntervalSeconds: 0.05,
                telemetryIntervalSeconds: 3_600,
            ),
            $rooms,
            $router,
            $telemetry,
            $scheduler,
            $bridge,
            $sink,
            new SignedRealtimeTokenVerifier($tokens),
            $logger,
            $statusSink,
        );

        return [$service, $tokens, $sink];
    }

    private static function connect(int $port, string $token): WebsocketConnection
    {
        $handshake = (new WebsocketHandshake('ws://127.0.0.1:' . $port . '/live?token=' . rawurlencode($token)))
            ->withHeader('Origin', 'http://127.0.0.1:5173');

        return (new Rfc6455Connector(httpClient: (new HttpClientBuilder())->build()))->connect($handshake);
    }

    /** @param array<string, string> $payload */
    private static function command(string $id, string $type, array $payload): string
    {
        return json_encode(['protocolVersion' => 1, 'id' => $id, 'type' => $type, 'payload' => $payload], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function receive(WebsocketConnection $connection): array
    {
        $message = $connection->receive(new TimeoutCancellation(2));
        self::assertNotNull($message);
        $decoded = json_decode($message->buffer(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private static function availablePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($server)) {
            throw new \RuntimeException("Unable to reserve test port: {$errorCode} {$errorMessage}");
        }
        $address = stream_socket_get_name($server, false);
        fclose($server);
        if (!is_string($address) || preg_match('/:(\d+)$/D', $address, $matches) !== 1) {
            throw new \RuntimeException('Unable to determine reserved test port.');
        }

        return (int)$matches[1];
    }

    /** @param array<string, mixed> $message */
    private static function errorCode(array $message): string
    {
        $error = $message['error'] ?? null;
        if (!is_array($error) || !is_string($error['code'] ?? null)) {
            throw new \RuntimeException('Realtime test expected a structured error code.');
        }

        return $error['code'];
    }
}

final class TestLiveQueryBridge implements LiveQueryBridge
{
    private LiveQueryBridgeState $state = LiveQueryBridgeState::Stopped;
    private bool $stopping = false;

    public function run(\Closure $onEvent, ?\Closure $onRecovery = null, ?Cancellation $cancellation = null): void
    {
        unset($onEvent, $onRecovery);
        $this->state = LiveQueryBridgeState::Healthy;
        try {
            while (!$this->stopping) {
                $cancellation?->throwIfRequested();
                delay(0.01, cancellation: $cancellation);
            }
        } catch (CancelledException) {
            // Graceful cancellation is the expected stop path.
        } finally {
            $this->state = LiveQueryBridgeState::Stopped;
        }
    }

    public function stop(): void
    {
        $this->stopping = true;
        $this->state = LiveQueryBridgeState::Stopping;
    }

    public function status(): LiveQueryBridgeStatus
    {
        return new LiveQueryBridgeStatus(
            $this->state,
            $this->state === LiveQueryBridgeState::Healthy ? 'test-query' : null,
            null,
            null,
            null,
            null,
            null,
            0,
            $this->state === LiveQueryBridgeState::Healthy ? 1 : 0,
        );
    }
}
