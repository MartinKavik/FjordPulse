<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class SurrealRepositories
{
    public StationRepository $stations;
    public StationSnapshotRepository $stationSnapshots;
    public CurrentVehicleRepository $currentVehicles;
    public VehicleObservationRepository $vehicleObservations;
    public WatchRepository $watches;
    public RealtimeEventRepository $realtimeEvents;
    public EnturRequestLogRepository $enturRequestLogs;
    public SystemStatusRepository $systemStatus;
    public CleanupRepository $cleanup;
    public DiagnosticsRepository $diagnostics;

    public function __construct(public SurrealConnection $connection)
    {
        $this->stations = new StationRepository($connection);
        $this->stationSnapshots = new StationSnapshotRepository($connection);
        $this->currentVehicles = new CurrentVehicleRepository($connection);
        $this->vehicleObservations = new VehicleObservationRepository($connection);
        $this->watches = new WatchRepository($connection);
        $this->realtimeEvents = new RealtimeEventRepository($connection);
        $this->enturRequestLogs = new EnturRequestLogRepository($connection);
        $this->systemStatus = new SystemStatusRepository($connection);
        $this->cleanup = new CleanupRepository($connection);
        $this->diagnostics = new DiagnosticsRepository($connection);
    }
}
