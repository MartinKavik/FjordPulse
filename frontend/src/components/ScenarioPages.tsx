import { For, type Component } from "solid-js";
import {
  VISUAL_SCENARIO_IDS,
  freshStationSnapshot,
  line100Vehicle,
  type VisualScenarioId,
} from "../fixtures/scenarios";
import { Button, DepartureRow, FeedbackBanner, FjordPulseLogo, FocusPill, SkeletonRows, StatusChip, TelemetryStrip, VehicleRow } from "./DesignSystem";
import { Icon } from "./Icon";

function scenarioCategory(id: VisualScenarioId): string {
  if (id.startsWith("desktop_")) return "Desktop public app";
  if (id.startsWith("mobile_")) return "Mobile public app";
  if (id.startsWith("admin_")) return "Admin console";
  return "Design system";
}

export const ScenarioIndex: Component = () => (
  <main class="scenario-index">
    <header><FjordPulseLogo /><span class="eyebrow">DETERMINISTIC VISUAL ROUTES</span><h1>FjordPulse scenario gallery</h1><p>Every approved visual state has a stable URL for Playwright and human review.</p></header>
    <div class="scenario-grid"><For each={VISUAL_SCENARIO_IDS}>{(id, index) => <a href={`/__scenario/${id}`}><span>{String(index() + 1).padStart(2, "0")}</span><div><strong>{id.replaceAll("_", " ")}</strong><small>{scenarioCategory(id)}</small></div><Icon name="chevron" size={18} /></a>}</For></div>
  </main>
);

export const DesignSystemPage: Component = () => (
  <main class="design-board">
    <header><div><span class="eyebrow">FJORDPULSE UI</span><h1>Design system</h1></div><p>Typed, reusable components for a trustworthy realtime transport explorer.</p></header>
    <section class="design-section topbar-sample"><h2><span>01</span>Brand & top bar</h2><div class="sample-topbar"><FjordPulseLogo /><div class="sample-search"><Icon name="search" size={20} />Search for station, place, line…<kbd>⌘ K</kbd></div><StatusChip state="connected" label="Live connected" /></div></section>
    <section class="design-section"><h2><span>02</span>Status language</h2><div class="sample-row"><StatusChip state="connected" label="Live connected" /><StatusChip state="delayed" label="Live delayed" /><StatusChip state="offline" label="Error" /><StatusChip state="idle" label="Realtime idle" /></div></section>
    <section class="design-section markers-sample"><h2><span>03</span>Map markers</h2><div class="sample-row"><div class="sample-marker"><Icon name="bus" size={20} /><small>Station</small></div><div class="sample-marker selected"><Icon name="bus" size={20} /><small>Selected</small></div><div class="sample-cluster">18<small>Cluster</small></div><div class="sample-marker vehicle"><Icon name="bus" size={20} /><small>Vehicle</small></div><div class="sample-marker stale"><Icon name="bus" size={20} /><small>Stale</small></div></div></section>
    <section class="design-section list-sample"><h2><span>04</span>Transport rows</h2><div class="component-grid"><DepartureRow departure={freshStationSnapshot.departures[1]!} /><DepartureRow departure={freshStationSnapshot.departures[0]!} /><DepartureRow departure={{ ...freshStationSnapshot.departures[2]!, status: "cancelled" }} /><VehicleRow vehicle={freshStationSnapshot.nearbyVehicles[0]!} /></div></section>
    <section class="design-section"><h2><span>05</span>Focus & actions</h2><div class="sample-row action-sample"><Button tone="primary" icon="focus">Focus vehicle</Button><Button icon="pause">Pause follow</Button><Button tone="danger" icon="close">Stop following</Button><FocusPill line={line100Vehicle.lineCode ?? "100"} lastSeenAt={line100Vehicle.lastSeenAt} paused={false} onPause={() => undefined} onResume={() => undefined} onUnfocus={() => undefined} /></div></section>
    <section class="design-section feedback-sample"><h2><span>06</span>Feedback & loading</h2><div class="component-grid"><FeedbackBanner tone="warning" title="Data is stale">Showing last known transport data from 2 min ago.</FeedbackBanner><FeedbackBanner tone="danger" title="Connection error">Realtime is unavailable. Periodic refresh continues.</FeedbackBanner><SkeletonRows count={2} /></div></section>
    <section class="design-section telemetry-sample"><h2><span>07</span>System telemetry</h2><TelemetryStrip telemetry={{ backend: "ok", realtime: "connected", entur: "ok", liveQueryBridge: "connected", refreshMode: "realtime", lastUpdateAt: "2026-07-10T18:42:22Z" }} /></section>
  </main>
);
