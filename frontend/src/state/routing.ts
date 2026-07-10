import {
  SCENARIO_ALIASES,
  isVisualScenarioId,
  type VisualScenarioId,
} from "../fixtures/scenarios";

export type AppRoute =
  | { readonly kind: "public" }
  | { readonly kind: "admin"; readonly page: "status" | "watches" | "entur-log" | "realtime" | "events" | "migrations" }
  | { readonly kind: "scenario"; readonly scenario: VisualScenarioId }
  | { readonly kind: "scenario-index" };

export function parseRoute(location: Pick<Location, "pathname" | "search">): AppRoute {
  const segments = location.pathname.split("/").filter(Boolean);
  if (segments[0] === "__scenarios") return { kind: "scenario-index" };

  if (segments[0] === "__scenario" && segments[1] !== undefined) {
    const raw = decodeURIComponent(segments[1]);
    if (isVisualScenarioId(raw)) return { kind: "scenario", scenario: raw };
    const alias = SCENARIO_ALIASES[raw];
    if (alias !== undefined) return { kind: "scenario", scenario: alias };
  }

  const queryScenario = new URLSearchParams(location.search).get("scenario");
  if (queryScenario !== null) {
    if (isVisualScenarioId(queryScenario)) return { kind: "scenario", scenario: queryScenario };
    const alias = SCENARIO_ALIASES[queryScenario];
    if (alias !== undefined) return { kind: "scenario", scenario: alias };
  }

  if (segments[0] === "admin") {
    const page = segments[1];
    if (page === "watches" || page === "entur-log" || page === "realtime" || page === "events" || page === "migrations") return { kind: "admin", page };
    return { kind: "admin", page: "status" };
  }

  return { kind: "public" };
}
