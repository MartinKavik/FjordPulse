import { cleanup, fireEvent, render, screen, within } from "@solidjs/testing-library";
import { afterEach, describe, expect, it } from "vitest";
import { AdminStatusPage, EventsPage, explainRealtimeEvent } from "../src/components/Admin";
import type { AdminStatus, RealtimeEventRow } from "../src/types/domain";

const lostEvent: RealtimeEventRow = {
  eventId: "019f52b3-15fb-71e1-a34b-50754e9600e7",
  type: "vehicle_lost",
  scope: "vehicle:RL_x0020_72190",
  entityId: "RL_x0020_72190",
  version: "2026-07-11T19:41:36.105Z",
  source: "current_vehicle",
  payload: {
    observation: {
      bearing: null,
      latitude: 58.7351115580479,
      longitude: 5.64892688436163,
      observedAt: "2026-07-11T19:35:30.002010Z",
    },
    vehicle: {
      id: "RL_x0020_72190",
      state: "lost",
      lastSeenAt: "2026-07-11T19:35:30.002010Z",
      refreshedAt: "2026-07-11T19:41:36.105591Z",
      version: "2026-07-11T19:41:36.105Z",
    },
  },
  createdAt: "2026-07-11T19:41:36.123726710Z",
};

const status: AdminStatus = {
  build: { version: "dev", environment: "local", dataMode: "real" },
  database: { engine: "surrealdb", endpointOrigin: "ws://127.0.0.1:8000", namespace: "fjordpulse", name: "fjordpulse_real", warning: null },
  resources: {
    checkedAt: "2026-07-11T19:41:36.123Z",
    cpu: { usagePercent: 25, load1: 1, load5: 0.8, load15: 0.6, logicalCores: 8 },
    memory: { totalBytes: 16_000_000_000, availableBytes: 10_000_000_000, usedBytes: 6_000_000_000, usedPercent: 37.5, scope: "host" },
    disk: { path: "/", totalBytes: 500_000_000_000, freeBytes: 300_000_000_000, usedBytes: 200_000_000_000, usedPercent: 40 },
  },
  dataCounts: {
    stations: 57_964,
    stationSnapshots: 1,
    currentVehicles: 1,
    vehicleObservations: 1,
    watches: 1,
    realtimeEvents: 2,
    enturRequestLogs: 1,
  },
  stationImport: {
    count: 57_964,
    lastImportedAt: "2026-07-10T14:51:12.168Z",
    sourceVersion: "2026-07-10",
  },
  dependencies: [],
  metrics: [],
  events: [{
    id: lostEvent.eventId,
    type: lostEvent.type,
    scope: lostEvent.scope,
    entityId: lostEvent.entityId,
    version: lostEvent.version,
    createdAt: lostEvent.createdAt,
    status: "warning",
    source: lostEvent.source,
    payload: lostEvent.payload,
  }],
};

afterEach(() => cleanup());

describe("admin realtime-event evidence", () => {
  it("omits host-resource placeholders when the platform cannot measure them", () => {
    render(() => <AdminStatusPage status={{
      ...status,
      resources: {
        ...status.resources,
        cpu: { usagePercent: null, load1: null, load5: null, load15: null, logicalCores: null },
        memory: { totalBytes: null, availableBytes: null, usedBytes: null, usedPercent: null, scope: "host" },
        disk: { path: "/unavailable", totalBytes: null, freeBytes: null, usedBytes: null, usedPercent: null },
      },
    }} onRefresh={() => undefined} />);

    expect(screen.queryByRole("heading", { name: "Host resources" })).not.toBeInTheDocument();
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });

  it("shows the credential-free database target and a deployment warning", () => {
    render(() => <AdminStatusPage status={{
      ...status,
      build: { ...status.build, environment: "staging" },
      database: {
        ...status.database,
        warning: "Loopback database target configured for staging; localhost resolves inside the running service.",
      },
    }} onRefresh={() => undefined} />);

    expect(screen.getByText("ws://127.0.0.1:8000")).toBeVisible();
    expect(screen.getByText("fjordpulse / fjordpulse_real", { exact: false })).toBeVisible();
    expect(screen.getByText(/loopback database target configured for staging/i)).toBeVisible();
    expect(screen.getByRole("heading", { name: "Host resources" })).toBeVisible();
    expect(screen.getByText("9.3 GiB free")).toBeVisible();
    expect(screen.getByText("279 GiB free")).toBeVisible();
    expect(screen.getAllByRole("progressbar")).toHaveLength(3);
    expect(document.body).not.toHaveTextContent("/rpc");
  });

  it("explains a lost vehicle from persisted observation timestamps", () => {
    const explanation = explainRealtimeEvent(lostEvent);

    expect(explanation.label).toBe("LOST");
    expect(explanation.summary).toMatch(/no recent position/i);
    expect(explanation.summary).toMatch(/6 min 6 sec/i);
  });

  it("exposes lost-event evidence from System status without calling it WARNING", async () => {
    render(() => <AdminStatusPage status={status} onRefresh={() => undefined} />);

    const table = screen.getByRole("table");
    expect(within(table).getByText("LOST")).toBeInTheDocument();
    expect(within(table).queryByText("WARNING")).not.toBeInTheDocument();

    await fireEvent.click(within(table).getByRole("button", {
      name: "Details for vehicle_lost vehicle:RL_x0020_72190",
    }));
    expect(within(table).getByText(/no recent position/i)).toBeVisible();
    const evidence = within(table).getByLabelText("Raw event payload");
    expect(evidence).toBeVisible();
    expect(evidence).toHaveTextContent("2026-07-11T19:35:30.002010Z");
    expect(evidence).toHaveTextContent("2026-07-11T19:41:36.105591Z");
  });

  it("exposes source and raw persisted payload on the full Events page", async () => {
    render(() => <EventsPage rows={[lostEvent]} onRefresh={() => undefined} />);

    const table = screen.getByRole("table");
    expect(within(table).getByText("LOST")).toBeInTheDocument();
    expect(within(table).getAllByText("current_vehicle").length).toBeGreaterThan(0);

    await fireEvent.click(within(table).getByRole("button", {
      name: "Details for vehicle_lost vehicle:RL_x0020_72190",
    }));
    const evidence = within(table).getByLabelText("Raw event payload");
    expect(evidence).toBeVisible();
    expect(evidence).toHaveTextContent("RL_x0020_72190");
    expect(evidence).toHaveTextContent("lastSeenAt");
    expect(evidence).toHaveTextContent("refreshedAt");
  });
});
