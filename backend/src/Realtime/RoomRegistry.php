<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RoomRegistry
{
    /** @var array<int, ClientConnection> */
    private array $clients = [];

    /** @var array<string, array<int, true>> */
    private array $rooms = [];

    /** @var array<int, array<string, true>> */
    private array $clientRooms = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly RealtimeTelemetry $telemetry,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function connect(ClientConnection $client): void
    {
        $id = $client->id();
        if (isset($this->clients[$id])) {
            throw new \LogicException('Realtime client is already registered.');
        }
        $this->clients[$id] = $client;
        $this->clientRooms[$id] = [];
        $this->telemetry->connected();
    }

    /** @return list<string> */
    public function disconnect(int $clientId): array
    {
        if (!isset($this->clients[$clientId])) {
            return [];
        }
        $scopes = array_keys($this->clientRooms[$clientId] ?? []);
        foreach ($scopes as $scope) {
            unset($this->rooms[$scope][$clientId]);
            if (($this->rooms[$scope] ?? []) === []) {
                unset($this->rooms[$scope]);
            }
        }
        unset($this->clientRooms[$clientId], $this->clients[$clientId]);
        $this->telemetry->disconnected();

        return $scopes;
    }

    public function join(int $clientId, string $scope): void
    {
        if (!isset($this->clients[$clientId])) {
            throw new \LogicException('Cannot join a room before registering the client.');
        }
        self::assertScope($scope);
        $this->rooms[$scope][$clientId] = true;
        $this->clientRooms[$clientId][$scope] = true;
    }

    public function leave(int $clientId, string $scope): void
    {
        unset($this->rooms[$scope][$clientId], $this->clientRooms[$clientId][$scope]);
        if (($this->rooms[$scope] ?? []) === []) {
            unset($this->rooms[$scope]);
        }
    }

    /** @param array<string, mixed> $message */
    public function send(int $clientId, array $message): bool
    {
        $client = $this->clients[$clientId] ?? null;
        if ($client === null || $client->closed()) {
            return false;
        }

        try {
            $client->send(EnvelopeFactory::encode($message));
            $this->telemetry->sent();

            return true;
        } catch (\Throwable $error) {
            $this->telemetry->sendFailed();
            $this->logger->warning('Realtime client send failed.', [
                'clientId' => $clientId,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /** @param array<string, mixed> $message */
    public function broadcast(string $scope, array $message): int
    {
        $recipients = 0;
        foreach (array_keys($this->rooms[$scope] ?? []) as $clientId) {
            if ($this->sendWithoutTelemetry($clientId, $message)) {
                $recipients++;
            }
        }
        $this->telemetry->broadcast($recipients);

        return $recipients;
    }

    /** @param array<string, mixed> $message */
    public function broadcastAll(array $message): int
    {
        $recipients = 0;
        foreach (array_keys($this->clients) as $clientId) {
            if ($this->sendWithoutTelemetry($clientId, $message)) {
                $recipients++;
            }
        }
        $this->telemetry->broadcast($recipients);

        return $recipients;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        $scopes = array_keys($this->rooms);
        sort($scopes);

        return $scopes;
    }

    /** @return list<string> */
    public function scopesFor(int $clientId): array
    {
        $scopes = array_keys($this->clientRooms[$clientId] ?? []);
        sort($scopes);

        return $scopes;
    }

    public function clientCount(string $scope): int
    {
        return count($this->rooms[$scope] ?? []);
    }

    public function roomCount(): int
    {
        return count($this->rooms);
    }

    /** @return list<array{scope: string, clientCount: int}> */
    public function roomDetails(): array
    {
        $details = [];
        foreach ($this->rooms as $scope => $clients) {
            $details[] = ['scope' => $scope, 'clientCount' => count($clients)];
        }
        usort($details, static fn(array $left, array $right): int => $left['scope'] <=> $right['scope']);

        return $details;
    }

    /** @param array<string, mixed> $message */
    private function sendWithoutTelemetry(int $clientId, array $message): bool
    {
        $client = $this->clients[$clientId] ?? null;
        if ($client === null || $client->closed()) {
            return false;
        }
        try {
            $client->send(EnvelopeFactory::encode($message));

            return true;
        } catch (\Throwable $error) {
            $this->telemetry->sendFailed();
            $this->logger->warning('Realtime broadcast send failed.', [
                'clientId' => $clientId,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    private static function assertScope(string $scope): void
    {
        if (strlen($scope) > 256
            || preg_match('/^(?:station|vehicle):\S+$|^focus:[^\s:]+:\S+$|^admin:status$/D', $scope) !== 1) {
            throw new \InvalidArgumentException('Realtime room scope is invalid.');
        }
    }
}
