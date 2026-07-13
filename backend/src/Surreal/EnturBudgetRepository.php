<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateInterval;
use DateTimeImmutable;
use FjordPulse\Domain\EnturService;
use SurrealDB\SDK\Exceptions\ServerException;

final readonly class EnturBudgetRepository extends AbstractSurrealRepository
{
    private const int CONFLICT_RETRY_LIMIT = 16;

    public function reserve(
        EnturService $service,
        string $reservationId,
        DateTimeImmutable $at,
        int $globalLimit,
        int $serviceLimit,
    ): bool {
        if ($reservationId === '') {
            throw new \InvalidArgumentException('Entur budget reservation id must not be empty.');
        }
        if ($globalLimit < 1 || $serviceLimit < 1) {
            throw new \InvalidArgumentException('Entur budget limits must be positive.');
        }

        $expiresAt = $at->add(new DateInterval('PT60S'));
        $bindings = [
            'at' => SurrealEncoding::string(self::timestamp($at)),
            'expires_at' => SurrealEncoding::string(self::timestamp($expiresAt)),
            'service' => SurrealEncoding::string($service->value),
            'reservation_id' => SurrealEncoding::string($reservationId),
            'global_limit' => $globalLimit,
            'service_limit' => $serviceLimit,
        ];

        for ($attempt = 1; $attempt <= self::CONFLICT_RETRY_LIMIT; $attempt++) {
            try {
                $results = $this->connection->run(<<<'SURQL'
LET $reservation_at = type::datetime(type::string_lossy(encoding::base64::decode($at)));
LET $reservation_expires_at = type::datetime(type::string_lossy(encoding::base64::decode($expires_at)));
LET $reservation_service = type::string_lossy(encoding::base64::decode($service));
LET $reservation_key = type::string_lossy(encoding::base64::decode($reservation_id));
UPDATE ONLY entur_budget_state:shared
SET reservations = IF array::len(array::filter(
    reservations,
    |$reservation| $reservation.expires_at > $reservation_at
        AND $reservation.reservation_id = $reservation_key
)) > 0 {
    array::filter(reservations, |$reservation| $reservation.expires_at > $reservation_at)
} ELSE {
    array::append(
        array::filter(reservations, |$reservation| $reservation.expires_at > $reservation_at),
        {
            reservation_id: $reservation_key,
            service: $reservation_service,
            reserved_at: $reservation_at,
            expires_at: $reservation_expires_at
        }
    )
}
WHERE array::len(array::filter(
    reservations,
    |$reservation| $reservation.expires_at > $reservation_at
        AND $reservation.reservation_id = $reservation_key
)) > 0 OR (
    array::len(array::filter(
        reservations,
        |$reservation| $reservation.expires_at > $reservation_at
    )) < $global_limit
    AND array::len(array::filter(
        reservations,
        |$reservation| $reservation.expires_at > $reservation_at
            AND $reservation.service = $reservation_service
    )) < $service_limit
)
RETURN AFTER;
SURQL, $bindings);

                return DatabaseRecord::one($results[count($results) - 1] ?? null) !== null;
            } catch (ServerException $error) {
                if ($attempt === self::CONFLICT_RETRY_LIMIT || !self::isRetryableConflict($error)) {
                    throw $error;
                }
            }
        }

        throw new \LogicException('Entur budget conflict retry loop ended unexpectedly.');
    }

    public function usage(DateTimeImmutable $at): EnturBudgetUsage
    {
        $results = $this->connection->run(
            'SELECT reservations FROM ONLY entur_budget_state:shared;',
        );
        $record = DatabaseRecord::one($results[0] ?? null);
        if ($record === null) {
            throw new \RuntimeException('SurrealDB Entur budget state is missing. Apply migrations before serving requests.');
        }

        $services = [];
        $serviceAvailableAt = [];
        $globalAvailableAt = null;
        foreach (DatabaseRecord::many($record['reservations'] ?? []) as $reservation) {
            $expiresAt = DatabaseRecord::dateTime(
                $reservation['expires_at'] ?? null,
                'entur_budget_state.reservations.expires_at',
            );
            if ($expiresAt <= $at) {
                continue;
            }

            $service = DatabaseRecord::string(
                $reservation['service'] ?? null,
                'entur_budget_state.reservations.service',
            );
            if (EnturService::tryFrom($service) === null) {
                throw new \RuntimeException("SurrealDB Entur budget state contains unknown service {$service}.");
            }

            $services[$service] = ($services[$service] ?? 0) + 1;
            $globalAvailableAt = self::earlier($globalAvailableAt, $expiresAt);
            $serviceAvailableAt[$service] = self::earlier($serviceAvailableAt[$service] ?? null, $expiresAt);
        }

        return new EnturBudgetUsage(
            array_sum($services),
            $services,
            $globalAvailableAt,
            $serviceAvailableAt,
        );
    }

    private static function earlier(?DateTimeImmutable $left, DateTimeImmutable $right): DateTimeImmutable
    {
        return $left === null || $right < $left ? $right : $left;
    }

    private static function isRetryableConflict(ServerException $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'transaction')
            && str_contains($message, 'conflict')
            && str_contains($message, 'can be retried');
    }
}
