<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Integration;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use FjordPulse\Domain\EnturService;
use FjordPulse\Entur\RateLimited;
use FjordPulse\Entur\RepositoryRequestBudget;
use FjordPulse\Surreal\SurrealRepositories;
use PHPUnit\Framework\Attributes\CoversNothing;

use function Amp\async;

#[CoversNothing]
final class EnturBudgetConcurrencyIntegrationTest extends SurrealIntegrationTestCase
{
    public function testTwoIndependentConnectionsCannotOversubscribeOneRollingSlot(): void
    {
        [$factory] = $this->database('entur_budget_concurrency');
        $firstConnection = $factory->ampCommand();
        $secondConnection = $factory->ampCommand();
        $firstRepositories = new SurrealRepositories($firstConnection);
        $secondRepositories = new SurrealRepositories($secondConnection);
        $limits = array_fill_keys(
            array_map(static fn(EnturService $service): string => $service->value, EnturService::cases()),
            1,
        );
        $firstBudget = new RepositoryRequestBudget($firstRepositories->enturBudgets, 1, $limits);
        $secondBudget = new RepositoryRequestBudget($secondRepositories->enturBudgets, 1, $limits);
        /** @var DeferredFuture<void> $gate */
        $gate = new DeferredFuture();
        $gateFuture = $gate->getFuture();
        $acquire = static function (RepositoryRequestBudget $budget, string $requestId) use ($gateFuture): ?string {
            $gateFuture->await();
            try {
                $budget->acquire(EnturService::JourneyPlanner, $requestId);

                return $requestId;
            } catch (RateLimited) {
                return null;
            }
        };

        try {
            $first = async($acquire, $firstBudget, 'entur_concurrent_first');
            $second = async($acquire, $secondBudget, 'entur_concurrent_second');
            $gate->complete();

            $results = [
                self::awaitAcquire($first),
                self::awaitAcquire($second),
            ];
            $reserved = array_values(array_filter($results));
            $reservedId = $results[0] ?? $results[1];

            self::assertCount(1, $reserved, 'Exactly one concurrent caller may reserve the shared slot.');
            self::assertCount(1, array_filter($results, static fn(?string $id): bool => $id === null));
            self::assertNotNull($reservedId);
            self::assertSame([], $firstRepositories->enturRequestLogs->recent(), 'A reservation exists before any transport result is logged.');

            foreach ([$firstBudget->status(), $secondBudget->status()] as $status) {
                self::assertCount(5, $status);
                self::assertSame(0, $status['global']['remaining']);
                self::assertSame(0, $status[EnturService::JourneyPlanner->value]['remaining']);
                self::assertSame(1, $status[EnturService::VehiclePositions->value]['remaining']);
            }

            $secondBudget->acquire(EnturService::JourneyPlanner, $reservedId);
            self::assertSame(
                1,
                $secondRepositories->enturBudgets->usage(new DateTimeImmutable())->global,
                'Retrying the same request id must not consume a second slot.',
            );
        } finally {
            $firstConnection->close();
            $secondConnection->close();
        }
    }

    public function testReservationsExpireAndPerServiceCapacityRemainsIndependent(): void
    {
        [$factory] = $this->database('entur_budget_expiry');
        $firstConnection = $factory->sync();
        $secondConnection = $factory->sync();
        $first = new SurrealRepositories($firstConnection);
        $second = new SurrealRepositories($secondConnection);
        $at = new DateTimeImmutable('2030-01-01T12:00:00Z', new DateTimeZone('UTC'));

        try {
            self::assertTrue($first->enturBudgets->reserve(
                EnturService::JourneyPlanner,
                'entur_journey_one',
                $at,
                2,
                1,
            ));
            self::assertTrue($second->enturBudgets->reserve(
                EnturService::JourneyPlanner,
                'entur_journey_one',
                $at,
                2,
                1,
            ), 'The same reservation id is idempotent across connections.');
            self::assertFalse($second->enturBudgets->reserve(
                EnturService::JourneyPlanner,
                'entur_journey_two',
                $at,
                2,
                1,
            ));
            self::assertTrue($second->enturBudgets->reserve(
                EnturService::VehiclePositions,
                'entur_vehicle_one',
                $at,
                2,
                1,
            ));

            $full = $first->enturBudgets->usage($at);
            self::assertSame(2, $full->global);
            self::assertSame(1, $full->service(EnturService::JourneyPlanner));
            self::assertSame(1, $full->service(EnturService::VehiclePositions));

            $afterWindow = $at->add(new DateInterval('PT61S'));
            self::assertTrue($first->enturBudgets->reserve(
                EnturService::JourneyPlanner,
                'entur_journey_after_window',
                $afterWindow,
                2,
                1,
            ));
            $expired = $second->enturBudgets->usage($afterWindow);
            self::assertSame(1, $expired->global);
            self::assertSame(1, $expired->service(EnturService::JourneyPlanner));
            self::assertSame(0, $expired->service(EnturService::VehiclePositions));
        } finally {
            $firstConnection->close();
            $secondConnection->close();
        }
    }

    /** @param Future<mixed> $future */
    private static function awaitAcquire(Future $future): ?string
    {
        $result = $future->await(new TimeoutCancellation(10.0));
        if ($result !== null && !is_string($result)) {
            throw new \UnexpectedValueException('Concurrent budget result must be a request id or null.');
        }

        return $result;
    }
}
