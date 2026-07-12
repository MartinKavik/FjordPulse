<?php

declare(strict_types=1);

namespace FjordPulse\Tests\Unit;

use FjordPulse\Domain\VehiclePassengerServiceClassifier;
use FjordPulse\Domain\VehiclePassengerServiceState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VehiclePassengerServiceClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null, string|null, string|null, string|null, VehiclePassengerServiceState}>
     */
    public static function signals(): iterable
    {
        yield 'canonical public service journey' => ['SKY:ServiceJourney:4-1', null, 'GAR4.402', null, 'skyss.no', VehiclePassengerServiceState::Passenger];
        yield 'explicit dead run' => ['SKY:DeadRun:garage-4', null, null, null, null, VehiclePassengerServiceState::NonPassenger];
        yield 'internal id and GAR destination' => ['21255797_200969', 'NSR:Quay:53799', 'GAR4.402', null, 'Flaktveit', VehiclePassengerServiceState::NonPassenger];
        yield 'missing id and GAR monitored call' => [null, null, null, 'gar7.1', null, VehiclePassengerServiceState::NonPassenger];
        yield 'provider operational destination sentinel' => ['21255797_200969', null, null, null, ' SKYSS.NO ', VehiclePassengerServiceState::NonPassenger];
        yield 'noncanonical id without operational evidence' => ['21255797_200969', 'NSR:Quay:1', 'NSR:Quay:2', 'NSR:Quay:2', 'Sentrum', VehiclePassengerServiceState::Unknown];
        yield 'missing journey signals' => [null, null, null, null, null, VehiclePassengerServiceState::Unknown];
        yield 'type name embedded outside type segment' => ['SKY:Other:ServiceJourney:4', null, null, null, null, VehiclePassengerServiceState::Unknown];
        yield 'GAR embedded in a public reference' => ['internal', null, 'NSR:Quay:GAR4', null, null, VehiclePassengerServiceState::Unknown];
    }

    #[DataProvider('signals')]
    public function testClassificationUsesOnlyExplicitTypedAndNarrowOperationalSignals(
        ?string $journeyId,
        ?string $originRef,
        ?string $destinationRef,
        ?string $monitoredStopPointRef,
        ?string $destinationName,
        VehiclePassengerServiceState $expected,
    ): void {
        self::assertSame($expected, VehiclePassengerServiceClassifier::classify(
            $journeyId,
            $originRef,
            $destinationRef,
            $monitoredStopPointRef,
            $destinationName,
        ));
    }
}
