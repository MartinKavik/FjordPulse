import { createSignal, getOwner, onCleanup, type Accessor } from "solid-js";

export type AppRoute =
  | { readonly kind: "public" }
  | { readonly kind: "admin"; readonly page: "status" | "infrastructure" | "watches" | "entur-log" | "realtime" | "events" }
  | { readonly kind: "admin"; readonly page: "database"; readonly databaseView: "schema" | "migrations" }
  | { readonly kind: "scenario"; readonly scenario: string }
  | { readonly kind: "scenario-index" };

export interface BrowserNavigationOptions {
  readonly replace?: boolean;
}

export interface BrowserRouter {
  readonly route: Accessor<AppRoute>;
  readonly navigate: (href: string, options?: BrowserNavigationOptions) => boolean;
  readonly dispose: () => void;
}

type BrowserRouteWindow = Pick<Window, "location" | "history" | "addEventListener" | "removeEventListener">;

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

function routesEqual(left: AppRoute, right: AppRoute): boolean {
  if (left.kind !== right.kind) return false;
  if (left.kind === "admin" && right.kind === "admin") {
    if (left.page !== right.page) return false;
    return left.page !== "database" || (right.page === "database" && left.databaseView === right.databaseView);
  }
  if (left.kind === "scenario" && right.kind === "scenario") return left.scenario === right.scenario;
  return true;
}

/**
 * Keeps application routing reactive while retaining real URLs for direct links,
 * reloads, and browser history. Navigation outside the current origin is left to
 * ordinary browser links by returning false.
 */
export function createBrowserRouter(
  browser: BrowserRouteWindow | null = typeof window === "undefined" ? null : window,
): BrowserRouter {
  const [route, setRoute] = createSignal<AppRoute>(
    browser === null ? { kind: "public" } : parseRoute(browser.location),
  );

  const syncFromLocation = () => {
    if (browser === null) return;
    const next = parseRoute(browser.location);
    if (!routesEqual(route(), next)) setRoute(next);
  };

  const onPopState = () => syncFromLocation();
  browser?.addEventListener("popstate", onPopState);

  let disposed = false;
  const dispose = () => {
    if (disposed) return;
    disposed = true;
    browser?.removeEventListener("popstate", onPopState);
  };
  if (getOwner() !== null) onCleanup(dispose);

  const navigate = (href: string, options: BrowserNavigationOptions = {}): boolean => {
    if (browser === null || disposed) return false;

    let target: URL;
    try {
      target = new URL(href, browser.location.href);
    } catch {
      return false;
    }
    if (target.origin !== browser.location.origin) return false;

    const current = `${browser.location.pathname}${browser.location.search}${browser.location.hash}`;
    const destination = `${target.pathname}${target.search}${target.hash}`;
    if (destination !== current) {
      const updateHistory = options.replace === true ? browser.history.replaceState : browser.history.pushState;
      updateHistory.call(browser.history, null, "", destination);
    }
    syncFromLocation();
    return true;
  };

  return { route, navigate, dispose };
}
