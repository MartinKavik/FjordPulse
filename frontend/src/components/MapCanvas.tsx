import { createEffect, createSignal, onCleanup, onMount, Show, For, type Component } from "solid-js";
import maplibregl, { type GeoJSONSource, type GeoJSONSourceSpecification, type LayerSpecification, type Map as MapLibreMap, type StyleSpecification } from "maplibre-gl";
import type { BasemapId, BasemapStyle, FocusState, JourneySnapshot, MapConfig, MapItem, MapLoadState, StationSnapshot, VehicleState } from "../types/domain";
import { ApiClientError, fjordPulseHttp } from "../services/httpClient";
import { applyMapTilerCartography, type MapTilerCartographyStatus } from "../services/mapCartography";
import { initialBasemap, rememberBasemap, styleUrlFor } from "../services/mapStyle";
import { localize, useI18n, type Language } from "../state/i18n";
import { Icon } from "./Icon";
import { vehicleModeIcon, vehicleModeLabel } from "./VehicleMode";

const localStyle: StyleSpecification = {
  version: 8,
  name: "FjordPulse deterministic local map",
  sources: {
    geography: {
      type: "geojson",
      data: {
        type: "FeatureCollection",
        features: [
          {
            type: "Feature",
            properties: { kind: "land" },
            geometry: {
              type: "Polygon",
              coordinates: [[
                [4.5, 58], [5.2, 59.4], [4.9, 60.6], [5.2, 62], [6.1, 63.1], [7.4, 64], [10, 65],
                [12.5, 66.2], [14, 67.5], [16.5, 68.7], [20, 69.2], [25.5, 70.4], [29.5, 70.9],
                [30.7, 69.5], [26, 68.8], [22, 67.6], [18, 66], [15, 64.8], [12.4, 63.2], [11.2, 61.8],
                [10.6, 59.2], [8.1, 58], [4.5, 58],
              ]],
            },
          },
          {
            type: "Feature",
            properties: { kind: "route" },
            geometry: { type: "LineString", coordinates: [[5.2, 60.3], [5.9, 61.45], [6.2, 61.75], [6.15, 62.3], [7.2, 63.1], [10.4, 63.43]] },
          },
          {
            type: "Feature",
            properties: { kind: "route" },
            geometry: { type: "LineString", coordinates: [[10.75, 59.91], [9.8, 61], [10.4, 63.43], [14.4, 65], [18.95, 69.65]] },
          },
        ],
      },
    },
    "fjordpulse-transport": { type: "geojson", data: { type: "FeatureCollection", features: [] } },
  },
  layers: [
    { id: "background", type: "background", paint: { "background-color": "#06131f" } },
    {
      id: "land",
      type: "fill",
      source: "geography",
      filter: ["==", ["get", "kind"], "land"],
      paint: { "fill-color": "#0b2634", "fill-outline-color": "#285064", "fill-opacity": 0.88 },
    },
    {
      id: "routes-glow",
      type: "line",
      source: "geography",
      filter: ["==", ["get", "kind"], "route"],
      paint: { "line-color": "#21c4c7", "line-width": 5, "line-opacity": 0.1 },
    },
    {
      id: "routes",
      type: "line",
      source: "geography",
      filter: ["==", ["get", "kind"], "route"],
      paint: { "line-color": "#39d3c4", "line-width": 1.5, "line-opacity": 0.34 },
    },
    {
      id: "station-clusters",
      type: "circle",
      source: "fjordpulse-transport",
      filter: ["==", ["get", "kind"], "cluster"],
      paint: { "circle-radius": ["interpolate", ["linear"], ["zoom"], 3, 12, 10, 20], "circle-color": "#155ea8", "circle-stroke-color": "#7fc4ff", "circle-stroke-width": 2, "circle-opacity": 0.86 },
    },
    {
      id: "station-points",
      type: "circle",
      source: "fjordpulse-transport",
      filter: ["==", ["get", "kind"], "station"],
      paint: { "circle-radius": ["case", ["==", ["get", "selected"], true], 10, 6], "circle-color": ["case", ["==", ["get", "selected"], true], "#ffffff", "#35a9ef"], "circle-stroke-color": ["case", ["==", ["get", "selected"], true], "#2c91ff", "#d6f0ff"], "circle-stroke-width": ["case", ["==", ["get", "selected"], true], 4, 2] },
    },
  ],
};

const TRANSPORT_SOURCE_ID = "fjordpulse-transport";
const CLUSTER_LAYER_ID = "fjordpulse-station-clusters";
const CLUSTER_COUNT_LAYER_ID = "fjordpulse-station-cluster-counts";
const CLUSTER_HIT_LAYER_ID = "fjordpulse-station-cluster-hit-targets";
const STATION_LAYER_ID = "fjordpulse-station-points";
const SELECTED_STATION_HALO_LAYER_ID = "fjordpulse-selected-station-halo";
const SELECTED_STATION_LAYER_ID = "fjordpulse-selected-station";
const SELECTED_STATION_LABEL_LAYER_ID = "fjordpulse-selected-station-label";
const JOURNEY_ROUTE_CASING_LAYER_ID = "fjordpulse-journey-route-casing";
const JOURNEY_ROUTE_PASSED_LAYER_ID = "fjordpulse-journey-route-passed";
const JOURNEY_ROUTE_REMAINING_LAYER_ID = "fjordpulse-journey-route-remaining";
const VEHICLE_TRAIL_LAYER_ID = "fjordpulse-vehicle-trail";
const JOURNEY_STOP_LAYER_ID = "fjordpulse-journey-stops";
const JOURNEY_STOP_LABEL_LAYER_ID = "fjordpulse-journey-stop-labels";
const VEHICLE_HALO_LAYER_ID = "fjordpulse-vehicle-halo";
const VEHICLE_LAYER_ID = "fjordpulse-vehicle-marker";
export const PUBLIC_MAP_HASH_NAME = "map";

export type TransportData = Exclude<GeoJSONSourceSpecification["data"], string>;

const publicTransportLayers: readonly LayerSpecification[] = [
  {
    id: JOURNEY_ROUTE_CASING_LAYER_ID,
    type: "line",
    source: TRANSPORT_SOURCE_ID,
    filter: ["match", ["get", "kind"], ["journey-route-passed", "journey-route-remaining"], true, false],
    layout: { "line-cap": "round", "line-join": "round" },
    paint: { "line-color": "#06131f", "line-width": 8, "line-opacity": 0.9 },
  },
  {
    id: JOURNEY_ROUTE_PASSED_LAYER_ID,
    type: "line",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "journey-route-passed"],
    layout: { "line-cap": "round", "line-join": "round" },
    paint: { "line-color": "#71808b", "line-width": 4, "line-opacity": 0.72 },
  },
  {
    id: JOURNEY_ROUTE_REMAINING_LAYER_ID,
    type: "line",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "journey-route-remaining"],
    layout: { "line-cap": "round", "line-join": "round" },
    paint: { "line-color": "#25c9ff", "line-width": 4, "line-opacity": 0.96 },
  },
  {
    id: VEHICLE_TRAIL_LAYER_ID,
    type: "line",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "vehicle-trail"],
    layout: { "line-cap": "round", "line-join": "round" },
    paint: { "line-color": "#ffffff", "line-width": 3, "line-opacity": ["case", ["==", ["get", "muted"], true], 0.3, 0.86], "line-dasharray": [1.5, 2.25] },
  },
  {
    id: CLUSTER_LAYER_ID,
    type: "circle",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "cluster"],
    paint: {
      "circle-radius": ["step", ["get", "count"], 9, 20, 10.5, 200, 12, 2_000, 14, 10_000, 16],
      "circle-color": "#0b4f87",
      "circle-stroke-color": "#7fc9ff",
      "circle-stroke-width": 1.5,
      "circle-opacity": 0.62,
      "circle-stroke-opacity": 0.9,
    },
  },
  {
    id: CLUSTER_COUNT_LAYER_ID,
    type: "symbol",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "cluster"],
    layout: {
      "text-field": ["get", "countLabel"],
      "text-size": ["interpolate", ["linear"], ["zoom"], 3, 9, 10, 11],
      "text-allow-overlap": true,
      "text-ignore-placement": true,
    },
    paint: { "text-color": "#eaf7ff", "text-halo-color": "#07345c", "text-halo-width": 1 },
  },
  {
    id: CLUSTER_HIT_LAYER_ID,
    type: "circle",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "cluster"],
    paint: { "circle-radius": 18, "circle-color": "#000000", "circle-opacity": 0.01 },
  },
  {
    id: STATION_LAYER_ID,
    type: "circle",
    source: TRANSPORT_SOURCE_ID,
    filter: ["all", ["==", ["get", "kind"], "station"], ["!=", ["get", "selected"], true]],
    paint: { "circle-radius": 4.5, "circle-color": "#35a9ef", "circle-stroke-color": "#d6f0ff", "circle-stroke-width": 1.5, "circle-opacity": 0.72 },
  },
  {
    id: SELECTED_STATION_HALO_LAYER_ID,
    type: "circle",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "selected-station"],
    paint: { "circle-radius": 18, "circle-color": "#2c91ff", "circle-opacity": 0.28, "circle-blur": 0.25 },
  },
  {
    id: SELECTED_STATION_LAYER_ID,
    type: "circle",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "selected-station"],
    paint: { "circle-radius": 10, "circle-color": "#ffffff", "circle-stroke-color": "#2c91ff", "circle-stroke-width": 4 },
  },
  {
    id: SELECTED_STATION_LABEL_LAYER_ID,
    type: "symbol",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "selected-station"],
    layout: { "text-field": ["get", "name"], "text-size": 12, "text-offset": [0, 2], "text-allow-overlap": true, "text-ignore-placement": true },
    paint: { "text-color": "#ffffff", "text-halo-color": "#03101b", "text-halo-width": 1.5 },
  },
  {
    id: JOURNEY_STOP_LAYER_ID,
    type: "circle",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "journey-stop"],
    paint: {
      "circle-radius": ["match", ["get", "role"], ["current"], 7, ["next"], 6, ["start", "end"], 5, 3],
      "circle-color": ["match", ["get", "role"], ["current"], "#ffffff", ["next"], "#25c9ff", ["start", "end"], "#f6d77a", "#b8cbd7"],
      "circle-stroke-color": "#06131f",
      "circle-stroke-width": 2,
      "circle-opacity": 0.95,
    },
  },
  {
    id: JOURNEY_STOP_LABEL_LAYER_ID,
    type: "symbol",
    source: TRANSPORT_SOURCE_ID,
    filter: ["match", ["get", "role"], ["start", "current", "next", "end"], true, false],
    layout: { "text-field": ["get", "name"], "text-font": ["Noto Sans Medium"], "text-size": 11, "text-offset": [0, 1.35], "text-anchor": "top", "text-optional": true },
    paint: { "text-color": "#ffffff", "text-halo-color": "#06131f", "text-halo-width": 1.5 },
  },
  {
    id: VEHICLE_HALO_LAYER_ID,
    type: "circle",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "vehicle"],
    paint: {
      "circle-radius": 18,
      "circle-color": ["case", ["==", ["get", "nonPassenger"], true], "#8092a0", "#1b8fff"],
      "circle-opacity": ["case", ["==", ["get", "muted"], true], 0.12, ["==", ["get", "nonPassenger"], true], 0.18, 0.25],
      "circle-blur": 0.35,
    },
  },
  {
    id: VEHICLE_LAYER_ID,
    type: "circle",
    source: TRANSPORT_SOURCE_ID,
    filter: ["==", ["get", "kind"], "vehicle"],
    paint: {
      "circle-radius": 11,
      "circle-color": ["case", ["==", ["get", "muted"], true], "#71808b", ["==", ["get", "nonPassenger"], true], "#597185", "#0877e6"],
      "circle-stroke-color": "#ffffff",
      "circle-stroke-width": 3,
      "circle-opacity": ["case", ["==", ["get", "lost"], true], 0.45, 1],
    },
  },
];

interface TransportLayerHost {
  getSource(id: string): unknown;
  addSource(id: string, source: GeoJSONSourceSpecification): unknown;
  getLayer(id: string): unknown;
  addLayer(layer: LayerSpecification, beforeId?: string): unknown;
  getStyle?(): StyleSpecification;
}

export function installTransportOverlays(target: TransportLayerHost, data: TransportData): void {
  if (target.getSource(TRANSPORT_SOURCE_ID) === undefined) target.addSource(TRANSPORT_SOURCE_ID, { type: "geojson", data });
  const contextLayerIds = new Set([
    JOURNEY_ROUTE_CASING_LAYER_ID,
    JOURNEY_ROUTE_PASSED_LAYER_ID,
    JOURNEY_ROUTE_REMAINING_LAYER_ID,
    VEHICLE_TRAIL_LAYER_ID,
    CLUSTER_LAYER_ID,
    CLUSTER_COUNT_LAYER_ID,
    CLUSTER_HIT_LAYER_ID,
    STATION_LAYER_ID,
  ]);
  const firstProviderSymbol = target.getStyle?.().layers.find((layer) => layer.type === "symbol" && !layer.id.startsWith("fjordpulse-"))?.id;
  for (const layer of publicTransportLayers) {
    if (target.getLayer(layer.id) !== undefined) continue;
    if (contextLayerIds.has(layer.id) && firstProviderSymbol !== undefined) target.addLayer(layer, firstProviderSymbol);
    else target.addLayer(layer);
  }
}

const markerPositions: Readonly<Record<string, readonly [number, number]>> = {
  "cluster-tromso": [66, 15],
  "cluster-trondheim": [56, 34],
  "cluster-forde": [37, 54],
  "cluster-bergen": [31, 66],
  "cluster-oslo": [63, 72],
  "cluster-stavanger": [36, 78],
  "NSR:StopPlace:36025": [43, 57],
  "NSR:StopPlace:58370": [65, 50],
  "NSR:StopPlace:58372": [55, 39],
};

export interface BasemapLayerPickerProps {
  readonly basemaps: readonly BasemapStyle[];
  readonly selected: BasemapId;
  readonly loading: boolean;
  readonly onSelect: (id: BasemapId) => void;
}

export const BasemapLayerPicker: Component<BasemapLayerPickerProps> = (props) => {
  const i18n = useI18n();
  let root: HTMLDivElement | undefined;
  let trigger: HTMLButtonElement | undefined;
  const [open, setOpen] = createSignal(false);

  const close = (restoreFocus: boolean) => {
    setOpen(false);
    if (restoreFocus) queueMicrotask(() => trigger?.focus());
  };

  onMount(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== "Escape" || !open()) return;
      event.preventDefault();
      close(true);
    };
    const onPointerDown = (event: PointerEvent) => {
      if (open() && event.target instanceof Node && root?.contains(event.target) === false) close(false);
    };
    document.addEventListener("keydown", onKeyDown);
    document.addEventListener("pointerdown", onPointerDown);
    onCleanup(() => {
      document.removeEventListener("keydown", onKeyDown);
      document.removeEventListener("pointerdown", onPointerDown);
    });
  });

  return (
    <div ref={root} class="map-layer-control">
      <button
        ref={trigger}
        type="button"
        class="map-control-button"
        aria-label={i18n.text({ nb: "Kartlag", en: "Map layers" })}
        aria-controls="basemap-picker"
        aria-expanded={open()}
        onClick={() => setOpen((current) => !current)}
      >
        <Icon name="layers" size={22} />
      </button>
      <Show when={open()}>
        <div id="basemap-picker" class="basemap-picker" aria-label={i18n.text({ nb: "Velg kartstil", en: "Choose map style" })}>
          <strong>{i18n.text({ nb: "Kartstil", en: "Map style" })}</strong>
          <div role="radiogroup" aria-label={i18n.text({ nb: "Bakgrunnskart", en: "Basemap" })}>
            <For each={props.basemaps}>{(basemap) => (
              <button
                type="button"
                role="radio"
                aria-checked={props.selected === basemap.id}
                class={props.selected === basemap.id ? "is-selected" : ""}
                disabled={props.loading}
                onClick={() => { props.onSelect(basemap.id); close(true); }}
              >
                <span class={`basemap-preview preview-${basemap.id}`} aria-hidden="true" />
                <span>
                  <strong>{basemap.id === "satellite" ? i18n.text({ nb: "Satellitt", en: "Satellite" }) : i18n.text({ nb: "Kart", en: "Map" })}</strong>
                  <small>{basemap.id === "satellite" ? i18n.text({ nb: "Flyfoto med stedsnavn", en: "Aerial imagery with labels" }) : i18n.text({ nb: "Veier og stedsnavn", en: "Roads and place names" })}</small>
                </span>
                <span class="radio-indicator" aria-hidden="true" />
              </button>
            )}</For>
          </div>
          <Show when={props.loading}><small class="basemap-switching" role="status">{i18n.text({ nb: "Bytter kart…", en: "Switching map…" })}</small></Show>
        </div>
      </Show>
    </div>
  );
};

export interface MapStatusOverlayProps {
  readonly state: MapLoadState;
  readonly basemap: BasemapId;
  readonly errorCode: string | null;
  readonly onRetry: () => void;
}

export const MapStatusOverlay: Component<MapStatusOverlayProps> = (props) => {
  const i18n = useI18n();
  return (
    <Show when={props.state !== "ready"}>
      <div class={`map-status-overlay state-${props.state}`} role={props.state === "error" ? "alert" : "status"} aria-live="polite">
        <Show when={props.state === "loading"}>
          <span class="spinner" aria-hidden="true" />
          <strong>{props.basemap === "satellite" ? i18n.text({ nb: "Laster satellittkart…", en: "Loading satellite map…" }) : i18n.text({ nb: "Laster kart…", en: "Loading map…" })}</strong>
        </Show>
        <Show when={props.state === "error"}>
          <Icon name="alert" size={24} />
          <div>
            <strong>{props.errorCode === "map_provider_misconfigured" ? i18n.text({ nb: "Karttjenesten er ikke konfigurert", en: "Map service is not configured" }) : i18n.text({ nb: "Kartet kunne ikke lastes", en: "The map could not be loaded" })}</strong>
            <p>{props.errorCode === "map_provider_misconfigured" ? i18n.text({ nb: "Dette er et problem med FjordPulse-tjenesten. Du trenger ingen API-nøkkel.", en: "This is a FjordPulse service problem. You do not need an API key." }) : i18n.text({ nb: "Kontroller tilkoblingen og prøv igjen.", en: "Check your connection and try again." })}</p>
          </div>
          <button type="button" onClick={props.onRetry}>{i18n.text({ nb: "Prøv igjen", en: "Retry" })}</button>
        </Show>
      </div>
    </Show>
  );
};

export interface MapCanvasProps {
  readonly items: readonly MapItem[];
  readonly station: StationSnapshot | null;
  readonly vehicle: VehicleState | null;
  readonly journey?: JourneySnapshot | null;
  readonly searchTarget?: { readonly longitude: number; readonly latitude: number; readonly requestId: number } | null;
  readonly focus: FocusState;
  readonly deterministic: boolean;
  readonly onSelectStation: (stationId: string) => void;
  readonly onSelectVehicle: (vehicleId: string) => void;
  readonly onManualMove: () => void;
  readonly onViewportChange: (bounds: readonly [number, number, number, number], zoom: number) => void;
}

type MapCoordinate = readonly [longitude: number, latitude: number];

export interface SplitRouteCoordinates {
  readonly passed: readonly MapCoordinate[];
  readonly remaining: readonly MapCoordinate[];
}

export const SELECTED_RESOURCE_MIN_ZOOM = 11;

export interface SelectionCameraTransition {
  readonly zoom: number;
}

/**
 * Keep a selection in its existing geographic context once the camera is at a
 * useful local scale. Overview cameras zoom in even when the point is technically
 * visible; off-screen resources pan into view without pulling a closer camera back.
 */
export function selectionCameraTransition(
  currentZoom: number,
  selectedPointVisible: boolean,
  selectionChanged: boolean,
): SelectionCameraTransition | null {
  if (!selectionChanged) return null;
  if (selectedPointVisible && currentZoom >= SELECTED_RESOURCE_MIN_ZOOM) return null;
  return { zoom: Math.max(currentZoom, SELECTED_RESOURCE_MIN_ZOOM) };
}

function isMapCoordinate(value: readonly number[]): value is MapCoordinate {
  const [longitude, latitude] = value;
  return value.length >= 2
    && Number.isFinite(longitude)
    && Number.isFinite(latitude)
    && longitude! >= -180
    && longitude! <= 180
    && latitude! >= -90
    && latitude! <= 90;
}

function validRouteCoordinates(journey: JourneySnapshot | null): readonly MapCoordinate[] {
  if (journey?.route === null || journey?.route === undefined) return [];
  return journey.route.coordinates.every((coordinate) => isMapCoordinate(coordinate)) ? journey.route.coordinates : [];
}

function segmentLength([firstLongitude, firstLatitude]: MapCoordinate, [secondLongitude, secondLatitude]: MapCoordinate): number {
  const averageLatitude = (firstLatitude + secondLatitude) * Math.PI / 360;
  const longitudeDistance = (secondLongitude - firstLongitude) * Math.cos(averageLatitude);
  return Math.hypot(longitudeDistance, secondLatitude - firstLatitude);
}

/** Split a route at a distance-based progress fraction, retaining the split point in both halves. */
export function splitRouteCoordinates(coordinates: readonly MapCoordinate[], progress: number | null | undefined): SplitRouteCoordinates {
  if (coordinates.length < 2) return { passed: [], remaining: coordinates };
  if (progress === null || progress === undefined || !Number.isFinite(progress) || progress <= 0) return { passed: [], remaining: coordinates };
  if (progress >= 1) return { passed: coordinates, remaining: [] };

  const lengths = coordinates.slice(1).map((coordinate, index) => segmentLength(coordinates[index]!, coordinate));
  const total = lengths.reduce((sum, length) => sum + length, 0);
  if (total <= 0) return { passed: [], remaining: coordinates };
  const target = total * progress;
  let traversed = 0;
  for (let index = 0; index < lengths.length; index += 1) {
    const length = lengths[index]!;
    if (traversed + length < target) {
      traversed += length;
      continue;
    }
    const start = coordinates[index]!;
    const end = coordinates[index + 1]!;
    const fraction = length <= 0 ? 0 : Math.max(0, Math.min(1, (target - traversed) / length));
    const split: MapCoordinate = [
      start[0] + (end[0] - start[0]) * fraction,
      start[1] + (end[1] - start[1]) * fraction,
    ];
    return {
      passed: [...coordinates.slice(0, index + 1), split],
      remaining: [split, ...coordinates.slice(index + 1)],
    };
  }
  return { passed: coordinates, remaining: [] };
}

interface TransportFeature {
  readonly type: "Feature";
  readonly geometry:
    | { readonly type: "Point"; readonly coordinates: readonly [number, number] }
    | { readonly type: "LineString"; readonly coordinates: readonly (readonly [number, number])[] };
  readonly properties: Readonly<Record<string, unknown>>;
}

export function compactClusterCount(count: number, language?: Language): string {
  if (count < 1_000) return String(count);
  const thousands = count / 1_000;
  const compact = String(thousands >= 10 ? Math.round(thousands) : Math.round(thousands * 10) / 10);
  return `${language === "nb" ? compact.replace(".", ",") : compact}k`;
}

export type VehicleMarkerLabelSide = "left" | "right";

export function vehicleMarkerLabelSide(screenX: number, viewportWidth: number): VehicleMarkerLabelSide {
  if (!Number.isFinite(screenX) || !Number.isFinite(viewportWidth) || viewportWidth <= 0) return "right";
  return viewportWidth - screenX < 155 ? "left" : "right";
}

export function buildTransportData(
  items: readonly MapItem[],
  selectedStationId: string | undefined,
  vehicle: VehicleState | null,
  suppliedJourney: JourneySnapshot | null | undefined,
  includeVehicle: boolean,
  selectedStation?: StationSnapshot | null,
  language: Language = "nb",
): TransportData {
  const features: TransportFeature[] = items.map((item) => ({
    type: "Feature",
    geometry: { type: "Point", coordinates: [item.longitude, item.latitude] },
    properties: item.kind === "cluster"
      ? { kind: item.kind, id: item.id, count: item.count, countLabel: compactClusterCount(item.count, language), bounds: JSON.stringify(item.bounds) }
      : { kind: item.kind, id: item.id, name: item.name, selected: item.id === selectedStationId },
  }));
  if (selectedStation !== null && selectedStation !== undefined) {
    features.push({
      type: "Feature",
      geometry: { type: "Point", coordinates: [selectedStation.station.longitude, selectedStation.station.latitude] },
      properties: { kind: "selected-station", id: selectedStation.stationId, name: selectedStation.station.name },
    });
  }
  const journey = suppliedJourney ?? vehicle?.journey ?? null;
  const routeCoordinates = validRouteCoordinates(journey);
  const includePassengerJourney = includeVehicle && vehicle !== null && vehicle.passengerServiceState !== "non_passenger";
  if (includePassengerJourney && vehicle !== null && routeCoordinates.length > 1) {
    const split = splitRouteCoordinates(routeCoordinates, vehicle.routeProgress);
    if (split.passed.length > 1) {
      features.push({
        type: "Feature",
        geometry: { type: "LineString", coordinates: split.passed },
        properties: { kind: "journey-route-passed", id: `${vehicle.id}:route:passed` },
      });
    }
    if (split.remaining.length > 1) {
      features.push({
        type: "Feature",
        geometry: { type: "LineString", coordinates: split.remaining },
        properties: { kind: "journey-route-remaining", id: `${vehicle.id}:route:remaining` },
      });
    }
  }
  if (includePassengerJourney && vehicle !== null && journey !== null) {
    const calls = journey.calls.filter((call) => call.longitude !== null && call.latitude !== null && isMapCoordinate([call.longitude, call.latitude]));
    const monitoredOrder = vehicle.monitoredCall?.order;
    const atStop = vehicle.monitoredCall?.vehicleAtStop === true;
    const reportedNext = vehicle.nextStop;
    const nextCall = reportedNext === null ? undefined : calls.find((call) => call.order === reportedNext.order
      && (reportedNext.quayId === null || call.quayId === reportedNext.quayId)
      && (reportedNext.stopPlaceId === null || call.stopPlaceId === reportedNext.stopPlaceId));
    const nextOrder = nextCall?.order ?? reportedNext?.order ?? (typeof monitoredOrder === "number"
      ? atStop ? calls.find((call) => call.order > monitoredOrder)?.order : monitoredOrder
      : undefined);
    calls.forEach((call, index) => {
      const terminalRole = index === 0 ? "start" : index === calls.length - 1 ? "end" : "stop";
      const role = typeof monitoredOrder === "number" && atStop && call.order === monitoredOrder
        ? "current"
        : call.order === nextOrder ? "next" : terminalRole;
      features.push({
        type: "Feature",
        geometry: { type: "Point", coordinates: [call.longitude!, call.latitude!] },
        properties: { kind: "journey-stop", id: call.quayId ?? call.stopPlaceId ?? `${vehicle.id}:call:${call.order}`, name: call.name, role, order: call.order },
      });
    });
    if (calls.length === 0 && routeCoordinates.length > 1) {
      const first = routeCoordinates[0]!;
      const last = routeCoordinates[routeCoordinates.length - 1]!;
      features.push(
        { type: "Feature", geometry: { type: "Point", coordinates: first }, properties: { kind: "journey-stop", id: `${vehicle.id}:route:start`, name: localize(language, { nb: "Rutestart", en: "Route start" }), role: "start" } },
        { type: "Feature", geometry: { type: "Point", coordinates: last }, properties: { kind: "journey-stop", id: `${vehicle.id}:route:end`, name: localize(language, { nb: "Ruteslutt", en: "Route end" }), role: "end" } },
      );
    }
  }
  if (includeVehicle && vehicle !== null && vehicle.longitude !== null && vehicle.latitude !== null) {
    if (vehicle.trail.length > 1) {
      features.push({
        type: "Feature",
        geometry: { type: "LineString", coordinates: vehicle.trail.map(({ longitude, latitude }) => [longitude, latitude] as const) },
        properties: { kind: "vehicle-trail", id: `${vehicle.id}:trail`, muted: vehicle.state !== "live" },
      });
    }
    features.push({
      type: "Feature",
      geometry: { type: "Point", coordinates: [vehicle.longitude, vehicle.latitude] },
      properties: {
        kind: "vehicle",
        id: vehicle.id,
        lineCode: vehicle.lineCode,
        passengerServiceState: vehicle.passengerServiceState,
        nonPassenger: vehicle.passengerServiceState === "non_passenger",
        muted: vehicle.state !== "live",
        lost: vehicle.state === "lost",
      },
    });
  }
  return { type: "FeatureCollection", features } as TransportData;
}

function mapLibreLocale(language: Language): Record<string, string> {
  return language === "nb" ? {
    "AttributionControl.ToggleAttribution": "Vis kartkilder",
    "AttributionControl.MapFeedback": "Tilbakemelding om kartet",
    "FullscreenControl.Enter": "Vis fullskjerm",
    "FullscreenControl.Exit": "Avslutt fullskjerm",
    "GeolocateControl.FindMyLocation": "Finn posisjonen min",
    "GeolocateControl.LocationNotAvailable": "Posisjonen er ikke tilgjengelig",
    "LogoControl.Title": "MapLibre-logo",
    "Map.Title": "Interaktivt kart over Norge",
    "Marker.Title": "Kartmarkør",
    "NavigationControl.ResetBearing": "Dra for å rotere kartet, klikk for å vende mot nord",
    "NavigationControl.ZoomIn": "Zoom inn",
    "NavigationControl.ZoomOut": "Zoom ut",
    "Popup.Close": "Lukk vindu",
    "GlobeControl.Enable": "Aktiver globus",
    "GlobeControl.Disable": "Deaktiver globus",
    "TerrainControl.Enable": "Aktiver terreng",
    "TerrainControl.Disable": "Deaktiver terreng",
    "CooperativeGesturesHandler.WindowsHelpText": "Bruk Ctrl og rullehjulet for å zoome i kartet",
    "CooperativeGesturesHandler.MacHelpText": "Bruk ⌘ og rullehjulet for å zoome i kartet",
    "CooperativeGesturesHandler.MobileHelpText": "Bruk to fingre for å flytte kartet",
  } : {
    "AttributionControl.ToggleAttribution": "Toggle attribution",
    "AttributionControl.MapFeedback": "Map feedback",
    "FullscreenControl.Enter": "Enter fullscreen",
    "FullscreenControl.Exit": "Exit fullscreen",
    "GeolocateControl.FindMyLocation": "Find my location",
    "GeolocateControl.LocationNotAvailable": "Location not available",
    "LogoControl.Title": "MapLibre logo",
    "Map.Title": "Interactive map of Norway",
    "Marker.Title": "Map marker",
    "NavigationControl.ResetBearing": "Drag to rotate map, click to reset north",
    "NavigationControl.ZoomIn": "Zoom in",
    "NavigationControl.ZoomOut": "Zoom out",
    "Popup.Close": "Close popup",
    "GlobeControl.Enable": "Enable globe",
    "GlobeControl.Disable": "Disable globe",
    "TerrainControl.Enable": "Enable terrain",
    "TerrainControl.Disable": "Disable terrain",
    "CooperativeGesturesHandler.WindowsHelpText": "Use Ctrl + scroll to zoom the map",
    "CooperativeGesturesHandler.MacHelpText": "Use ⌘ + scroll to zoom the map",
    "CooperativeGesturesHandler.MobileHelpText": "Use two fingers to move the map",
  };
}

export const MapCanvas: Component<MapCanvasProps> = (props) => {
  const i18n = useI18n();
  let container: HTMLDivElement | undefined;
  let map: MapLibreMap | null = null;
  let configAbortController: AbortController | null = null;
  let loadTimeout: ReturnType<typeof setTimeout> | null = null;
  let loadAttempt = 0;
  let styleLoadedAttempt = 0;
  let failedLoadAttempt: number | null = null;
  let interactionsAttached = false;
  let selectedStationId: string | null = props.station?.stationId ?? null;
  let selectedVehicleId: string | null = props.vehicle?.id ?? null;
  let appliedSearchTargetRequestId: number | null = null;
  let recentTileErrors: number[] = [];
  const [config, setConfig] = createSignal<MapConfig | null>(null);
  const [loadState, setLoadState] = createSignal<MapLoadState>("loading");
  const [errorCode, setErrorCode] = createSignal<string | null>(null);
  const [requestedBasemap, setRequestedBasemap] = createSignal<BasemapId>("satellite");
  const [cartographyStatus, setCartographyStatus] = createSignal<MapTilerCartographyStatus>("pending");
  const [styleRevision, setStyleRevision] = createSignal(0);
  const [stationScreen, setStationScreen] = createSignal<readonly [number, number] | null>(null);
  const [vehicleScreen, setVehicleScreen] = createSignal<readonly [number, number] | null>(null);
  const [trailScreen, setTrailScreen] = createSignal<readonly (readonly [number, number])[]>([]);

  const syncMapLibreLocale = (language: Language) => {
    if (map === null || container === undefined) return;
    const locale = mapLibreLocale(language);
    Object.assign(map._locale, locale);
    const labelControl = (selector: string, label: string) => {
      const element = container?.querySelector<HTMLElement>(selector);
      element?.setAttribute("aria-label", label);
      element?.setAttribute("title", label);
    };
    map.getCanvas().setAttribute("aria-label", locale["Map.Title"]!);
    labelControl(".maplibregl-ctrl-zoom-in", locale["NavigationControl.ZoomIn"]!);
    labelControl(".maplibregl-ctrl-zoom-out", locale["NavigationControl.ZoomOut"]!);
    labelControl(".maplibregl-ctrl-attrib-button", locale["AttributionControl.ToggleAttribution"]!);
  };

  const updateSelectionProjection = () => {
    const selectedStation = props.station;
    if (map === null || selectedStation === null) setStationScreen(null);
    else {
      const stationPoint = map.project([selectedStation.station.longitude, selectedStation.station.latitude]);
      setStationScreen([stationPoint.x, stationPoint.y]);
    }
    const current = props.vehicle;
    if (map === null || current === null || current.longitude === null || current.latitude === null) { setVehicleScreen(null); setTrailScreen([]); return; }
    const point = map.project([current.longitude, current.latitude]);
    setVehicleScreen([point.x, point.y]);
    setTrailScreen(current.trail.map((observation) => { const trailPoint = map!.project([observation.longitude, observation.latitude]); return [trailPoint.x, trailPoint.y] as const; }));
  };

  const reportViewport = () => {
    if (map === null || props.deterministic) return;
    const bounds = map.getBounds();
    props.onViewportChange(
      [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()],
      map.getZoom(),
    );
  };

  const clearLoadTimeout = () => {
    if (loadTimeout !== null) clearTimeout(loadTimeout);
    loadTimeout = null;
  };

  const failMap = (code: string) => {
    clearLoadTimeout();
    failedLoadAttempt = loadAttempt;
    setErrorCode(code);
    setLoadState("error");
  };

  const completeMapLoad = (attempt: number) => {
    if (attempt !== loadAttempt || styleLoadedAttempt !== attempt || failedLoadAttempt === attempt) return;
    clearLoadTimeout();
    setErrorCode(null);
    setLoadState("ready");
    recentTileErrors = [];
    if (!props.deterministic) rememberBasemap(requestedBasemap());
    reportViewport();
    updateSelectionProjection();
  };

  const beginLoadAttempt = (basemap: BasemapId): number => {
    loadAttempt += 1;
    const attempt = loadAttempt;
    setRequestedBasemap(basemap);
    setErrorCode(null);
    setLoadState("loading");
    failedLoadAttempt = null;
    recentTileErrors = [];
    clearLoadTimeout();
    loadTimeout = setTimeout(() => {
      if (attempt === loadAttempt) failMap("map_load_timeout");
    }, 15_000);
    return attempt;
  };

  const attachInteractions = () => {
    if (map === null || interactionsAttached) return;
    interactionsAttached = true;
    const stationLayers = props.deterministic ? ["station-points"] : [STATION_LAYER_ID, SELECTED_STATION_LAYER_ID];
    const clusterLayer = props.deterministic ? "station-clusters" : CLUSTER_HIT_LAYER_ID;
    for (const stationLayer of stationLayers) {
      map.on("click", stationLayer, (event) => {
        const id = event.features?.[0]?.properties.id as string | undefined;
        if (id !== undefined) props.onSelectStation(id);
      });
    }
    map.on("click", clusterLayer, (event) => {
      const raw = event.features?.[0]?.properties.bounds as string | undefined;
      if (raw === undefined) return;
      try {
        const bounds = JSON.parse(raw) as { minLongitude: number; minLatitude: number; maxLongitude: number; maxLatitude: number };
        map?.fitBounds([[bounds.minLongitude, bounds.minLatitude], [bounds.maxLongitude, bounds.maxLatitude]], { padding: 55, duration: 600 });
      } catch { /* Invalid server data is ignored after contract validation. */ }
    });
    if (!props.deterministic) {
      map.on("click", VEHICLE_LAYER_ID, (event) => {
        const id = event.features?.[0]?.properties.id as string | undefined;
        if (id !== undefined) props.onSelectVehicle(id);
      });
    }
    const pointerLayers = props.deterministic ? [...stationLayers, clusterLayer] : [...stationLayers, clusterLayer, VEHICLE_LAYER_ID];
    for (const layer of pointerLayers) {
      map.on("mouseenter", layer, () => { if (map !== null) map.getCanvas().style.cursor = "pointer"; });
      map.on("mouseleave", layer, () => { if (map !== null) map.getCanvas().style.cursor = ""; });
    }
  };

  const handleStyleLoad = () => {
    if (map === null) return;
    const data = buildTransportData(props.items, props.station?.stationId, props.vehicle, props.journey, !props.deterministic, props.station, i18n.language());
    try {
      if (props.deterministic) {
        (map.getSource(TRANSPORT_SOURCE_ID) as GeoJSONSource | undefined)?.setData(data);
      } else {
        setCartographyStatus(applyMapTilerCartography(map, requestedBasemap(), i18n.language()).status);
        installTransportOverlays(map, data);
      }
      attachInteractions();
      setStyleRevision((revision) => revision + 1);
    } catch {
      failMap("map_overlay_error");
      return;
    }
    styleLoadedAttempt = loadAttempt;
    const attempt = loadAttempt;
    map.once("idle", () => completeMapLoad(attempt));
  };

  const handleMapError = (error: Error) => {
    if (map === null) return;
    if (!map.isStyleLoaded()) {
      failMap("map_style_unavailable");
      return;
    }
    const now = Date.now();
    recentTileErrors = [...recentTileErrors.filter((at) => now - at < 10_000), now];
    if (recentTileErrors.length >= 3) failMap("map_tiles_unavailable");
    void error;
  };

  const createMap = (style: string | StyleSpecification) => {
    if (container === undefined) return;
    try {
      map = new maplibregl.Map({
        container,
        style,
        hash: props.deterministic ? false : PUBLIC_MAP_HASH_NAME,
        center: [10.2, 64.2],
        zoom: 3.6,
        minZoom: 2.8,
        maxZoom: 16,
        attributionControl: false,
        fadeDuration: props.deterministic ? 0 : 300,
        locale: mapLibreLocale(i18n.language()),
      });
      map.addControl(new maplibregl.NavigationControl({ showCompass: false }), "top-left");
      if (!props.deterministic) map.addControl(new maplibregl.AttributionControl({ compact: true }), "bottom-right");
      map.on("style.load", handleStyleLoad);
      map.on("error", (event) => handleMapError(event.error));
      map.on("move", updateSelectionProjection);
      map.on("moveend", reportViewport);
      map.on("resize", updateSelectionProjection);
      const manualMove = (event: maplibregl.MapMouseEvent | maplibregl.MapWheelEvent | maplibregl.MapTouchEvent) => {
        const target = event.originalEvent?.target;
        if (target instanceof Element && (target.closest(".maplibregl-canvas") !== null || target.closest(".maplibregl-ctrl") !== null)) props.onManualMove();
      };
      map.on("dragstart", manualMove);
      map.on("zoomstart", manualMove);
      syncMapLibreLocale(i18n.language());
    } catch {
      failMap("map_initialization_failed");
    }
  };

  const loadPublicConfiguration = async (preferredBasemap?: BasemapId) => {
    configAbortController?.abort();
    const controller = new AbortController();
    configAbortController = controller;
    let timedOut = false;
    const configTimeout = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, 15_000);
    setLoadState("loading");
    setErrorCode(null);
    try {
      const result = await fjordPulseHttp.getMapConfig(controller.signal);
      if (controller.signal.aborted) return;
      setConfig(result);
      const basemap = preferredBasemap === undefined ? initialBasemap(result) : preferredBasemap;
      beginLoadAttempt(basemap);
      const style = styleUrlFor(result, basemap);
      if (map === null) createMap(style);
      else map.setStyle(style, { diff: false });
    } catch (error) {
      if (controller.signal.aborted && !timedOut) return;
      const code = error instanceof ApiClientError && error.code === "map_provider_misconfigured" ? error.code : "map_config_unavailable";
      if (code === "map_provider_misconfigured") setConfig(null);
      failMap(code);
    } finally {
      clearTimeout(configTimeout);
      if (configAbortController === controller) configAbortController = null;
    }
  };

  const selectBasemap = (basemap: BasemapId) => {
    const currentConfig = config();
    if (currentConfig === null || (basemap === requestedBasemap() && loadState() !== "error")) return;
    if (map === null) {
      void loadPublicConfiguration(basemap);
      return;
    }
    beginLoadAttempt(basemap);
    try { map.setStyle(styleUrlFor(currentConfig, basemap), { diff: false }); }
    catch { failMap("map_style_unavailable"); }
  };

  const retry = () => {
    if (props.deterministic) return;
    void loadPublicConfiguration(requestedBasemap());
  };

  onMount(() => {
    if (props.deterministic) {
      beginLoadAttempt("satellite");
      createMap(localStyle);
    } else {
      void loadPublicConfiguration();
    }
  });

  createEffect(() => {
    styleRevision();
    const data = buildTransportData(props.items, props.station?.stationId, props.vehicle, props.journey, !props.deterministic, props.station, i18n.language());
    if (map === null || loadState() === "loading") return;
    (map.getSource(TRANSPORT_SOURCE_ID) as GeoJSONSource | undefined)?.setData(data);
  });

  createEffect(() => {
    const language = i18n.language();
    styleRevision();
    syncMapLibreLocale(language);
    if (map === null || props.deterministic || !map.isStyleLoaded()) return;
    setCartographyStatus(applyMapTilerCartography(map, requestedBasemap(), language).status);
  });

  createEffect(() => {
    styleRevision();
    const target = props.searchTarget;
    if (map === null || target === null || target === undefined || appliedSearchTargetRequestId === target.requestId) return;
    appliedSearchTargetRequestId = target.requestId;
    const coordinates: [number, number] = [target.longitude, target.latitude];
    const transition = selectionCameraTransition(map.getZoom(), map.getBounds().contains(coordinates), true);
    if (transition !== null) map.easeTo({ center: coordinates, zoom: transition.zoom, duration: 700 });
  });

  createEffect(() => {
    styleRevision();
    const station = props.station;
    const vehicle = props.vehicle;
    if (map === null) return;
    if (vehicle !== null && vehicle.longitude !== null && vehicle.latitude !== null) {
      selectedStationId = null;
      const selectionChanged = selectedVehicleId !== vehicle.id;
      selectedVehicleId = vehicle.id;
      const coordinates: [number, number] = [vehicle.longitude, vehicle.latitude];
      if (props.focus === "following") {
        map.easeTo({ center: coordinates, zoom: Math.max(map.getZoom(), 10.2), duration: 700 });
      } else {
        const transition = selectionCameraTransition(map.getZoom(), map.getBounds().contains(coordinates), selectionChanged);
        if (transition !== null) map.easeTo({ center: coordinates, zoom: transition.zoom, duration: 700 });
      }
    } else if (station !== null) {
      selectedVehicleId = null;
      const selectionChanged = selectedStationId !== station.stationId;
      selectedStationId = station.stationId;
      const coordinates: [number, number] = [station.station.longitude, station.station.latitude];
      const transition = selectionCameraTransition(map.getZoom(), map.getBounds().contains(coordinates), selectionChanged);
      if (transition !== null) map.easeTo({ center: coordinates, zoom: transition.zoom, duration: 650 });
    } else if (vehicle === null) {
      selectedStationId = null;
      selectedVehicleId = null;
    }
    updateSelectionProjection();
  });

  const showRouteOverview = () => {
    const route = validRouteCoordinates(props.journey ?? props.vehicle?.journey ?? null);
    if (map === null || route.length < 2) return;
    const first = route[0]!;
    const bounds = route.reduce((current, coordinate) => current.extend([coordinate[0], coordinate[1]]), new maplibregl.LngLatBounds([first[0], first[1]], [first[0], first[1]]));
    props.onManualMove();
    map.fitBounds(bounds, { padding: 70, duration: 700, maxZoom: 11 });
  };

  onCleanup(() => {
    configAbortController?.abort();
    clearLoadTimeout();
    map?.remove();
  });

  const position = (id: string): readonly [number, number] | null => markerPositions[id] ?? null;
  const selectedVehicleLabelSide = () => vehicleMarkerLabelSide(vehicleScreen()?.[0] ?? 0, container?.clientWidth ?? 0);
  const selectedVehicleNonPassenger = () => props.vehicle?.passengerServiceState === "non_passenger";

  return (
    <section
      class={`map-region ${props.deterministic ? "is-deterministic" : "is-public-map"}`}
      aria-label={i18n.text({ nb: "Interaktivt kart over Norge", en: "Interactive map of Norway" })}
      data-basemap={props.deterministic ? "fixture" : requestedBasemap()}
      data-map-state={loadState()}
      data-cartography={props.deterministic ? "fixture" : cartographyStatus()}
      data-map-error={errorCode() ?? ""}
    >
      <div ref={container} class="maplibre-canvas" />
      <Show when={props.deterministic}>
        <div class="map-texture" aria-hidden="true" />
        <div class="map-label norway-label" aria-hidden="true">{i18n.text({ nb: "NORGE", en: "NORWAY" })}</div>
        <div class="map-label sea-label" aria-hidden="true">{i18n.text({ nb: "Norskehavet", en: "Norwegian Sea" })}</div>
        <div class="map-markers">
          <For each={props.items}>{(item) => {
            const markerPosition = position(item.id);
            if (markerPosition === null) return null;
            const [left, top] = markerPosition;
            const selected = () => item.kind === "station" && props.station?.stationId === item.id;
            const clusterLabel = item.kind === "cluster" ? ({ "cluster-tromso": "Tromsø", "cluster-trondheim": "Trondheim", "cluster-forde": "Førde / Nordfjord", "cluster-bergen": "Bergen", "cluster-oslo": "Oslo", "cluster-stavanger": "Stavanger" } as const)[item.id as keyof typeof markerPositions] ?? i18n.text({ nb: "Holdeplassklynge", en: "Station cluster" }) : "";
            return item.kind === "cluster" ? (
              <button class={`cluster-marker ${item.id === "cluster-forde" ? "is-featured" : ""}`} style={{ left: `${left}%`, top: `${top}%` }} onClick={() => map?.fitBounds([[item.bounds.minLongitude, item.bounds.minLatitude], [item.bounds.maxLongitude, item.bounds.maxLatitude]], { padding: 55, duration: 600 })} aria-label={`${clusterLabel}, ${item.count} ${item.count === 1 ? i18n.text({ nb: "holdeplass", en: "station" }) : i18n.text({ nb: "holdeplasser", en: "stations" })}`}>
                <strong>{item.count}</strong><small>{clusterLabel}</small>
              </button>
            ) : (
              <button class={`station-marker ${selected() ? "is-selected" : ""}`} style={{ left: `${left}%`, top: `${top}%` }} onClick={() => props.onSelectStation(item.id)} aria-label={i18n.text({ nb: "Åpne {name}", en: "Open {name}" }, { name: item.name })}>
                <Icon name="bus" size={17} /><small>{item.name}</small>
              </button>
            );
          }}</For>
        </div>
      </Show>
      <Show when={props.station !== null && stationScreen() !== null}>
        <button
          class="selected-station-marker"
          type="button"
          style={{ left: `${stationScreen()?.[0] ?? 0}px`, top: `${stationScreen()?.[1] ?? 0}px` }}
          onClick={() => props.station !== null && props.onSelectStation(props.station.stationId)}
          aria-label={i18n.text({ nb: "Valgt holdeplass {name}", en: "Selected station {name}" }, { name: props.station?.station.name ?? i18n.text({ nb: "ukjent", en: "unknown" }) })}
        >
          <Icon name="pin" size={24} />
          <span>{props.station?.station.name}</span>
        </button>
      </Show>
      <Show when={props.deterministic && props.vehicle !== null && vehicleScreen() !== null}>
        <svg class={`vehicle-trail ${props.vehicle?.state !== "live" ? "is-muted" : ""}`} aria-hidden="true">
          <Show when={trailScreen().length > 1}><path d={trailScreen().map(([x, y], index) => `${index === 0 ? "M" : "L"}${x.toFixed(1)} ${y.toFixed(1)}`).join(" ")} /></Show>
          <For each={trailScreen()}>{([x, y], index) => <circle cx={x} cy={y} r={Math.min(5, 2.5 + index() * .45)} />}</For>
        </svg>
      </Show>
      <Show when={props.vehicle !== null && vehicleScreen() !== null}>
        <button
          class={`vehicle-marker state-${props.vehicle?.state ?? "live"} label-${selectedVehicleLabelSide()} ${selectedVehicleNonPassenger() ? "service-non-passenger" : ""}`}
          type="button"
          style={{ left: `${vehicleScreen()?.[0] ?? 0}px`, top: `${vehicleScreen()?.[1] ?? 0}px` }}
          onClick={() => props.vehicle !== null && props.onSelectVehicle(props.vehicle.id)}
          aria-label={selectedVehicleNonPassenger()
            ? i18n.text(
              { nb: "Valgt {mode}, ikke i passasjertrafikk", en: "Selected {mode}, not in passenger service" },
              { mode: vehicleModeLabel(props.vehicle?.transportMode ?? "unknown", i18n.language()).toLocaleLowerCase(i18n.language() === "nb" ? "nb-NO" : "en") },
            )
            : i18n.text(
              { nb: "Valgt {mode} på linje {line}", en: "Selected {mode} on Line {line}" },
              { mode: vehicleModeLabel(props.vehicle?.transportMode ?? "unknown", i18n.language()), line: props.vehicle?.lineCode ?? i18n.text({ nb: "ukjent", en: "unknown" }) },
            )}
        >
          <span class="vehicle-marker-pin" aria-hidden="true"><Icon name={vehicleModeIcon(props.vehicle?.transportMode ?? "unknown")} size={24} /></span>
          <span class="vehicle-marker-label" aria-hidden="true">{vehicleModeLabel(props.vehicle?.transportMode ?? "unknown", i18n.language())} · {selectedVehicleNonPassenger()
            ? i18n.text({ nb: "Ikke i passasjertrafikk", en: "Not in passenger service" })
            : i18n.text({ nb: "Linje {line}", en: "Line {line}" }, { line: props.vehicle?.lineCode ?? "—" })}</span>
        </button>
      </Show>
      <Show when={!props.deterministic}><MapStatusOverlay state={loadState()} basemap={requestedBasemap()} errorCode={errorCode()} onRetry={retry} /></Show>
      <div class="map-controls" aria-label={i18n.text({ nb: "Kartkontroller", en: "Map controls" })}>
        <Show when={props.vehicle?.passengerServiceState !== "non_passenger" && validRouteCoordinates(props.journey ?? props.vehicle?.journey ?? null).length > 1}>
          <button class="map-control-button" type="button" aria-label={i18n.text({ nb: "Vis hele ruten", en: "Show full route overview" })} title={i18n.text({ nb: "Ruteoversikt", en: "Route overview" })} onClick={showRouteOverview}><Icon name="focus" size={22} /></button>
        </Show>
        <Show when={config() !== null && !props.deterministic} fallback={<button class="map-control-button" type="button" aria-label={i18n.text({ nb: "Kartlag", en: "Map layers" })} disabled={!props.deterministic}><Icon name="layers" size={22} /></button>}>
          <BasemapLayerPicker basemaps={config()!.basemaps} selected={requestedBasemap()} loading={loadState() === "loading"} onSelect={selectBasemap} />
        </Show>
      </div>
    </section>
  );
};
