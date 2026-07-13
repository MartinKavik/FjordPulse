import { cleanup, fireEvent, render, screen, waitFor, within } from "@solidjs/testing-library";
import { createSignal, type Component, type JSX } from "solid-js";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AdminApp, type AdminPage } from "../src/components/Admin";
import { adminStatusFixture } from "../src/fixtures/scenarios";
import type { HttpClient } from "../src/services/httpClient";
import { I18nProvider } from "../src/state/i18n";
import type { RealtimeEventRow, WatchRow } from "../src/types/domain";

const EnglishWrapper: Component<{ readonly children: JSX.Element }> = (props) => (
  <I18nProvider initialLanguage="en">{props.children}</I18nProvider>
);

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

afterEach(cleanup);

describe("Admin same-document navigation", () => {
  it("keeps the authenticated shell mounted through loading, error, and retry", async () => {
    const watches = deferred<readonly WatchRow[]>();
    const http = {
      getAdminSession: vi.fn().mockResolvedValue({ authenticated: true, username: "admin", access: "operator", expiresAt: "2026-07-14T20:00:00Z" }),
      getAdminStatus: vi.fn().mockResolvedValue(adminStatusFixture),
      getAdminWatches: vi.fn().mockReturnValueOnce(watches.promise).mockResolvedValueOnce([]),
    } as unknown as HttpClient;
    const [page, setPage] = createSignal<AdminPage>("status");

    render(() => <AdminApp page={page()} fixture={false} http={http} />, { wrapper: EnglishWrapper });

    expect(await screen.findByRole("heading", { name: "System status" })).toBeVisible();
    const shell = document.querySelector<HTMLElement>(".admin-shell");
    expect(shell).not.toBeNull();

    setPage("watches");
    await waitFor(() => expect(http.getAdminWatches).toHaveBeenCalledTimes(1));
    expect(document.querySelector(".admin-shell")).toBe(shell);
    expect(within(shell!).getByRole("progressbar", { name: "Loading Admin page" })).toBeVisible();
    expect(within(shell!).getByRole("heading", { name: "System status" })).toBeVisible();
    expect(within(shell!).getByRole("heading", { name: "System status" }).closest(".admin-page-content")).toHaveProperty("inert", true);
    expect(within(shell!).getByRole("link", { name: "Active watches" })).toHaveAttribute("aria-current", "page");
    expect(within(shell!).getByText("Signed in as")).toBeVisible();
    expect(within(shell!).getByRole("button", { name: "Log out admin" })).toBeVisible();

    watches.reject(new TypeError("Failed to fetch"));
    const errorHeading = await within(shell!).findByRole("heading", { name: "Admin page unavailable" });
    expect(errorHeading).toBeVisible();
    await waitFor(() => expect(errorHeading).toHaveFocus());
    expect(document.querySelector(".admin-shell")).toBe(shell);
    expect(within(shell!).getByText("Could not connect to the FjordPulse server. Check your connection and try again.")).toBeVisible();

    await fireEvent.click(within(shell!).getByRole("button", { name: "Retry" }));
    expect(await within(shell!).findByRole("heading", { name: "Active watches" })).toBeVisible();
    expect(document.querySelector(".admin-shell")).toBe(shell);
    expect(http.getAdminSession).toHaveBeenCalledTimes(1);
    expect(http.getAdminWatches).toHaveBeenCalledTimes(2);
  });

  it("keeps the newest route when an older request settles later", async () => {
    const watches = deferred<readonly WatchRow[]>();
    const events = deferred<readonly RealtimeEventRow[]>();
    const http = {
      getAdminSession: vi.fn().mockResolvedValue({ authenticated: true, username: "admin", access: "operator", expiresAt: "2026-07-14T20:00:00Z" }),
      getAdminStatus: vi.fn().mockResolvedValue(adminStatusFixture),
      getAdminWatches: vi.fn().mockReturnValue(watches.promise),
      getAdminEvents: vi.fn().mockReturnValue(events.promise),
    } as unknown as HttpClient;
    const [page, setPage] = createSignal<AdminPage>("status");

    render(() => <AdminApp page={page()} fixture={false} http={http} />, { wrapper: EnglishWrapper });
    expect(await screen.findByRole("heading", { name: "System status" })).toBeVisible();
    const shell = document.querySelector<HTMLElement>(".admin-shell");

    setPage("watches");
    await waitFor(() => expect(http.getAdminWatches).toHaveBeenCalledTimes(1));
    setPage("events");
    await waitFor(() => expect(http.getAdminEvents).toHaveBeenCalledTimes(1));
    events.resolve([]);

    expect(await screen.findByRole("heading", { name: "Persisted realtime events" })).toBeVisible();
    expect(screen.getByRole("link", { name: "Persisted events" })).toHaveAttribute("aria-current", "page");
    watches.resolve([]);
    await Promise.resolve();
    await Promise.resolve();

    expect(screen.getByRole("heading", { name: "Persisted realtime events" })).toBeVisible();
    expect(screen.queryByRole("heading", { name: "Active watches" })).not.toBeInTheDocument();
    expect(document.querySelector(".admin-shell")).toBe(shell);
  });

  it("intercepts only ordinary same-origin Admin link activation", async () => {
    const navigate = vi.fn(() => true);
    render(() => <AdminApp page="status" fixture http={{} as HttpClient} fixtureData={{
      status: adminStatusFixture,
      watches: [],
      enturLog: { metrics: { requestsPerMinute: 0, cacheHitRate: 0, p95LatencyMs: null, inBackoff: false }, entries: [] },
      databaseSchema: { readOnly: true, checkedAt: "2026-07-14T12:00:00Z", tables: [] },
      databaseMigrations: { readOnly: true, checkedAt: "2026-07-14T12:00:00Z", state: "in_sync", counts: { applied: 0, pending: 0, checksumMismatch: 0, orphaned: 0, failed: 0 }, lastAppliedAt: null, migrations: [] },
    }} onNavigate={navigate} />, { wrapper: EnglishWrapper });

    const infrastructure = await screen.findByRole("link", { name: "Infrastructure" });
    document.addEventListener("click", (event) => event.preventDefault(), { once: true });
    await fireEvent.click(infrastructure, { ctrlKey: true });
    expect(navigate).not.toHaveBeenCalled();
    await fireEvent.click(infrastructure);
    expect(navigate).toHaveBeenCalledOnce();
    expect(navigate).toHaveBeenCalledWith("/admin/infrastructure");
  });
});
