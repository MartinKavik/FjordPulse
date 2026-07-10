<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use DateTimeInterface;
use FjordPulse\Domain\WatchType;
use FjordPulse\Domain\WatchPriority;
use FjordPulse\Dto\Watch;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ProtocolRouter
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ProtocolDecoder $decoder,
        private readonly RoomRegistry $rooms,
        private readonly ActiveWatchRegistry $watches,
        private readonly SnapshotProvider $snapshots,
        private readonly RealtimeTelemetry $telemetry,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function handle(ClientSession $session, string $raw): void
    {
        $this->telemetry->received();
        if (!$session->rateLimiter->allow()) {
            $this->telemetry->limited();
            $this->rooms->send($session->connectionId, EnvelopeFactory::notification('rate_limited', [
                'limit' => $session->rateLimiter->limit,
                'remaining' => 0,
                'retryAt' => $session->rateLimiter->retryAt(),
            ]));

            return;
        }

        try {
            $message = $this->decoder->decode($raw);
            $this->route($session, $message);
        } catch (ProtocolException $error) {
            $this->telemetry->rejected();
            $this->rooms->send($session->connectionId, EnvelopeFactory::error($error));
            $this->logger->notice('Rejected realtime client message.', [
                'clientId' => $session->connectionId,
                'messageId' => $error->messageId,
                'errorCode' => $error->errorCode,
                'details' => $error->details,
            ]);
        } catch (\Throwable $error) {
            $this->telemetry->rejected();
            $this->rooms->send($session->connectionId, EnvelopeFactory::error(new ProtocolException(
                'internal_error',
                'Realtime command could not be completed.',
            )));
            $this->logger->error('Realtime command failed.', [
                'clientId' => $session->connectionId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    public function disconnect(ClientSession $session): void
    {
        $this->watches->detachClient($session->sessionId);
        $this->rooms->disconnect($session->connectionId);
        $this->logger->info('Realtime client disconnected.', [
            'clientId' => $session->connectionId,
            'sessionId' => $session->sessionId,
        ]);
    }

    public function bridgeDegraded(string $reason, string $status = 'degraded'): void
    {
        $status = in_array($status, ['reconnecting', 'degraded', 'offline'], true) ? $status : 'degraded';
        $this->rooms->broadcastAll(EnvelopeFactory::notification('realtime_degraded', [
            'reason' => $reason,
            'fallbackPolling' => true,
            'bridgeStatus' => $status,
        ]));
    }

    public function bridgeRecovered(): void
    {
        $scopes = array_values(array_filter(
            $this->rooms->scopes(),
            static fn(string $scope): bool => str_starts_with($scope, 'station:') || str_starts_with($scope, 'vehicle:'),
        ));
        $this->telemetry->bridgeRecovered();
        $this->rooms->broadcastAll(EnvelopeFactory::notification('resync_required', [
            'reason' => 'bridge_recovered',
            'scopes' => $scopes,
        ]));
        foreach ($scopes as $scope) {
            $snapshot = $this->snapshotForScope($scope);
            if ($snapshot !== null) {
                $this->rooms->broadcast($scope, $snapshot->envelope());
            }
        }
    }

    public function sourceBackoff(Watch $watch, string $reason, string $retryAt): void
    {
        $scope = $watch->type === WatchType::Station
            ? 'station:' . $watch->entityId
            : 'vehicle:' . $watch->entityId;
        $this->rooms->broadcast($scope, EnvelopeFactory::notification('source_backoff', [
            'service' => $watch->type === WatchType::Station ? 'journey_planner' : 'vehicle_positions',
            'reason' => $reason,
            'retryAt' => $retryAt,
        ], $scope));
    }

    /** @param array<string, mixed> $payload */
    public function telemetry(array $payload): void
    {
        $this->rooms->broadcastAll(EnvelopeFactory::notification('telemetry_tick', $payload));
    }

    private function route(ClientSession $session, ClientMessage $message): void
    {
        match ($message->type) {
            ClientMessageType::WatchStation => $this->watchStation($session, $message),
            ClientMessageType::UnwatchStation => $this->unwatchStation($session, $message),
            ClientMessageType::WatchVehicle => $this->watchVehicle($session, $message),
            ClientMessageType::UnwatchVehicle => $this->unwatchVehicle($session, $message),
            ClientMessageType::FocusVehicle => $this->focusVehicle($session, $message),
            ClientMessageType::UnfocusVehicle => $this->unfocusVehicle($session, $message),
            ClientMessageType::PauseFocus => $this->pauseFocus($session, $message),
            ClientMessageType::ResumeFocus => $this->resumeFocus($session, $message),
            ClientMessageType::Ping => $this->rooms->send($session->connectionId, EnvelopeFactory::pong($message)),
        };
    }

    private function watchStation(ClientSession $session, ClientMessage $message): void
    {
        $stationId = $message->stationId();
        $scope = 'station:' . $stationId;
        $watch = $this->watches->acquire(
            $session->sessionId,
            WatchType::Station,
            $scope,
            $stationId,
            WatchPriority::Station,
        );
        $session->watchStation($stationId);
        $this->rooms->join($session->connectionId, $scope);
        $this->sendWatchAck($session, $message, 'watch_station_ack', $watch);
        $this->sendReconnectResyncIfNeeded($session, $message, [$scope]);
        $this->sendSnapshot($session->connectionId, $this->snapshots->station($stationId), $message->id);
    }

    private function unwatchStation(ClientSession $session, ClientMessage $message): void
    {
        $stationId = $message->stationId();
        $scope = 'station:' . $stationId;
        $remaining = $session->watchesStation($stationId)
            ? $this->watches->release($session->sessionId, WatchType::Station, $scope)
            : $this->rooms->clientCount($scope);
        $session->unwatchStation($stationId);
        $this->rooms->leave($session->connectionId, $scope);
        $this->rooms->send($session->connectionId, EnvelopeFactory::acknowledgement(
            $message->id,
            'unwatch_station_ack',
            $scope,
            $stationId,
            ['entityId' => $stationId, 'scope' => $scope, 'remainingClientCount' => $remaining],
        ));
    }

    private function watchVehicle(ClientSession $session, ClientMessage $message): void
    {
        $vehicleId = $message->vehicleId();
        $scope = 'vehicle:' . $vehicleId;
        $watch = $this->watches->acquire(
            $session->sessionId,
            WatchType::Vehicle,
            $scope,
            $vehicleId,
            WatchPriority::Vehicle,
        );
        $session->watchVehicle($vehicleId);
        $this->rooms->join($session->connectionId, $scope);
        $this->sendWatchAck($session, $message, 'watch_vehicle_ack', $watch);
        $this->sendReconnectResyncIfNeeded($session, $message, [$scope]);
        $this->sendSnapshot($session->connectionId, $this->snapshots->vehicle($vehicleId), $message->id);
    }

    private function unwatchVehicle(ClientSession $session, ClientMessage $message): void
    {
        $vehicleId = $message->vehicleId();
        $scope = 'vehicle:' . $vehicleId;
        $remaining = $session->watchesVehicle($vehicleId)
            ? $this->watches->release($session->sessionId, WatchType::Vehicle, $scope)
            : $this->rooms->clientCount($scope);
        $session->unwatchVehicle($vehicleId);
        if ($session->focusedVehicle() !== $vehicleId) {
            $this->rooms->leave($session->connectionId, $scope);
        }
        $this->rooms->send($session->connectionId, EnvelopeFactory::acknowledgement(
            $message->id,
            'unwatch_vehicle_ack',
            $scope,
            $vehicleId,
            ['entityId' => $vehicleId, 'scope' => $scope, 'remainingClientCount' => $remaining],
        ));
    }

    private function focusVehicle(ClientSession $session, ClientMessage $message): void
    {
        $vehicleId = $message->vehicleId();
        $current = $session->focusedVehicle();
        if ($current !== null && $current !== $vehicleId) {
            $this->stopFocus($session, $current);
        }
        $scope = $session->focusScope($vehicleId);
        \assert($scope !== null);
        $watch = $this->watches->acquire(
            $session->sessionId,
            WatchType::Focus,
            $scope,
            $vehicleId,
            WatchPriority::Focus,
        );
        $session->focus($vehicleId);
        $this->rooms->join($session->connectionId, $scope);
        $this->rooms->join($session->connectionId, 'vehicle:' . $vehicleId);
        $this->rooms->send($session->connectionId, EnvelopeFactory::acknowledgement(
            $message->id,
            'focus_started',
            $scope,
            $vehicleId,
            [
                'vehicleId' => $vehicleId,
                'scope' => $scope,
                'state' => 'following',
                'watchExpiresAt' => self::timestamp($watch),
            ],
        ));
        $this->sendReconnectResyncIfNeeded($session, $message, ['vehicle:' . $vehicleId, $scope]);
        $this->sendSnapshot($session->connectionId, $this->snapshots->vehicle($vehicleId), $message->id);
    }

    private function unfocusVehicle(ClientSession $session, ClientMessage $message): void
    {
        $vehicleId = $message->vehicleId();
        if ($session->focusedVehicle() !== $vehicleId) {
            throw new ProtocolException('invalid_state', 'Vehicle is not currently focused.', [
                'vehicleId' => $vehicleId,
            ], $message->id);
        }
        $scope = $session->focusScope($vehicleId);
        \assert($scope !== null);
        $this->stopFocus($session, $vehicleId);
        $this->rooms->send($session->connectionId, EnvelopeFactory::acknowledgement(
            $message->id,
            'focus_stopped',
            $scope,
            $vehicleId,
            ['vehicleId' => $vehicleId, 'scope' => $scope, 'state' => 'stopped', 'watchExpiresAt' => null],
        ));
    }

    private function pauseFocus(ClientSession $session, ClientMessage $message): void
    {
        $vehicleId = $message->vehicleId();
        $scope = $this->requireFocusScope($session, $vehicleId, $message->id);
        $watch = $this->watches->pauseFocus($session->sessionId, $scope);
        if ($watch === null) {
            throw new ProtocolException('invalid_state', 'Focus watch is not active.', ['vehicleId' => $vehicleId], $message->id);
        }
        $this->sendFocusAck($session, $message, 'focus_paused', 'paused', $scope, $watch);
    }

    private function resumeFocus(ClientSession $session, ClientMessage $message): void
    {
        $vehicleId = $message->vehicleId();
        $scope = $this->requireFocusScope($session, $vehicleId, $message->id);
        $watch = $this->watches->resumeFocus($session->sessionId, $scope);
        if ($watch === null) {
            throw new ProtocolException('invalid_state', 'Focus watch is not active.', ['vehicleId' => $vehicleId], $message->id);
        }
        $this->sendFocusAck($session, $message, 'focus_resumed', 'following', $scope, $watch);
        $this->sendSnapshot($session->connectionId, $this->snapshots->vehicle($vehicleId), $message->id);
    }

    private function stopFocus(ClientSession $session, string $vehicleId): void
    {
        $scope = $session->focusScope($vehicleId);
        \assert($scope !== null);
        $this->watches->release($session->sessionId, WatchType::Focus, $scope);
        $this->rooms->leave($session->connectionId, $scope);
        $session->unfocus();
        if (!$session->watchesVehicle($vehicleId)) {
            $this->rooms->leave($session->connectionId, 'vehicle:' . $vehicleId);
        }
    }

    private function requireFocusScope(ClientSession $session, string $vehicleId, string $messageId): string
    {
        if ($session->focusedVehicle() !== $vehicleId) {
            throw new ProtocolException('invalid_state', 'Vehicle is not currently focused.', [
                'vehicleId' => $vehicleId,
            ], $messageId);
        }
        $scope = $session->focusScope($vehicleId);
        \assert($scope !== null);

        return $scope;
    }

    private function sendWatchAck(ClientSession $session, ClientMessage $message, string $type, Watch $watch): void
    {
        $this->rooms->send($session->connectionId, EnvelopeFactory::acknowledgement(
            $message->id,
            $type,
            $watch->scope,
            $watch->entityId,
            [
                'entityId' => $watch->entityId,
                'scope' => $watch->scope,
                'clientCount' => $watch->clientCount,
                'watchExpiresAt' => self::timestamp($watch),
            ],
        ));
    }

    private function sendFocusAck(
        ClientSession $session,
        ClientMessage $message,
        string $type,
        string $state,
        string $scope,
        Watch $watch,
    ): void {
        $this->rooms->send($session->connectionId, EnvelopeFactory::acknowledgement(
            $message->id,
            $type,
            $scope,
            $message->vehicleId(),
            [
                'vehicleId' => $message->vehicleId(),
                'scope' => $scope,
                'state' => $state,
                'watchExpiresAt' => self::timestamp($watch),
            ],
        ));
    }

    private function sendSnapshot(int $clientId, ?AuthoritativeSnapshot $snapshot, string $messageId): void
    {
        if ($snapshot !== null) {
            $this->rooms->send($clientId, $snapshot->envelope());

            return;
        }
        $this->rooms->send($clientId, EnvelopeFactory::error(new ProtocolException(
            'snapshot_unavailable',
            'Authoritative snapshot is not available yet.',
            [],
            $messageId,
        )));
    }

    /** @param list<string> $scopes */
    private function sendReconnectResyncIfNeeded(ClientSession $session, ClientMessage $message, array $scopes): void
    {
        if (!isset($message->payload['knownVersion']) && !isset($message->payload['lastEventId'])) {
            return;
        }
        $this->rooms->send($session->connectionId, EnvelopeFactory::notification('resync_required', [
            'reason' => 'browser_reconnected',
            'scopes' => $scopes,
        ]));
    }

    private function snapshotForScope(string $scope): ?AuthoritativeSnapshot
    {
        if (str_starts_with($scope, 'station:')) {
            return $this->snapshots->station(substr($scope, strlen('station:')));
        }
        if (str_starts_with($scope, 'vehicle:')) {
            return $this->snapshots->vehicle(substr($scope, strlen('vehicle:')));
        }

        return null;
    }

    private static function timestamp(Watch $watch): string
    {
        return $watch->expiresAt->format(DateTimeInterface::RFC3339_EXTENDED);
    }
}
