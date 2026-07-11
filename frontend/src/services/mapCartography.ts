import type { ExpressionSpecification, FilterSpecification, LayerSpecification, StyleSpecification } from "maplibre-gl";
import type { BasemapId } from "../types/domain";

const HYBRID_VECTOR_SOURCE_ID = "maptiler_planet";
const HYBRID_RASTER_SOURCE_ID = "satellite";

export const HYBRID_LAYER_IDS = {
  road: "Road",
  roadLabels: "Road labels",
  placeLabels: "Place labels",
  villageLabels: "Village labels",
  townLabels: "Town labels",
  roadCasing: "fjordpulse-hybrid-road-casing",
  majorRoadLabels: "fjordpulse-hybrid-major-road-labels",
} as const;

const ROAD_FILTER: FilterSpecification = [
  "all",
  ["==", ["geometry-type"], "LineString"],
  ["match", ["get", "brunnel"], ["tunnel"], false, true],
  ["any", ["==", ["get", "construction"], false], ["!", ["has", "construction"]]],
];

const ROAD_LABEL_FILTER: FilterSpecification = [
  "all",
  ["==", ["geometry-type"], "LineString"],
  ["any", ["==", ["get", "construction"], false], ["!", ["has", "construction"]]],
];

const PLACE_FILTER: FilterSpecification = [
  "all",
  ["==", ["geometry-type"], "Point"],
  [
    "match",
    ["get", "class"],
    ["hamlet", "isolated_dwelling", "neighbourhood", "quarter", "suburb"],
    true,
    ["!", ["has", "class"]],
  ],
];

const VILLAGE_FILTER: FilterSpecification = [
  "all",
  ["==", ["geometry-type"], "Point"],
  ["match", ["get", "class"], ["village"], true, false],
];

const TOWN_FILTER: FilterSpecification = ["==", ["geometry-type"], "Point"];

const RANK_SORT_KEY: ExpressionSpecification = ["to-number", ["get", "rank"]];
const TOWN_SORT_KEY: ExpressionSpecification = [
  "+",
  ["case", ["==", ["get", "capital"], 20], -1000, 0],
  ["to-number", ["get", "rank"]],
];
const LOCALIZED_NAME: ExpressionSpecification = ["coalesce", ["get", "name:en"], ["get", "name"]];

const ROAD_WIDTH: ExpressionSpecification = [
  "interpolate",
  ["linear"],
  ["zoom"],
  6,
  ["match", ["get", "class"], ["motorway", "motorway_link", "trunk", "primary"], 0.9, ["secondary", "tertiary"], 0.5, ["minor", "service"], 0.15, 0.35],
  10.2,
  ["match", ["get", "class"], ["motorway", "motorway_link", "trunk", "primary"], 2.8, ["secondary", "tertiary"], 2.1, ["minor"], 1.2, ["service"], 0.8, 1],
  14,
  ["match", ["get", "class"], ["motorway", "motorway_link", "trunk", "primary"], 6, ["secondary", "tertiary"], 4.5, ["minor"], 3, ["service"], 2, 2.4],
];

const ROAD_CASING_WIDTH: ExpressionSpecification = [
  "interpolate",
  ["linear"],
  ["zoom"],
  6,
  ["match", ["get", "class"], ["motorway", "motorway_link", "trunk", "primary"], 2.9, ["secondary", "tertiary"], 2.5, ["minor", "service"], 2.15, 2.35],
  10.2,
  ["match", ["get", "class"], ["motorway", "motorway_link", "trunk", "primary"], 4.8, ["secondary", "tertiary"], 4.1, ["minor"], 3.2, ["service"], 2.8, 3],
  14,
  ["match", ["get", "class"], ["motorway", "motorway_link", "trunk", "primary"], 8, ["secondary", "tertiary"], 6.5, ["minor"], 5, ["service"], 4, 4.4],
];

const ROAD_COLOR: ExpressionSpecification = [
  "match",
  ["get", "class"],
  ["motorway", "motorway_link", "trunk", "primary"],
  "#f6d77a",
  ["secondary", "tertiary"],
  "#f4efe5",
  ["minor", "service"],
  "#ddd7cc",
  "#e7e1d7",
];

const MAJOR_ROAD_LABEL_FILTER: FilterSpecification = [
  "all",
  ["==", ["geometry-type"], "LineString"],
  ["any", ["==", ["get", "construction"], false], ["!", ["has", "construction"]]],
  ["match", ["get", "class"], ["motorway", "motorway_link", "trunk", "primary"], true, false],
];

interface ExpectedLayerSignature {
  readonly id: string;
  readonly type: "line" | "symbol";
  readonly sourceLayer: string;
  readonly minzoom: number;
  readonly curatedMinzoom?: number;
  readonly maxzoom?: number;
  readonly filter: FilterSpecification;
  readonly layout: Readonly<Record<string, unknown>>;
}

const EXPECTED_LAYERS: readonly ExpectedLayerSignature[] = [
  {
    id: HYBRID_LAYER_IDS.road,
    type: "line",
    sourceLayer: "road",
    minzoom: 6,
    filter: ROAD_FILTER,
    layout: { "line-cap": "butt", "line-join": "round" },
  },
  {
    id: HYBRID_LAYER_IDS.roadLabels,
    type: "symbol",
    sourceLayer: "road_label",
    minzoom: 11,
    filter: ROAD_LABEL_FILTER,
    layout: { "symbol-placement": "line", "text-field": LOCALIZED_NAME, "text-font": ["Noto Sans Medium"] },
  },
  {
    id: HYBRID_LAYER_IDS.placeLabels,
    type: "symbol",
    sourceLayer: "place_label",
    minzoom: 10,
    curatedMinzoom: 12,
    maxzoom: 16,
    filter: PLACE_FILTER,
    layout: { "symbol-sort-key": RANK_SORT_KEY, "text-field": LOCALIZED_NAME },
  },
  {
    id: HYBRID_LAYER_IDS.villageLabels,
    type: "symbol",
    sourceLayer: "place_label",
    minzoom: 10,
    curatedMinzoom: 11,
    maxzoom: 16,
    filter: VILLAGE_FILTER,
    layout: { "symbol-sort-key": RANK_SORT_KEY, "text-field": LOCALIZED_NAME },
  },
  {
    id: HYBRID_LAYER_IDS.townLabels,
    type: "symbol",
    sourceLayer: "town_label",
    minzoom: 9,
    maxzoom: 16,
    filter: TOWN_FILTER,
    layout: { "symbol-sort-key": TOWN_SORT_KEY, "text-field": LOCALIZED_NAME },
  },
];

export interface HybridCartographyHost {
  getStyle(): StyleSpecification;
  getLayer(id: string): unknown;
  addLayer(layer: LayerSpecification, beforeId?: string): unknown;
  setPaintProperty(layerId: string, name: string, value: unknown): unknown;
  setLayoutProperty(layerId: string, name: string, value: unknown): unknown;
  setLayerZoomRange(layerId: string, minzoom: number, maxzoom: number): unknown;
}

export type HybridCartographyStatus = "applied" | "not-applicable" | "provider-drift" | "mutation-failed";

export interface HybridCartographyResult {
  readonly status: HybridCartographyStatus;
  readonly diagnostics: readonly string[];
}

function deepEqual(left: unknown, right: unknown): boolean {
  return JSON.stringify(left) === JSON.stringify(right);
}

function record(value: unknown): Readonly<Record<string, unknown>> | null {
  return typeof value === "object" && value !== null ? value as Readonly<Record<string, unknown>> : null;
}

function layerRecord(style: StyleSpecification, id: string): Readonly<Record<string, unknown>> | null {
  return record(style.layers.find((layer) => layer.id === id));
}

function sourceType(style: StyleSpecification, id: string): unknown {
  return record(style.sources[id])?.type;
}

function signatureDiagnostics(style: StyleSpecification): string[] {
  const diagnostics: string[] = [];
  const curated = layerRecord(style, HYBRID_LAYER_IDS.roadCasing) !== null
    && layerRecord(style, HYBRID_LAYER_IDS.majorRoadLabels) !== null;
  if (sourceType(style, HYBRID_VECTOR_SOURCE_ID) !== "vector") diagnostics.push(`${HYBRID_VECTOR_SOURCE_ID}:vector source missing`);
  if (sourceType(style, HYBRID_RASTER_SOURCE_ID) !== "raster") diagnostics.push(`${HYBRID_RASTER_SOURCE_ID}:raster source missing`);

  for (const expected of EXPECTED_LAYERS) {
    const layer = layerRecord(style, expected.id);
    if (layer === null) {
      diagnostics.push(`${expected.id}:layer missing`);
      continue;
    }
    const layout = record(layer.layout) ?? {};
    const layoutMatches = Object.entries(expected.layout).every(([key, value]) => deepEqual(layout[key], value));
    const maxzoomMatches = expected.maxzoom === undefined ? layer.maxzoom === undefined : layer.maxzoom === expected.maxzoom;
    if (
      layer.type !== expected.type
      || layer.source !== HYBRID_VECTOR_SOURCE_ID
      || layer["source-layer"] !== expected.sourceLayer
      || layer.minzoom !== (curated ? expected.curatedMinzoom ?? expected.minzoom : expected.minzoom)
      || !maxzoomMatches
      || !deepEqual(layer.filter, expected.filter)
      || !layoutMatches
    ) {
      diagnostics.push(`${expected.id}:signature changed`);
    }
  }
  return diagnostics;
}

function warnDefault(message: string): void {
  console.warn(message);
}

/**
 * Applies FjordPulse's readability policy only to the exact MapTiler Hybrid-v4
 * structure it was designed against. A provider update is intentionally a
 * non-fatal skip: transport overlays and the provider map remain available.
 */
export function applyHybridCartography(
  host: HybridCartographyHost,
  basemap: BasemapId,
  warn: (message: string) => void = warnDefault,
): HybridCartographyResult {
  if (basemap !== "satellite") return { status: "not-applicable", diagnostics: [] };

  const diagnostics = signatureDiagnostics(host.getStyle());
  if (diagnostics.length > 0) {
    warn(`FjordPulse Hybrid cartography skipped: ${diagnostics.join("; ")}`);
    return { status: "provider-drift", diagnostics };
  }

  try {
    if (host.getLayer(HYBRID_LAYER_IDS.roadCasing) === undefined) {
      host.addLayer({
        id: HYBRID_LAYER_IDS.roadCasing,
        type: "line",
        source: HYBRID_VECTOR_SOURCE_ID,
        "source-layer": "road",
        minzoom: 6,
        filter: ROAD_FILTER,
        layout: { "line-cap": "butt", "line-join": "round" },
        paint: {
          "line-color": "#111827",
          "line-opacity": 0.86,
          "line-width": ROAD_CASING_WIDTH,
        },
      }, HYBRID_LAYER_IDS.road);
    }

    host.setPaintProperty(HYBRID_LAYER_IDS.road, "line-color", ROAD_COLOR);
    host.setPaintProperty(HYBRID_LAYER_IDS.road, "line-width", ROAD_WIDTH);
    host.setPaintProperty(HYBRID_LAYER_IDS.road, "line-opacity", 0.94);

    if (host.getLayer(HYBRID_LAYER_IDS.majorRoadLabels) === undefined) {
      host.addLayer({
        id: HYBRID_LAYER_IDS.majorRoadLabels,
        type: "symbol",
        source: HYBRID_VECTOR_SOURCE_ID,
        "source-layer": "road_label",
        minzoom: 9.5,
        maxzoom: 11,
        filter: MAJOR_ROAD_LABEL_FILTER,
        layout: {
          "symbol-placement": "line",
          "symbol-spacing": 420,
          "text-field": LOCALIZED_NAME,
          "text-font": ["Noto Sans Medium"],
          "text-letter-spacing": 0.08,
          "text-pitch-alignment": "viewport",
          "text-rotation-alignment": "map",
          "text-size": ["interpolate", ["linear"], ["zoom"], 9.5, 10, 11, 11],
        },
        paint: { "text-color": "#fff8df", "text-halo-color": "#111827", "text-halo-width": 1.25 },
      }, HYBRID_LAYER_IDS.roadLabels);
    }

    host.setLayerZoomRange(HYBRID_LAYER_IDS.placeLabels, 12, 16);
    host.setLayerZoomRange(HYBRID_LAYER_IDS.villageLabels, 11, 16);
    host.setLayerZoomRange(HYBRID_LAYER_IDS.townLabels, 9, 16);
    host.setLayoutProperty(HYBRID_LAYER_IDS.townLabels, "text-font", ["Noto Sans Medium"]);
    host.setLayoutProperty(HYBRID_LAYER_IDS.townLabels, "text-size", ["interpolate", ["linear"], ["zoom"], 9, 15, 10, 16, 14, 19]);
    host.setPaintProperty(HYBRID_LAYER_IDS.townLabels, "text-halo-color", "#050b10");
    host.setPaintProperty(HYBRID_LAYER_IDS.townLabels, "text-halo-width", 1.5);

    return { status: "applied", diagnostics: [] };
  } catch (error) {
    const message = error instanceof Error ? error.message : "unknown mutation error";
    const mutationDiagnostics = [`Hybrid-v4 mutation failed: ${message}`];
    warn(`FjordPulse Hybrid cartography incomplete: ${message}`);
    return { status: "mutation-failed", diagnostics: mutationDiagnostics };
  }
}
