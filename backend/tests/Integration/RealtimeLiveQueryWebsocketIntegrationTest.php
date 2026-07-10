<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use Amp\Http\Client\HttpClientBuilder;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use DateTimeImmutable;
use FjordPulse\Domain\SourceState;
use FjordPulse\Domain\VehicleFreshness;
use FjordPulse\Dto\Coordinate;
use FjordPulse\Dto\StationSnapshot;
use FjordPulse\Dto\VehicleState;
use FjordPulse\Realtime\ActiveWatchRegistry;
use FjordPulse\Realtime\NullWatchRefreshHandler;
use FjordPulse\Realtime\ProtocolDecoder;
use FjordPulse\Realtime\ProtocolRouter;
use FjordPulse\Realtime\RealtimeService;
use FjordPulse\Realtime\RealtimeServiceConfig;
use FjordPulse\Realtime\RealtimeTelemetry;
use FjordPulse\Realtime\RoomEventSink;
use FjordPulse\Realtime\RoomRegistry;
use FjordPulse\Realtime\SignedRealtimeTokenVerifier;
use FjordPulse\Realtime\SurrealSnapshotProvider;
use FjordPulse\Realtime\SurrealWatchStore;
use FjordPulse\Realtime\WatchScheduler;
use FjordPulse\Security\SignedToken;
use FjordPulse\Surreal\SupervisedLiveQueryBridge;
use FjordPulse\Surreal\SurrealRepositories;
use Psr\Log\NullLogger;

use function Amp\delay;

final class RealtimeLiveQueryWebsocketIntegrationTest extends SurrealIntegrationTestCase
{
    public function testCanonicalWriteFlowsThroughDefineEventLiveQueryAndRoomToWebsocket(): void
    {
        [$factory] = $this->database('realtime_live_query_websocket');
        $commandConnection = $factory->ampCommand();
        $repositories = new SurrealRepositories($commandConnection);
        $logger = new NullLogger();
        $telemetry = new RealtimeTelemetry();
        $rooms = new RoomRegistry($telemetry, $logger);
        $registry = new ActiveWatchRegistry(new SurrealWatchStore($repositories->watches), 60);
        $snapshots = new SurrealSnapshotProvider(
            $repositories->stationSnapshots,
            $repositories->currentVehicles,
            $repositories->vehicleObservations,
        );
        $router = new ProtocolRouter(new ProtocolDecoder(), $rooms, $registry, $snapshots, $telemetry, $logger);
        $bridge = new SupervisedLiveQueryBridge($factory, $logger, minimumRetryDelay: 0.01, maximumRetryDelay: 0.05);
        $sink = new RoomEventSink($rooms, $logger);
        $tokens = new SignedToken('integration-realtime-secret');
        $port = self::reservePort();
        $service = new RealtimeService(
            new RealtimeServiceConfig(
                '127.0.0.1',
                $port,
                ['http://127.0.0.1:5173'],
                schedulerIntervalSeconds: 0.1,
                telemetryIntervalSeconds: 3_600,
            ),
            $rooms,
            $router,
            $telemetry,
            new WatchScheduler($registry, new NullWatchRefreshHandler(), $logger),
            $bridge,
            $sink,
            new SignedRealtimeTokenVerifier($tokens),
            $logger,
        );
        $client = null;

        try {
            $service->start();
            for ($attempt = 0; $attempt < 200 && !$bridge->status()->healthy(); $attempt++) {
                delay(0.01);
            }
            self::assertTrue($bridge->status()->healthy(), 'Live-query bridge did not become healthy.');

            $token = $tokens->issue(['clientId' => 'live-query-browser'], 60, 'realtime');
            $client = self::websocket($port, $token);
            $client->sendText(json_encode([
                'protocolVersion' => 1,
                'id' => 'watch-live-query',
                'type' => 'watch_station',
                'payload' => ['stationId' => 'NSR:StopPlace:548'],
            ], JSON_THROW_ON_ERROR));
            self::assertSame('watch_station_ack', self::message($client)['type']);
            self::assertSame('error', self::message($client)['type']);

            $version = '2026-07-10T10:05:00.000Z';
            $repositories->stationSnapshots->save(new StationSnapshot(
                'NSR:StopPlace:548',
                $version,
                hash('sha256', 'live-query-websocket-state'),
                new DateTimeImmutable($version),
                SourceState::Empty,
                [],
                [],
                new DateTimeImmutable($version),
            ));

            $event = self::message($client);
            self::assertSame('station_snapshot_changed', $event['type']);
            self::assertSame('station:NSR:StopPlace:548', $event['scope']);
            self::assertSame('NSR:StopPlace:548', $event['entityId']);
            self::assertSame($version, $event['version']);
            self::assertIsString($event['eventId']);
            self::assertNotSame('', $event['eventId']);

            $vehicleId = 'SKY:Vehicle:live-query-1';
            $client->sendText(json_encode([
                'protocolVersion' => 1,
                'id' => 'watch-live-vehicle',
                'type' => 'watch_vehicle',
                'payload' => ['vehicleId' => $vehicleId],
            ], JSON_THROW_ON_ERROR));
            self::assertSame('watch_vehicle_ack', self::message($client)['type']);
            self::assertSame('error', self::message($client)['type']);

            $vehicleVersion = '2026-07-10T10:05:01.000Z';
            $repositories->currentVehicles->save(new VehicleState(
                $vehicleId,
                $vehicleVersion,
                hash('sha256', 'live-query-websocket-vehicle'),
                VehicleFreshness::Live,
                new Coordinate(61.452, 5.857),
                '100',
                'Førde–Nordfjordeid',
                'Nordfjordeid',
                42.0,
                60,
                null,
                new DateTimeImmutable($vehicleVersion),
                new DateTimeImmutable($vehicleVersion),
                null,
            ));

            $vehicleEvent = self::message($client);
            self::assertSame('vehicle_moved', $vehicleEvent['type']);
            self::assertSame('vehicle:' . $vehicleId, $vehicleEvent['scope']);
            self::assertSame($vehicleVersion, $vehicleEvent['version']);
            $payload = $vehicleEvent['payload'] ?? null;
            self::assertIsArray($payload);
            $vehiclePayload = $payload['vehicle'] ?? null;
            self::assertIsArray($vehiclePayload);
            self::assertArrayHasKey('nextStop', $vehiclePayload);
            self::assertNull($vehiclePayload['nextStop']);
        } finally {
            $client?->close();
            $service->stop();
            $commandConnection->close();
        }
    }

    private static function websocket(int $port, string $token): WebsocketConnection
    {
        $handshake = (new WebsocketHandshake('ws://127.0.0.1:' . $port . '/live?token=' . rawurlencode($token)))
            ->withHeader('Origin', 'http://127.0.0.1:5173');

        return (new Rfc6455Connector(httpClient: (new HttpClientBuilder())->build()))->connect($handshake);
    }

    /** @return array<string, mixed> */
    private static function message(WebsocketConnection $client): array
    {
        $message = $client->receive(new TimeoutCancellation(5));
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

    private static function reservePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($server)) {
            throw new \RuntimeException("Unable to reserve realtime integration port: {$errorCode} {$errorMessage}");
        }
        $address = stream_socket_get_name($server, false);
        fclose($server);
        if (!is_string($address) || preg_match('/:(\d+)$/D', $address, $matches) !== 1) {
            throw new \RuntimeException('Unable to determine realtime integration port.');
        }

        return (int)$matches[1];
    }
}
