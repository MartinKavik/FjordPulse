<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

final class VehiclePassengerServiceClassifier
{
    public static function classify(
        ?string $journeyId,
        ?string $originRef,
        ?string $destinationRef,
        ?string $monitoredStopPointRef,
        ?string $destinationName,
    ): VehiclePassengerServiceState {
        $journeyId = self::clean($journeyId);
        if ($journeyId !== null && preg_match('/^[^:]+:DeadRun:.+$/iD', $journeyId) === 1) {
            return VehiclePassengerServiceState::NonPassenger;
        }
        if ($journeyId !== null && preg_match('/^[^:]+:ServiceJourney:.+$/iD', $journeyId) === 1) {
            return VehiclePassengerServiceState::Passenger;
        }

        foreach ([$originRef, $destinationRef, $monitoredStopPointRef] as $reference) {
            $reference = self::clean($reference);
            if ($reference !== null && preg_match('/^GAR(?:[A-Z0-9._:-]|$)/iD', $reference) === 1) {
                return VehiclePassengerServiceState::NonPassenger;
            }
        }

        $destinationName = self::clean($destinationName);
        if ($destinationName !== null && strtolower($destinationName) === 'skyss.no') {
            return VehiclePassengerServiceState::NonPassenger;
        }

        return VehiclePassengerServiceState::Unknown;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
