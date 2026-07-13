export type AppRoute =
  | { readonly kind: "public" }
  | { readonly kind: "admin"; readonly page: "status" | "infrastructure" | "watches" | "entur-log" | "realtime" | "events" }
  | { readonly kind: "admin"; readonly page: "database"; readonly databaseView: "schema" | "migrations" }
  | { readonly kind: "scenario"; readonly scenario: string }
  | { readonly kind: "scenario-index" };

export function parseRoute(location: Pick<Location, "pathname" | "search">): AppRoute {
  const segments = location.pathname.split("/").filter(Boolean);
  if (segments[0] === "__scenarios") return { kind: "scenario-index" };

  if (segments[0] === "__scenario" && segments[1] !== undefined) {
    const raw = decodeURIComponent(segments[1]);
    return { kind: "scenario", scenario: raw };
  }

  const queryScenario = new URLSearchParams(location.search).get("scenario");
  if (queryScenario !== null) {
    return { kind: "scenario", scenario: queryScenario };
  }

  if (segments[0] === "admin") {
    const page = segments[1];
    if (page === "database") return { kind: "admin", page: "database", databaseView: segments[2] === "migrations" ? "migrations" : "schema" };
    if (page === "migrations") return { kind: "admin", page: "database", databaseView: "migrations" };
    if (page === "infrastructure" || page === "watches" || page === "entur-log" || page === "realtime" || page === "events") return { kind: "admin", page };
    return { kind: "admin", page: "status" };
  }

  return { kind: "public" };
}
