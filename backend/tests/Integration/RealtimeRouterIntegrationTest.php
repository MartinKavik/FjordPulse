<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use DateTimeImmutable;
use FjordPulse\Domain\RealtimeType;
use FjordPulse\Dto\RealtimeEvent;
use FjordPulse\Realtime\ActiveWatchRegistry;
use FjordPulse\Realtime\AuthoritativeSnapshot;
use FjordPulse\Realtime\ClientConnection;
use FjordPulse\Realtime\ClientSession;
use FjordPulse\Realtime\MessageRateLimiter;
use FjordPulse\Realtime\NullWatchStore;
use FjordPulse\Realtime\ProtocolDecoder;
use FjordPulse\Realtime\ProtocolRouter;
use FjordPulse\Realtime\RealtimeTelemetry;
use FjordPulse\Realtime\RoomEventSink;
use FjordPulse\Realtime\RoomRegistry;
use FjordPulse\Realtime\SnapshotProvider;
use PHPUnit\Framework\TestCase;

final class RealtimeRouterIntegrationTest extends TestCase
{
    public function testMultipleClientsPingValidationAndRoomIsolation(): void
    {
        [$router, $rooms, $sink] = self::system();
        $first = new RecordingConnection(1);
        $second = new RecordingConnection(2);
        $rooms->connect($first);
        $rooms->connect($second);
        $firstSession = new ClientSession(1, 'client-1', [], new MessageRateLimiter(100, 10));
        $secondSession = new ClientSession(2, 'client-2', [], new MessageRateLimiter(100, 10));

        $router->handle($firstSession, self::command('a1', 'watch_station', ['stationId' => 'NSR:StopPlace:548']));
        $router->handle($secondSession, self::command('b1', 'watch_station', ['stationId' => 'NSR:StopPlace:337']));
        self::assertSame(['watch_station_ack', 'station_snapshot'], $first->types());
        self::assertSame(['watch_station_ack', 'station_snapshot'], $second->types());

        $sink->publish(new RealtimeEvent(
            'evt_station',
            'NSR:StopPlace:548',
            'station:NSR:StopPlace:548',
            RealtimeType::StationSnapshotChanged,
            '2026-07-10T10:01:00Z',
            new DateTimeImmutable('2026-07-10T10:01:00Z'),
            [
                'stationId' => 'NSR:StopPlace:548',
                'state' => 'empty',
                'version' => '2026-07-10T10:01:00Z',
                'updatedAt' => '2026-07-10T10:01:00Z',
                'departures' => [],
                'nearbyVehicles' => [],
            ],
        ));
        self::assertSame('station_snapshot_changed', $first->lastType());
        self::assertSame('station_snapshot', $second->lastType());

        $router->handle($secondSession, '{"protocolVersion":1,"id":"bad","type":"unknown","payload":{}}');
        self::assertSame('error', $second->lastType());
        self::assertSame('unknown_message_type', $second->lastErrorCode());
        $router->handle($secondSession, self::command('ping-1', 'ping', ['sentAt' => '2026-07-10T10:00:00Z']));
        self::assertSame('pong', $second->lastType());
        self::assertFalse($second->closed());
    }

    public function testFocusLifecycleMaintainsVehicleRoomUntilUnfocus(): void
    {
        [$router, $rooms] = self::system();
        $client = new RecordingConnection(7);
        $rooms->connect($client);
        $session = new ClientSession(7, 'client-7', [], new MessageRateLimiter(100, 10));
        $vehicleId = 'SKY:Vehicle:12345';

        $router->handle($session, self::command('f1', 'focus_vehicle', ['vehicleId' => $vehicleId]));
        self::assertContains('focus:client-7:' . $vehicleId, $rooms->scopesFor(7));
        self::assertContains('vehicle:' . $vehicleId, $rooms->scopesFor(7));
        $router->handle($session, self::command('f2', 'pause_focus', ['vehicleId' => $vehicleId]));
        $router->handle($session, self::command('f3', 'resume_focus', ['vehicleId' => $vehicleId]));
        $router->handle($session, self::command('f4', 'unfocus_vehicle', ['vehicleId' => $vehicleId]));

        self::assertSame(['focus_started', 'vehicle_snapshot', 'focus_paused', 'focus_resumed', 'vehicle_snapshot', 'focus_stopped'], $client->types());
        self::assertNotContains('vehicle:' . $vehicleId, $rooms->scopesFor(7));
    }

    /** @return array{ProtocolRouter, RoomRegistry, RoomEventSink} */
    private static function system(): array
    {
        $telemetry = new RealtimeTelemetry();
        $rooms = new RoomRegistry($telemetry);
        $router = new ProtocolRouter(
            new ProtocolDecoder(),
            $rooms,
            new ActiveWatchRegistry(new NullWatchStore()),
            new FixedSnapshotProvider(),
            $telemetry,
        );

        return [$router, $rooms, new RoomEventSink($rooms)];
    }

    /** @param array<string, string> $payload */
    private static function command(string $id, string $type, array $payload): string
    {
        return json_encode(['protocolVersion' => 1, 'id' => $id, 'type' => $type, 'payload' => $payload], JSON_THROW_ON_ERROR);
    }
}

final class RecordingConnection implements ClientConnection
{
    /** @var list<array<string, mixed>> */
    private array $messages = [];
    private bool $isClosed = false;

    public function __construct(private readonly int $connectionId)
    {
    }

    public function id(): int
    {
        return $this->connectionId;
    }

    public function send(string $message): void
    {
        $decoded = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Test connection received non-object JSON.');
        }
        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }
        $this->messages[] = $normalized;
    }

    public function close(int $code, string $reason): void
    {
        unset($code, $reason);
        $this->isClosed = true;
    }

    public function closed(): bool
    {
        return $this->isClosed;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_map(static function (array $message): string {
            $type = $message['type'] ?? null;
            if (!is_string($type)) {
                throw new \RuntimeException('Test message has no string type.');
            }

            return $type;
        }, $this->messages);
    }

    /** @return array<string, mixed> */
    public function last(): array
    {
        $message = end($this->messages);
        if (!is_array($message)) {
            throw new \RuntimeException('Test connection has no messages.');
        }

        return $message;
    }

    public function lastType(): string
    {
        $type = $this->last()['type'] ?? null;
        if (!is_string($type)) {
            throw new \RuntimeException('Test message has no string type.');
        }

        return $type;
    }

    public function lastErrorCode(): string
    {
        $error = $this->last()['error'] ?? null;
        if (!is_array($error) || !is_string($error['code'] ?? null)) {
            throw new \RuntimeException('Test message has no structured error code.');
        }

        return $error['code'];
    }
}

final class FixedSnapshotProvider implements SnapshotProvider
{
    public function station(string $stationId): AuthoritativeSnapshot
    {
        $version = '2026-07-10T10:00:00Z';

        return new AuthoritativeSnapshot('station_snapshot', 'station:' . $stationId, $stationId, $version, [
            'stationId' => $stationId,
            'state' => 'fresh',
            'version' => $version,
            'updatedAt' => $version,
            'lastSuccessfulAt' => $version,
            'warning' => null,
            'departures' => [],
            'nearbyVehicles' => [],
        ]);
    }

    public function vehicle(string $vehicleId): AuthoritativeSnapshot
    {
        $version = '2026-07-10T10:00:00Z';

        return new AuthoritativeSnapshot('vehicle_snapshot', 'vehicle:' . $vehicleId, $vehicleId, $version, [
            'vehicle' => [
                'id' => $vehicleId,
                'lineCode' => null,
                'routeName' => null,
                'destination' => null,
                'state' => 'live',
                'latitude' => 61.45,
                'longitude' => 5.85,
                'bearing' => null,
                'delaySeconds' => null,
                'distanceMeters' => null,
                'lastSeenAt' => $version,
                'refreshedAt' => $version,
                'version' => $version,
                'nextStop' => null,
                'journeyReference' => null,
                'monitoredCall' => null,
                'progressBetweenStops' => null,
                'journeyVersion' => null,
                'routeProgress' => null,
            ],
            'trail' => [],
            'journey' => null,
            'upcomingStops' => [],
        ]);
    }
}
