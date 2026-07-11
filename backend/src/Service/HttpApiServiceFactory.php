<?php

declare(strict_types=1);

namespace FjordPulse\Service;

use FjordPulse\Config\RuntimeConfig;
use FjordPulse\Domain\EnturService;
use FjordPulse\Domain\Scenario;
use FjordPulse\Entur\EnturApiClient;
use FjordPulse\Entur\Fake\FakeGeocoder;
use FjordPulse\Entur\Fake\FakeJourneyPlanner;
use FjordPulse\Entur\Fake\FakeStationRegistry;
use FjordPulse\Entur\Fake\FakeVehiclePositions;
use FjordPulse\Entur\GeocoderInterface;
use FjordPulse\Entur\Http\GuzzleTransport;
use FjordPulse\Entur\JourneyPlannerInterface;
use FjordPulse\Entur\Mapper\GeocoderMapper;
use FjordPulse\Entur\Mapper\JourneyPlannerMapper;
use FjordPulse\Entur\Mapper\StopPlaceMapper;
use FjordPulse\Entur\Mapper\VehicleMapper;
use FjordPulse\Entur\MutableScenarioProvider;
use FjordPulse\Entur\Real\RealGeocoder;
use FjordPulse\Entur\Real\RealJourneyPlanner;
use FjordPulse\Entur\Real\RealStationRegistry;
use FjordPulse\Entur\Real\RealVehiclePositions;
use FjordPulse\Entur\RepositoryRequestBudget;
use FjordPulse\Entur\RequestBudgetInterface;
use FjordPulse\Entur\StationRegistryInterface;
use FjordPulse\Entur\VehiclePositionsInterface;
use FjordPulse\Surreal\SdkSurrealConnectionFactory;
use FjordPulse\Surreal\SurrealRepositories;

final readonly class HttpApiServiceFactory
{
    public function __construct(private RuntimeConfig $config)
    {
    }

    public function create(): HttpApiService
    {
        $connection = (new SdkSurrealConnectionFactory($this->config->surreal))->sync();
        $repositories = new SurrealRepositories($connection);
        $scenario = $this->loadScenario($repositories);
        $scenarios = new MutableScenarioProvider($scenario);
        $budget = $this->budget($repositories);

        if ($this->config->dataMode === 'fake') {
            $stations = new FakeStationRegistry();
            $geocoder = new FakeGeocoder();
            $journeys = new FakeJourneyPlanner($scenarios);
            $vehicles = new FakeVehiclePositions($scenarios);
        } else {
            [$stations, $geocoder, $journeys, $vehicles] = $this->realAdapters($repositories, $budget);
        }

        $searchNormalizer = new SearchNormalizer();

        return new HttpApiService(
            $this->config,
            $repositories,
            $scenarios,
            $stations,
            $geocoder,
            $journeys,
            $vehicles,
            $budget,
            new StationClusterer(),
            new SearchRanker($searchNormalizer),
            $searchNormalizer,
        );
    }

    private function loadScenario(SurrealRepositories $repositories): Scenario
    {
        $status = $repositories->systemStatus->find('dev_scenario');
        $value = $status?->metadata['scenario'] ?? null;

        return is_string($value) ? (Scenario::tryFrom($value) ?? $this->config->defaultScenario) : $this->config->defaultScenario;
    }

    private function budget(SurrealRepositories $repositories): RequestBudgetInterface
    {
        $global = self::positiveIntEnvironment('ENTUR_GLOBAL_REQUESTS_PER_MINUTE', 120);
        $limits = [
            EnturService::StopPlaceRegister->value => $this->config->enturStopPlaceRequestsPerMinute,
            EnturService::Geocoder->value => self::positiveIntEnvironment('ENTUR_GEOCODER_REQUESTS_PER_MINUTE', 20),
            EnturService::JourneyPlanner->value => self::positiveIntEnvironment('ENTUR_JOURNEY_REQUESTS_PER_MINUTE', 30),
            EnturService::VehiclePositions->value => self::positiveIntEnvironment('ENTUR_VEHICLE_REQUESTS_PER_MINUTE', 30),
        ];

        return new RepositoryRequestBudget($repositories->enturRequestLogs, $global, $limits);
    }

    /**
     * @return array{StationRegistryInterface, GeocoderInterface, JourneyPlannerInterface, VehiclePositionsInterface}
     */
    private function realAdapters(SurrealRepositories $repositories, RequestBudgetInterface $budget): array
    {
        $client = new EnturApiClient(
            new GuzzleTransport(),
            $budget,
            new RepositoryEnturRequestObserver($repositories->enturRequestLogs),
            $this->config->enturClientName,
        );

        return [
            new RealStationRegistry($client, new StopPlaceMapper(), $this->config->enturStopPlacesUrl),
            new RealGeocoder($client, new GeocoderMapper(), $this->config->enturGeocoderUrl),
            new RealJourneyPlanner($client, new JourneyPlannerMapper(), $this->config->enturJourneyPlannerUrl),
            new RealVehiclePositions(
                $client,
                new VehicleMapper($this->config->vehicleStaleSeconds, $this->config->vehicleLostSeconds),
                $this->config->enturVehiclePositionsUrl,
            ),
        ];
    }

    private static function positiveIntEnvironment(string $name, int $default): int
    {
        $value = getenv($name);
        if (!is_string($value) || !ctype_digit($value) || (int)$value < 1) {
            return $default;
        }

        return (int)$value;
    }
}
