import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createBrowserRouter } from "../src/state/routing";

describe("browser routing", () => {
  beforeEach(() => window.history.replaceState(null, "", "/"));
  afterEach(() => window.history.replaceState(null, "", "/"));

  it("starts from a deep link and navigates without replacing the document", () => {
    window.history.replaceState(null, "", "/admin/database/migrations");
    const pushState = vi.spyOn(window.history, "pushState");
    const replaceState = vi.spyOn(window.history, "replaceState");
    const router = createBrowserRouter(window);

    expect(router.route()).toEqual({ kind: "admin", page: "database", databaseView: "migrations" });
    expect(router.navigate("/admin/events")).toBe(true);
    expect(window.location.pathname).toBe("/admin/events");
    expect(router.route()).toEqual({ kind: "admin", page: "events" });
    expect(pushState).toHaveBeenCalledWith(null, "", "/admin/events");

    expect(router.navigate("/admin/infrastructure", { replace: true })).toBe(true);
    expect(window.location.pathname).toBe("/admin/infrastructure");
    expect(router.route()).toEqual({ kind: "admin", page: "infrastructure" });
    expect(replaceState).toHaveBeenLastCalledWith(null, "", "/admin/infrastructure");

    router.dispose();
  });

  it("reacts to popstate and stops reacting after disposal", () => {
    window.history.replaceState(null, "", "/admin/status");
    const router = createBrowserRouter(window);

    window.history.replaceState(null, "", "/admin/database/schema");
    window.dispatchEvent(new PopStateEvent("popstate"));
    expect(router.route()).toEqual({ kind: "admin", page: "database", databaseView: "schema" });

    router.dispose();
    window.history.replaceState(null, "", "/admin/events");
    window.dispatchEvent(new PopStateEvent("popstate"));
    expect(router.route()).toEqual({ kind: "admin", page: "database", databaseView: "schema" });
    expect(router.navigate("/admin/status")).toBe(false);
  });

  it("does not create duplicate route state or intercept another origin", () => {
    window.history.replaceState(null, "", "/admin/status");
    const router = createBrowserRouter(window);
    const initialRoute = router.route();

    expect(router.navigate("/admin/overview")).toBe(true);
    expect(window.location.pathname).toBe("/admin/overview");
    expect(router.route()).toBe(initialRoute);
    expect(router.navigate("https://example.com/admin/events")).toBe(false);
    expect(window.location.pathname).toBe("/admin/overview");
    expect(router.navigate("http://[invalid")).toBe(false);

    router.dispose();
  });

  it("has a safe inert route when no browser is available", () => {
    const router = createBrowserRouter(null);

    expect(router.route()).toEqual({ kind: "public" });
    expect(router.navigate("/admin/status")).toBe(false);
    expect(() => router.dispose()).not.toThrow();
  });
});
