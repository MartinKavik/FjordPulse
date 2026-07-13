import { cleanup, fireEvent, render, screen, within } from "@solidjs/testing-library";
import type { Component, JSX } from "solid-js";
import { afterEach, describe, expect, it } from "vitest";
import { DatabaseMigrationsPage, DatabaseSchemaPage } from "../src/components/Admin";
import { adminDatabaseMigrationStatesFixture, adminDatabaseSchemaFixture } from "../src/fixtures/scenarios";
import { I18nProvider } from "../src/state/i18n";

const EnglishWrapper: Component<{ readonly children: JSX.Element }> = (props) => (
  <I18nProvider initialLanguage="en">{props.children}</I18nProvider>
);

function renderEnglish(view: () => JSX.Element) {
  return render(view, { wrapper: EnglishWrapper });
}

afterEach(cleanup);

describe("read-only database admin", () => {
  it("inspects the effective schema without implying browser database access", async () => {
    renderEnglish(() => <DatabaseSchemaPage data={adminDatabaseSchemaFixture} onRefresh={() => undefined} />);

    expect(screen.getByRole("heading", { name: "Database", level: 1 })).toBeVisible();
    expect(screen.getByLabelText("Read-only database view")).toHaveTextContent("This page cannot run queries, edit the schema, or apply migrations.");
    expect(screen.getByRole("link", { name: /Current schema/ })).toHaveAttribute("href", "/admin/database/schema");
    expect(screen.getByRole("link", { name: /Current schema/ })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: /Migrations/ })).toHaveAttribute("href", "/admin/database/migrations");
    expect(screen.getByText(/record and API rules/i)).toBeVisible();
    expect(screen.getByText(/database-scoped EDITOR connection/i)).toBeVisible();
    expect(screen.getByText(/browser never connects directly/i)).toBeVisible();

    const vehicleSummary = screen.getByText("current_vehicle").closest("summary");
    expect(vehicleSummary).not.toBeNull();
    await fireEvent.click(vehicleSummary!);
    const vehicleDetails = vehicleSummary!.parentElement!;
    expect(vehicleDetails).toHaveAttribute("open");
    expect(within(vehicleDetails).getByRole("heading", { name: "Permissions" })).toBeVisible();
    expect(within(vehicleDetails).getAllByText("None")).toHaveLength(4);
    expect(within(vehicleDetails).getByText("current_vehicle_vehicle_id_unique")).toBeVisible();
    expect(within(vehicleDetails).getByText("publish_current_vehicle")).toBeVisible();

    const filter = screen.getByRole("searchbox", { name: "Filter tables or fields" });
    await fireEvent.input(filter, { target: { value: "event_id" } });
    expect(screen.queryByText("current_vehicle")).not.toBeInTheDocument();
    expect(screen.getByText("realtime_event")).toBeVisible();

    await fireEvent.input(filter, { target: { value: "missing schema object" } });
    expect(screen.getByText("No tables or fields match this search.")).toBeVisible();
    expect(screen.getByText(/Use Surrealist through the private operator connection/)).toBeVisible();
  });

  it("shows every migration state and opens the most serious issue", async () => {
    renderEnglish(() => <DatabaseMigrationsPage data={adminDatabaseMigrationStatesFixture} onRefresh={() => undefined} />);

    expect(screen.getByRole("heading", { name: "A migration failed" })).toBeVisible();
    expect(screen.getByText("Checksum mismatch", { selector: ".migration-state" })).toBeVisible();
    expect(screen.getByText("Database only", { selector: ".migration-state" })).toBeVisible();
    expect(screen.getByText("Pending", { selector: ".migration-state" })).toBeVisible();
    expect(screen.getByText("Applied", { selector: ".migration-state" })).toBeVisible();
    expect(screen.getByText("Failed", { selector: ".migration-state" })).toBeVisible();

    const failedSummary = screen.getByText("013_failed_journey_event.surql").closest("summary");
    expect(failedSummary?.parentElement).toHaveAttribute("open");
    expect(screen.getByText(/transaction rolled back/i)).toBeVisible();
    expect(screen.getByLabelText("Read-only source for 013_failed_journey_event.surql")).toBeVisible();
    expect(screen.getByText("journey_snapshot.publish_journey_snapshot")).toBeVisible();

    const driftedSummary = screen.getByText("011_drifted_vehicle_index.surql").closest("summary");
    await fireEvent.click(driftedSummary!);
    const driftedDetails = driftedSummary!.parentElement!;
    expect(within(driftedDetails).getByText(adminDatabaseMigrationStatesFixture.migrations[2]!.releaseChecksum!)).toBeVisible();
    expect(within(driftedDetails).getByText(adminDatabaseMigrationStatesFixture.migrations[2]!.databaseChecksum!)).toBeVisible();

    const orphanedSummary = screen.getByText("012_database_only_legacy.surql").closest("summary");
    await fireEvent.click(orphanedSummary!);
    expect(within(orphanedSummary!.parentElement!).getByText(/release has no source file/i)).toBeVisible();
    expect(screen.queryByRole("button", { name: /apply|execute|edit|run migration/i })).not.toBeInTheDocument();
  });

  it("keeps the complete database surface bilingual", async () => {
    render(() => <I18nProvider initialLanguage="nb"><DatabaseMigrationsPage data={adminDatabaseMigrationStatesFixture} onRefresh={() => undefined} /></I18nProvider>);

    expect(screen.getByText("SKRIVEBESKYTTET")).toBeVisible();
    expect(screen.getByRole("link", { name: /Gjeldende skjema/ })).toBeVisible();
    expect(screen.getByText("VERSJONSSAMSVAR")).toBeVisible();
    expect(screen.getByRole("heading", { name: "En migrering mislyktes" })).toBeVisible();
    expect(screen.getByText("Kontrollsum avviker", { selector: ".migration-state" })).toBeVisible();
    expect(screen.getByText("Bare i databasen", { selector: ".migration-state" })).toBeVisible();
    expect(screen.getAllByText("definer", { selector: ".object-operation" }).length).toBeGreaterThan(0);
    expect(screen.getByText("hendelse")).toBeVisible();
    expect(screen.getByText(/Bruk Surrealist via den private operatørtilkoblingen/)).toBeVisible();

    await fireEvent.click(screen.getByRole("button", { name: "Bytt språk til engelsk" }));
    expect(screen.getByRole("heading", { name: "A migration failed" })).toBeVisible();
  });
});
