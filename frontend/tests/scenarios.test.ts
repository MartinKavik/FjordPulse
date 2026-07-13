import { describe, expect, it } from "vitest";
import { VISUAL_SCENARIO_IDS, getPublicScenario, isPublicScenarioId, isVisualScenarioId } from "../src/fixtures/scenarios";
import { parseRoute } from "../src/state/routing";

describe("deterministic visual scenarios", () => {
  it("contains exactly the 27 approved design states", () => {
    expect(VISUAL_SCENARIO_IDS).toHaveLength(27);
    expect(new Set(VISUAL_SCENARIO_IDS).size).toBe(27);
  });

  it.each(VISUAL_SCENARIO_IDS)("routes %s deterministically", (scenario) => {
    expect(parseRoute({ pathname: `/__scenario/${scenario}`, search: "" })).toEqual({ kind: "scenario", scenario });
    expect(isVisualScenarioId(scenario)).toBe(true);
  });

  it("keeps public failure states semantically distinct", () => {
    expect(getPublicScenario("desktop_station_empty").stationSnapshot?.state).toBe("empty");
    expect(getPublicScenario("desktop_station_stale").stationSnapshot?.state).toBe("stale");
    expect(getPublicScenario("desktop_station_error").stationSnapshot?.state).toBe("error");
    expect(getPublicScenario("desktop_vehicle_stale").vehicle?.state).toBe("stale");
    expect(getPublicScenario("desktop_vehicle_lost").vehicle?.state).toBe("lost");
    expect(getPublicScenario("desktop_vehicle_non_passenger").vehicle?.passengerServiceState).toBe("non_passenger");
    expect(getPublicScenario("mobile_vehicle_non_passenger").focus).toBe("following");
    expect(getPublicScenario("desktop_degraded_fallback").telemetry.refreshMode).toBe("polling");
  });

  it("recognizes only public scenario IDs for public fixtures", () => {
    expect(isPublicScenarioId("mobile_vehicle_focus")).toBe(true);
    expect(isPublicScenarioId("admin_status")).toBe(false);
    expect(isPublicScenarioId("design_system_components")).toBe(false);
  });

  it("routes protected diagnostics without confusing them with visual fixtures", () => {
    expect(parseRoute({ pathname: "/admin", search: "" })).toEqual({ kind: "admin", page: "status" });
    expect(parseRoute({ pathname: "/admin/status", search: "" })).toEqual({ kind: "admin", page: "status" });
    expect(parseRoute({ pathname: "/admin/overview", search: "" })).toEqual({ kind: "admin", page: "status" });
    expect(parseRoute({ pathname: "/admin/infrastructure", search: "" })).toEqual({ kind: "admin", page: "infrastructure" });
    expect(parseRoute({ pathname: "/admin/realtime", search: "" })).toEqual({ kind: "admin", page: "realtime" });
    expect(parseRoute({ pathname: "/admin/events", search: "" })).toEqual({ kind: "admin", page: "events" });
    expect(parseRoute({ pathname: "/admin/database", search: "" })).toEqual({ kind: "admin", page: "database", databaseView: "schema" });
    expect(parseRoute({ pathname: "/admin/database/schema", search: "" })).toEqual({ kind: "admin", page: "database", databaseView: "schema" });
    expect(parseRoute({ pathname: "/admin/database/migrations", search: "" })).toEqual({ kind: "admin", page: "database", databaseView: "migrations" });
    expect(parseRoute({ pathname: "/admin/migrations", search: "" })).toEqual({ kind: "admin", page: "database", databaseView: "migrations" });
  });
});
