import type { Component, JSX } from "solid-js";
import {
  SCENARIO_ALIASES,
  FIXTURE_NOW_MS,
  adminDatabaseMigrationsFixture,
  adminDatabaseMigrationStatesFixture,
  adminDatabaseSchemaFixture,
  adminStatusFixture,
  enturLogFixture,
  freshStationSnapshot,
  getPublicScenario,
  isPublicScenarioId,
  isVisualScenarioId,
  line100Vehicle,
  searchResults,
  watchRowsFixture,
} from "../fixtures/scenarios";
import { ClockProvider } from "../state/clock";
import type { AdminEnturLog, PublicScenario, SearchResult, StationSnapshot, VehicleState } from "../types/domain";
import type { HttpClient } from "../services/httpClient";
import { AdminApp, type AdminPage } from "./Admin";
import { DesignSystemPage, ScenarioIndex } from "./ScenarioPages";

function fixtureEnturLog(): AdminEnturLog {
  const measured = enturLogFixture.flatMap((row) => row.latencyMs === null ? [] : [row.latencyMs]).sort((left, right) => left - right);
  const hits = enturLogFixture.filter((row) => row.cache === "hit").length;
  return {
    metrics: {
      requestsPerMinute: enturLogFixture.length,
      cacheHitRate: enturLogFixture.length === 0 ? 0 : hits / enturLogFixture.length,
      p95LatencyMs: measured.length === 0 ? null : measured[Math.floor((measured.length - 1) * 0.95)] ?? null,
      inBackoff: enturLogFixture.some((row) => row.status === "backoff" || row.status === "rate_limited"),
    },
    entries: enturLogFixture,
  };
}

function resolveScenario(value: string | null): string | null {
  if (value === null) return null;
  if (isVisualScenarioId(value)) return value;
  return SCENARIO_ALIASES[value] ?? null;
}

interface FixtureRouterProps {
  readonly scenario: string | null;
  readonly index: boolean;
  readonly http: HttpClient;
  readonly renderPublic: (scenario: PublicScenario, interactions: {
    readonly searchResults: readonly SearchResult[];
    readonly station: StationSnapshot;
    readonly vehicle: VehicleState;
  }) => JSX.Element;
}

const FixtureContent: Component<FixtureRouterProps> = (props) => {
  if (props.index) return <ScenarioIndex />;
  const scenario = resolveScenario(props.scenario);
  if (scenario === null) return <ScenarioIndex />;
  if (scenario === "design_system_components") return <DesignSystemPage />;
  if (scenario.startsWith("admin_")) {
    const page: AdminPage = scenario === "admin_infrastructure" ? "infrastructure" : scenario === "admin_watches" ? "watches" : scenario === "admin_entur_log" ? "entur-log" : scenario === "admin_database" ? "database" : "status";
    const databaseView = page === "database" && new URLSearchParams(window.location.search).get("databaseView") === "migrations" ? "migrations" : page === "database" ? "schema" : undefined;
    return <AdminApp page={page} databaseView={databaseView} fixture http={props.http} fixtureData={{ status: adminStatusFixture, watches: watchRowsFixture, enturLog: fixtureEnturLog(), databaseSchema: adminDatabaseSchemaFixture, databaseMigrations: databaseView === "migrations" ? adminDatabaseMigrationStatesFixture : adminDatabaseMigrationsFixture }} />;
  }
  return isPublicScenarioId(scenario)
    ? props.renderPublic(getPublicScenario(scenario), { searchResults, station: freshStationSnapshot, vehicle: line100Vehicle })
    : <ScenarioIndex />;
};

export const FixtureRouter: Component<FixtureRouterProps> = (props) => (
  <ClockProvider now={() => FIXTURE_NOW_MS}>
    <FixtureContent {...props} />
  </ClockProvider>
);
