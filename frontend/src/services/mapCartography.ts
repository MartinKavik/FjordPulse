import type { ExpressionSpecification, FilterSpecification, LayerSpecification, StyleSpecification } from "maplibre-gl";
import type { BasemapId } from "../types/domain";

const HYBRID_VECTOR_SOURCE_ID = "maptiler_planet";
const HYBRID_RASTER_SOURCE_ID = "satellite";
const STREETS_VECTOR_SOURCE_ID = "maptiler_planet_v4";

export const MAPTILER_LAYER_IDS = {
  road: "Road",
  roadLabels: "Road labels",
  placeLabels: "Place labels",
  villageLabels: "Village labels",
  townLabels: "Town labels",
  roadCasing: "fjordpulse-hybrid-road-casing",
  majorRoadLabels: "fjordpulse-hybrid-major-road-labels",
} as const;

export const SETTLEMENT_LABEL_MIN_ZOOM = {
  town: 6,
  village: 8,
  place: 10,
} as const;

const SETTLEMENT_VARIABLE_ANCHORS = ["center", "top", "bottom"] as const;

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

const STREETS_PLACE_FILTER: FilterSpecification = [
  "all",
  ["==", ["geometry-type"], "Point"],
  [
    "any",
    ["match", ["get", "class"], ["neighbourhood", "quarter", "suburb"], true, false],
    ["all", [">=", ["zoom"], 12], ["match", ["get", "class"], ["hamlet", "isolated_dwelling"], true, false]],
  ],
];

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
  readonly source: string;
  readonly sourceLayer: string;
  readonly minzoom: number;
  readonly curatedMinzoom?: number;
  readonly maxzoom?: number;
  readonly curatedMaxzoom?: number;
  readonly filter: FilterSpecification;
  readonly layout: Readonly<Record<string, unknown>>;
}

const HYBRID_EXPECTED_LAYERS: readonly ExpectedLayerSignature[] = [
  {
    id: MAPTILER_LAYER_IDS.road,
    type: "line",
    source: HYBRID_VECTOR_SOURCE_ID,
    sourceLayer: "road",
    minzoom: 6,
    filter: ROAD_FILTER,
    layout: { "line-cap": "butt", "line-join": "round" },
  },
  {
    id: MAPTILER_LAYER_IDS.roadLabels,
    type: "symbol",
    source: HYBRID_VECTOR_SOURCE_ID,
    sourceLayer: "road_label",
    minzoom: 11,
    filter: ROAD_LABEL_FILTER,
    layout: { "symbol-placement": "line", "text-field": LOCALIZED_NAME, "text-font": ["Noto Sans Medium"] },
  },
  {
    id: MAPTILER_LAYER_IDS.placeLabels,
    type: "symbol",
    source: HYBRID_VECTOR_SOURCE_ID,
    sourceLayer: "place_label",
    minzoom: 10,
    curatedMinzoom: SETTLEMENT_LABEL_MIN_ZOOM.place,
    maxzoom: 16,
    filter: PLACE_FILTER,
    layout: { "symbol-sort-key": RANK_SORT_KEY, "text-field": LOCALIZED_NAME },
  },
  {
    id: MAPTILER_LAYER_IDS.villageLabels,
    type: "symbol",
    source: HYBRID_VECTOR_SOURCE_ID,
    sourceLayer: "place_label",
    minzoom: 10,
    curatedMinzoom: SETTLEMENT_LABEL_MIN_ZOOM.village,
    maxzoom: 16,
    filter: VILLAGE_FILTER,
    layout: { "symbol-sort-key": RANK_SORT_KEY, "text-field": LOCALIZED_NAME },
  },
  {
    id: MAPTILER_LAYER_IDS.townLabels,
    type: "symbol",
    source: HYBRID_VECTOR_SOURCE_ID,
    sourceLayer: "town_label",
    minzoom: 9,
    curatedMinzoom: SETTLEMENT_LABEL_MIN_ZOOM.town,
    maxzoom: 16,
    filter: TOWN_FILTER,
    layout: { "symbol-sort-key": TOWN_SORT_KEY, "text-field": LOCALIZED_NAME },
  },
];

const STREETS_EXPECTED_LAYERS: readonly ExpectedLayerSignature[] = [
  {
    id: MAPTILER_LAYER_IDS.placeLabels,
    type: "symbol",
    source: STREETS_VECTOR_SOURCE_ID,
    sourceLayer: "place_label",
    minzoom: 9,
    curatedMinzoom: SETTLEMENT_LABEL_MIN_ZOOM.place,
    curatedMaxzoom: 24,
    filter: STREETS_PLACE_FILTER,
    layout: { "symbol-sort-key": RANK_SORT_KEY, "text-field": "{name}" },
  },
  {
    id: MAPTILER_LAYER_IDS.villageLabels,
    type: "symbol",
    source: STREETS_VECTOR_SOURCE_ID,
    sourceLayer: "place_label",
    minzoom: 10,
    curatedMinzoom: SETTLEMENT_LABEL_MIN_ZOOM.village,
    curatedMaxzoom: 24,
    filter: VILLAGE_FILTER,
    layout: { "symbol-sort-key": RANK_SORT_KEY, "text-field": "{name}" },
  },
  {
    id: MAPTILER_LAYER_IDS.townLabels,
    type: "symbol",
    source: STREETS_VECTOR_SOURCE_ID,
    sourceLayer: "town_label",
    minzoom: 6,
    curatedMinzoom: SETTLEMENT_LABEL_MIN_ZOOM.town,
    maxzoom: 16,
    filter: TOWN_FILTER,
    layout: { "symbol-sort-key": TOWN_SORT_KEY, "text-field": LOCALIZED_NAME },
  },
];

interface MapTilerStyleProfile {
  readonly vectorSource: string;
  readonly rasterSource?: string;
  readonly expectedLayers: readonly ExpectedLayerSignature[];
}

const MAPTILER_STYLE_PROFILES: Readonly<Record<BasemapId, MapTilerStyleProfile>> = {
  satellite: {
    vectorSource: HYBRID_VECTOR_SOURCE_ID,
    rasterSource: HYBRID_RASTER_SOURCE_ID,
    expectedLayers: HYBRID_EXPECTED_LAYERS,
  },
  streets: {
    vectorSource: STREETS_VECTOR_SOURCE_ID,
    expectedLayers: STREETS_EXPECTED_LAYERS,
  },
};

export interface MapTilerCartographyHost {
  getStyle(): StyleSpecification;
  getLayer(id: string): unknown;
  addLayer(layer: LayerSpecification, beforeId?: string): unknown;
  setPaintProperty(layerId: string, name: string, value: unknown): unknown;
  setLayoutProperty(layerId: string, name: string, value: unknown): unknown;
  setLayerZoomRange(layerId: string, minzoom: number, maxzoom: number): unknown;
}

export type MapTilerCartographyStatus = "pending" | "applied" | "provider-drift" | "mutation-failed";

export interface MapTilerCartographyResult {
  readonly status: MapTilerCartographyStatus;
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

function signatureDiagnostics(style: StyleSpecification, profile: MapTilerStyleProfile): string[] {
  const diagnostics: string[] = [];
  const townLayout = record(layerRecord(style, MAPTILER_LAYER_IDS.townLabels)?.layout) ?? {};
  const curated = deepEqual(townLayout["text-variable-anchor"], SETTLEMENT_VARIABLE_ANCHORS);
  if (sourceType(style, profile.vectorSource) !== "vector") diagnostics.push(`${profile.vectorSource}:vector source missing`);
  if (profile.rasterSource !== undefined && sourceType(style, profile.rasterSource) !== "raster") diagnostics.push(`${profile.rasterSource}:raster source missing`);

  for (const expected of profile.expectedLayers) {
    const layer = layerRecord(style, expected.id);
    if (layer === null) {
      diagnostics.push(`${expected.id}:layer missing`);
      continue;
    }
    const layout = record(layer.layout) ?? {};
    const layoutMatches = Object.entries(expected.layout).every(([key, value]) => deepEqual(layout[key], value));
    const wantedMaxzoom = curated ? expected.curatedMaxzoom ?? expected.maxzoom : expected.maxzoom;
    const maxzoomMatches = wantedMaxzoom === undefined ? layer.maxzoom === undefined : layer.maxzoom === wantedMaxzoom;
    if (
      layer.type !== expected.type
      || layer.source !== expected.source
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
 * Applies FjordPulse's readability policy only to the exact current MapTiler
 * Hybrid-v4 or Streets-v4 structure it was designed against. A provider update
 * is intentionally a non-fatal skip: transport overlays and the provider map
 * remain available.
 */
export function applyMapTilerCartography(
  host: MapTilerCartographyHost,
  basemap: BasemapId,
  warn: (message: string) => void = warnDefault,
): MapTilerCartographyResult {
  const profile = MAPTILER_STYLE_PROFILES[basemap];
  const diagnostics = signatureDiagnostics(host.getStyle(), profile);
  if (diagnostics.length > 0) {
    warn(`FjordPulse MapTiler cartography skipped: ${diagnostics.join("; ")}`);
    return { status: "provider-drift", diagnostics };
  }

  try {
    if (basemap === "satellite" && host.getLayer(MAPTILER_LAYER_IDS.roadCasing) === undefined) {
      host.addLayer({
        id: MAPTILER_LAYER_IDS.roadCasing,
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
      }, MAPTILER_LAYER_IDS.road);
    }

    if (basemap === "satellite") {
      host.setPaintProperty(MAPTILER_LAYER_IDS.road, "line-color", ROAD_COLOR);
      host.setPaintProperty(MAPTILER_LAYER_IDS.road, "line-width", ROAD_WIDTH);
      host.setPaintProperty(MAPTILER_LAYER_IDS.road, "line-opacity", 0.94);
    }

    if (basemap === "satellite" && host.getLayer(MAPTILER_LAYER_IDS.majorRoadLabels) === undefined) {
      host.addLayer({
        id: MAPTILER_LAYER_IDS.majorRoadLabels,
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
      }, MAPTILER_LAYER_IDS.roadLabels);
    }

    const extendedMaxZoom = basemap === "satellite" ? 16 : 24;
    host.setLayerZoomRange(MAPTILER_LAYER_IDS.placeLabels, SETTLEMENT_LABEL_MIN_ZOOM.place, extendedMaxZoom);
    host.setLayerZoomRange(MAPTILER_LAYER_IDS.villageLabels, SETTLEMENT_LABEL_MIN_ZOOM.village, extendedMaxZoom);
    host.setLayerZoomRange(MAPTILER_LAYER_IDS.townLabels, SETTLEMENT_LABEL_MIN_ZOOM.town, 16);
    host.setLayoutProperty(MAPTILER_LAYER_IDS.villageLabels, "text-size", ["interpolate", ["linear"], ["zoom"], 8, 10, 10, 12, 14, 16]);
    host.setLayoutProperty(MAPTILER_LAYER_IDS.townLabels, "text-size", ["interpolate", ["linear"], ["zoom"], 6, 10, 8, 13, 10, 16, 14, 19]);
    for (const layerId of [MAPTILER_LAYER_IDS.placeLabels, MAPTILER_LAYER_IDS.villageLabels, MAPTILER_LAYER_IDS.townLabels]) {
      host.setLayoutProperty(layerId, "text-allow-overlap", false);
      host.setLayoutProperty(layerId, "text-ignore-placement", false);
    }
    for (const layerId of [MAPTILER_LAYER_IDS.villageLabels, MAPTILER_LAYER_IDS.townLabels]) {
      host.setLayoutProperty(layerId, "text-variable-anchor", SETTLEMENT_VARIABLE_ANCHORS);
      host.setLayoutProperty(layerId, "text-padding", 3);
    }
    if (basemap === "satellite") {
      host.setLayoutProperty(MAPTILER_LAYER_IDS.townLabels, "text-font", ["Noto Sans Medium"]);
      host.setPaintProperty(MAPTILER_LAYER_IDS.townLabels, "text-halo-color", "#050b10");
      host.setPaintProperty(MAPTILER_LAYER_IDS.townLabels, "text-halo-width", 1.5);
    }

    return { status: "applied", diagnostics: [] };
  } catch (error) {
    const message = error instanceof Error ? error.message : "unknown mutation error";
    const mutationDiagnostics = [`MapTiler cartography mutation failed: ${message}`];
    warn(`FjordPulse MapTiler cartography incomplete: ${message}`);
    return { status: "mutation-failed", diagnostics: mutationDiagnostics };
  }
}
