import { createEffect, createSignal, onCleanup, onMount, Show, For, type Component } from "solid-js";
import maplibregl, { type GeoJSONSource, type Map as MapLibreMap, type StyleSpecification } from "maplibre-gl";
import type { FocusState, MapItem, StationSnapshot, VehicleState } from "../types/domain";
import { resolveMapStyleUrl } from "../services/mapStyle";
import { Icon } from "./Icon";

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
    transport: { type: "geojson", data: { type: "FeatureCollection", features: [] } },
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
      source: "transport",
      filter: ["==", ["get", "kind"], "cluster"],
      paint: { "circle-radius": ["interpolate", ["linear"], ["zoom"], 3, 12, 10, 20], "circle-color": "#155ea8", "circle-stroke-color": "#7fc4ff", "circle-stroke-width": 2, "circle-opacity": 0.86 },
    },
    {
      id: "station-points",
      type: "circle",
      source: "transport",
      filter: ["==", ["get", "kind"], "station"],
      paint: { "circle-radius": ["case", ["==", ["get", "selected"], true], 10, 6], "circle-color": ["case", ["==", ["get", "selected"], true], "#ffffff", "#35a9ef"], "circle-stroke-color": ["case", ["==", ["get", "selected"], true], "#2c91ff", "#d6f0ff"], "circle-stroke-width": ["case", ["==", ["get", "selected"], true], 4, 2] },
    },
  ],
};

const markerPositions: Readonly<Record<string, readonly [number, number]>> = {
  "cluster-tromso": [66, 15],
  "cluster-trondheim": [56, 34],
  "cluster-forde": [37, 54],
  "cluster-bergen": [31, 66],
  "cluster-oslo": [63, 72],
  "cluster-stavanger": [36, 78],
  "NSR:StopPlace:58366": [43, 57],
  "NSR:StopPlace:58370": [65, 50],
  "NSR:StopPlace:58372": [55, 39],
};

export interface MapCanvasProps {
  readonly items: readonly MapItem[];
  readonly station: StationSnapshot | null;
  readonly vehicle: VehicleState | null;
  readonly focus: FocusState;
  readonly deterministic: boolean;
  readonly onSelectStation: (stationId: string) => void;
  readonly onSelectVehicle: (vehicleId: string) => void;
  readonly onManualMove: () => void;
  readonly onViewportChange: (bounds: readonly [number, number, number, number], zoom: number) => void;
}

export const MapCanvas: Component<MapCanvasProps> = (props) => {
  let container: HTMLDivElement | undefined;
  let map: MapLibreMap | null = null;
  const [mapReady, setMapReady] = createSignal(false);
  const [vehicleScreen, setVehicleScreen] = createSignal<readonly [number, number] | null>(null);
  const [trailScreen, setTrailScreen] = createSignal<readonly (readonly [number, number])[]>([]);

  const updateVehicleProjection = () => {
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

  onMount(() => {
    if (container === undefined) return;
    try {
      const configuredStyle = resolveMapStyleUrl(import.meta.env.VITE_MAP_STYLE_URL, props.deterministic);
      map = new maplibregl.Map({
        container,
        style: configuredStyle ?? localStyle,
        center: [10.2, 64.2],
        zoom: 3.6,
        minZoom: 2.8,
        maxZoom: 16,
        attributionControl: false,
        fadeDuration: 0,
      });
      map.addControl(new maplibregl.NavigationControl({ showCompass: false }), "top-left");
      if (configuredStyle !== null) map.addControl(new maplibregl.AttributionControl({ compact: true }), "bottom-right");
      map.on("load", () => { setMapReady(true); reportViewport(); });
      map.on("move", updateVehicleProjection);
      map.on("moveend", reportViewport);
      map.on("resize", updateVehicleProjection);
      map.on("click", "station-points", (event) => {
        const id = event.features?.[0]?.properties.id as string | undefined;
        if (id !== undefined) props.onSelectStation(id);
      });
      map.on("click", "station-clusters", (event) => {
        const raw = event.features?.[0]?.properties.bounds as string | undefined;
        if (raw === undefined) return;
        try {
          const bounds = JSON.parse(raw) as { minLongitude: number; minLatitude: number; maxLongitude: number; maxLatitude: number };
          map?.fitBounds([[bounds.minLongitude, bounds.minLatitude], [bounds.maxLongitude, bounds.maxLatitude]], { padding: 55, duration: 600 });
        } catch { /* Invalid server data is ignored after contract validation. */ }
      });
      map.on("mouseenter", "station-points", () => { if (map !== null) map.getCanvas().style.cursor = "pointer"; });
      map.on("mouseleave", "station-points", () => { if (map !== null) map.getCanvas().style.cursor = ""; });
      const manualMove = (event: maplibregl.MapMouseEvent | maplibregl.MapWheelEvent | maplibregl.MapTouchEvent) => {
        const target = event.originalEvent?.target;
        if (target instanceof Element && (target.closest('.maplibregl-canvas') !== null || target.closest('.maplibregl-ctrl') !== null)) {
          props.onManualMove();
        }
      };
      map.on("dragstart", manualMove);
      map.on("zoomstart", manualMove);
    } catch {
      container.dataset.mapFallback = "true";
    }
  });

  createEffect(() => {
    if (!mapReady() || map === null) return;
    const selectedStationId = props.station?.stationId;
    const features = props.items.map((item) => ({
      type: "Feature" as const,
      geometry: { type: "Point" as const, coordinates: [item.longitude, item.latitude] },
      properties: item.kind === "cluster"
        ? { kind: item.kind, id: item.id, count: item.count, bounds: JSON.stringify(item.bounds) }
        : { kind: item.kind, id: item.id, name: item.name, selected: item.id === selectedStationId },
    }));
    (map.getSource("transport") as GeoJSONSource | undefined)?.setData({ type: "FeatureCollection", features });
  });

  createEffect(() => {
    const station = props.station;
    const vehicle = props.vehicle;
    if (map === null) return;
    if (vehicle !== null && vehicle.longitude !== null && vehicle.latitude !== null) {
      map.easeTo({ center: [vehicle.longitude, vehicle.latitude], zoom: props.focus === "following" ? 10.2 : 9, duration: 700 });
    } else if (station !== null) {
      map.easeTo({ center: [station.station.longitude, station.station.latitude], zoom: 8.2, duration: 650 });
    }
    updateVehicleProjection();
  });

  onCleanup(() => map?.remove());

  const position = (id: string): readonly [number, number] | null => markerPositions[id] ?? null;

  return (
    <section class="map-region" aria-label="Interactive map of Norway">
      <div ref={container} class="maplibre-canvas" />
      <div class="map-texture" aria-hidden="true" />
      <div class="map-label norway-label" aria-hidden="true">NORWAY</div>
      <div class="map-label sea-label" aria-hidden="true">Norwegian Sea</div>
      <div class="map-markers">
        <For each={props.items}>{(item) => {
          const id = item.id;
          const markerPosition = position(id);
          if (markerPosition === null) return null;
          const [left, top] = markerPosition;
          const selected = item.kind === "station" && props.station?.stationId === item.id;
          const clusterLabel = item.kind === "cluster" ? ({ "cluster-tromso": "Tromsø", "cluster-trondheim": "Trondheim", "cluster-forde": "Førde / Nordfjord", "cluster-bergen": "Bergen", "cluster-oslo": "Oslo", "cluster-stavanger": "Stavanger" } as const)[item.id as keyof typeof markerPositions] ?? "Station cluster" : "";
          return item.kind === "cluster" ? (
            <button
              class={`cluster-marker ${item.id === "cluster-forde" ? "is-featured" : ""}`}
              style={{ left: `${left}%`, top: `${top}%` }}
              onClick={() => map?.fitBounds([[item.bounds.minLongitude, item.bounds.minLatitude], [item.bounds.maxLongitude, item.bounds.maxLatitude]], { padding: 55, duration: 600 })}
              aria-label={`${clusterLabel}, ${item.count} stations`}
            >
              <strong>{item.count}</strong><small>{clusterLabel}</small>
            </button>
          ) : (
            <button
              class={`station-marker ${selected ? "is-selected" : ""}`}
              style={{ left: `${left}%`, top: `${top}%` }}
              onClick={() => props.onSelectStation(item.id)}
              aria-label={`Open ${item.name}`}
            >
              <Icon name="bus" size={17} /><small>{item.name}</small>
            </button>
          );
        }}</For>
      </div>
      <Show when={props.vehicle !== null && vehicleScreen() !== null}>
        <svg class={`vehicle-trail ${props.vehicle?.state !== "live" ? "is-muted" : ""}`} aria-hidden="true">
          <Show when={trailScreen().length > 1}><path d={trailScreen().map(([x, y], index) => `${index === 0 ? "M" : "L"}${x.toFixed(1)} ${y.toFixed(1)}`).join(" ")} /></Show>
          <For each={trailScreen()}>{([x, y], index) => <circle cx={x} cy={y} r={Math.min(5, 2.5 + index() * .45)} />}</For>
        </svg>
        <button
          class={`vehicle-marker state-${props.vehicle?.state ?? "live"}`}
          style={{ left: `${vehicleScreen()?.[0] ?? 0}px`, top: `${vehicleScreen()?.[1] ?? 0}px` }}
          onClick={() => props.vehicle !== null && props.onSelectVehicle(props.vehicle.id)}
          aria-label={`Selected Line ${props.vehicle?.lineCode ?? "unknown"} vehicle`}
        >
          <Icon name="bus" size={24} />
          <span>Line {props.vehicle?.lineCode}</span>
        </button>
      </Show>
      <div class="map-controls" aria-label="Map controls">
        <button type="button" aria-label="Find my location"><Icon name="focus" size={22} /></button>
        <button type="button" aria-label="Map layers"><Icon name="layers" size={22} /></button>
      </div>
    </section>
  );
};
