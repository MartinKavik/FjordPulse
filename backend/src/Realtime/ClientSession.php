<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

final class ClientSession
{
    /** @var array<string, true> */
    private array $stations = [];

    /** @var array<string, true> */
    private array $vehicles = [];

    private ?string $focusedVehicle = null;

    /** @param array<string, mixed> $tokenClaims */
    public function __construct(
        public readonly int $connectionId,
        public readonly string $sessionId,
        public readonly array $tokenClaims,
        public readonly MessageRateLimiter $rateLimiter,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $sessionId) !== 1) {
            throw new \InvalidArgumentException('Realtime session id is invalid.');
        }
    }

    public function watchStation(string $stationId): void
    {
        $this->stations[$stationId] = true;
    }

    public function unwatchStation(string $stationId): void
    {
        unset($this->stations[$stationId]);
    }

    public function watchesStation(string $stationId): bool
    {
        return isset($this->stations[$stationId]);
    }

    public function watchVehicle(string $vehicleId): void
    {
        $this->vehicles[$vehicleId] = true;
    }

    public function unwatchVehicle(string $vehicleId): void
    {
        unset($this->vehicles[$vehicleId]);
    }

    public function watchesVehicle(string $vehicleId): bool
    {
        return isset($this->vehicles[$vehicleId]);
    }

    public function focus(string $vehicleId): void
    {
        $this->focusedVehicle = $vehicleId;
    }

    public function unfocus(): void
    {
        $this->focusedVehicle = null;
    }

    public function focusedVehicle(): ?string
    {
        return $this->focusedVehicle;
    }

    public function focusScope(?string $vehicleId = null): ?string
    {
        $vehicleId ??= $this->focusedVehicle;

        return $vehicleId === null ? null : 'focus:' . $this->sessionId . ':' . $vehicleId;
    }
}
