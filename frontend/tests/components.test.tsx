import { fireEvent, render, screen } from "@solidjs/testing-library";
import { describe, expect, it, vi } from "vitest";
import { freshStationSnapshot, line100Vehicle } from "../src/fixtures/scenarios";
import { DepartureRow, StatusChip, TelemetryStrip } from "../src/components/DesignSystem";
import { SearchOverlay } from "../src/components/AppChrome";
import { StationPanel, VehiclePanel } from "../src/components/Panels";

describe("design-system components", () => {
  it("communicates status with text in addition to color", () => {
    render(() => <StatusChip state="delayed" label="Live delayed" />);
    expect(screen.getByRole("status")).toHaveTextContent("Live delayed");
    expect(screen.getByRole("status")).toHaveAttribute("data-state", "delayed");
  });

  it("formats transport times in Europe/Oslo", () => {
    render(() => <DepartureRow departure={freshStationSnapshot.departures[0]!} />);
    expect(screen.getByText("20:45")).toBeInTheDocument();
    expect(screen.getByText("+2 min")).toBeInTheDocument();
  });

  it("renders canonical telemetry including polling fallback", () => {
    render(() => <TelemetryStrip telemetry={{ backend: "ok", realtime: "offline", entur: "ok", liveQueryBridge: "offline", refreshMode: "polling", lastUpdateAt: null }} />);
    expect(screen.getByLabelText("System telemetry")).toHaveTextContent("polling");
    expect(screen.getByLabelText("System telemetry")).toHaveTextContent("offline");
  });
});

describe("public interaction components", () => {
  it("selects a keyboard-highlighted search result", async () => {
    const select = vi.fn();
    const result = { type: "station" as const, id: "NSR:StopPlace:548", label: "Førde rutebilstasjon", secondaryText: "Station", stationId: "NSR:StopPlace:548", lineCode: null, latitude: 61.45, longitude: 5.85 };
    render(() => <SearchOverlay open query="førde" results={[result]} activeIndex={0} loading={false} onSelect={select} onClose={() => undefined} />);
    await fireEvent.click(screen.getByRole("option"));
    expect(select).toHaveBeenCalledWith(result);
  });

  it("keeps station empty and error states distinct", () => {
    const noop = () => undefined;
    const { unmount } = render(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "empty", departures: [], nearbyVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByText("No upcoming departures.")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    unmount();
    render(() => <StationPanel snapshot={{ ...freshStationSnapshot, state: "error", message: "Could not load station details.", departures: [], nearbyVehicles: [] }} sheet="none" onClose={noop} onRetry={noop} onVehicle={noop} onSheet={noop} />);
    expect(screen.getByRole("alert")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("exposes Focus, stale, and lost recovery actions", () => {
    const noop = () => undefined;
    const props = { sheet: "none" as const, onClose: noop, onFocus: noop, onPause: noop, onResume: noop, onUnfocus: noop, onStop: noop, onRetry: noop, onSheet: noop };
    const { unmount } = render(() => <VehiclePanel {...props} vehicle={line100Vehicle} focus="none" />);
    expect(screen.getByRole("button", { name: /Focus this vehicle/i })).toBeInTheDocument();
    unmount();
    const staleRender = render(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, state: "stale" }} focus="paused" />);
    expect(screen.getByRole("button", { name: /Keep watching/i })).toBeInTheDocument();
    staleRender.unmount();
    render(() => <VehiclePanel {...props} vehicle={{ ...line100Vehicle, state: "lost" }} focus="none" />);
    expect(screen.getByRole("button", { name: /Try again/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Stop following/i })).toBeInTheDocument();
  });
});
