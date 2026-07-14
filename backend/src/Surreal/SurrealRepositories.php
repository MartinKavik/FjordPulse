<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

final readonly class SurrealRepositories
{
    public StationRepository $stations;
    public StationSnapshotRepository $stationSnapshots;
    public StationTimetableRepository $stationTimetables;
    public CurrentVehicleRepository $currentVehicles;
    public VehicleObservationRepository $vehicleObservations;
    public JourneySnapshotRepository $journeySnapshots;
    public WatchRepository $watches;
    public RealtimeEventRepository $realtimeEvents;
    public EnturRequestLogRepository $enturRequestLogs;
    public EnturBudgetRepository $enturBudgets;
    public SystemStatusRepository $systemStatus;
    public CleanupRepository $cleanup;
    public DiagnosticsRepository $diagnostics;
    public DatabaseSchemaRepository $databaseSchema;
    public MigrationDiagnosticsRepository $migrationDiagnostics;

    public function __construct(public SurrealConnection $connection)
    {
        $this->stations = new StationRepository($connection);
        $this->stationSnapshots = new StationSnapshotRepository($connection);
        $this->stationTimetables = new StationTimetableRepository($connection);
        $this->currentVehicles = new CurrentVehicleRepository($connection);
        $this->vehicleObservations = new VehicleObservationRepository($connection);
        $this->journeySnapshots = new JourneySnapshotRepository($connection);
        $this->watches = new WatchRepository($connection);
        $this->realtimeEvents = new RealtimeEventRepository($connection);
        $this->enturRequestLogs = new EnturRequestLogRepository($connection);
        $this->enturBudgets = new EnturBudgetRepository($connection);
        $this->systemStatus = new SystemStatusRepository($connection);
        $this->cleanup = new CleanupRepository($connection);
        $this->diagnostics = new DiagnosticsRepository($connection);
        $this->databaseSchema = new DatabaseSchemaRepository($connection);
        $this->migrationDiagnostics = new MigrationDiagnosticsRepository($connection);
    }
}
