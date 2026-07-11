import { describe, expect, it, vi } from "vitest";
import type { LayerSpecification, StyleSpecification } from "maplibre-gl";
import { validateStyleMin } from "@maplibre/maplibre-gl-style-spec";
import { buildTransportData, splitRouteCoordinates } from "../src/components/MapCanvas";
import { applyHybridCartography, HYBRID_LAYER_IDS, type HybridCartographyHost } from "../src/services/mapCartography";
import type { JourneySnapshot, StopCall, VehicleState } from "../src/types/domain";

function hybridStyle(): StyleSpecification {
  return {
    version: 8,
    name: "Satellite Hybrid",
    sources: {
      maptiler_planet: { type: "vector", tiles: ["https://tiles.test/{z}/{x}/{y}.pbf"] },
      satellite: { type: "raster", tiles: ["https://tiles.test/{z}/{x}/{y}.png"], tileSize: 256 },
    },
    layers: [
      { id: "Satellite", type: "raster", source: "satellite" },
      {
        id: "Road",
        type: "line",
        source: "maptiler_planet",
        "source-layer": "road",
        minzoom: 6,
        filter: ["all", ["==", ["geometry-type"], "LineString"], ["match", ["get", "brunnel"], ["tunnel"], false, true], ["any", ["==", ["get", "construction"], false], ["!", ["has", "construction"]]]],
        layout: { "line-cap": "butt", "line-join": "round", visibility: "visible" },
        paint: { "line-color": "rgba(255,255,255,.2)", "line-width": 1 },
      },
      {
        id: "Road labels",
        type: "symbol",
        source: "maptiler_planet",
        "source-layer": "road_label",
        minzoom: 11,
        filter: ["all", ["==", ["geometry-type"], "LineString"], ["any", ["==", ["get", "construction"], false], ["!", ["has", "construction"]]]],
        layout: { "symbol-placement": "line", "text-field": ["coalesce", ["get", "name:en"], ["get", "name"]], "text-font": ["Noto Sans Medium"] },
      },
      {
        id: "Place labels",
        type: "symbol",
        source: "maptiler_planet",
        "source-layer": "place_label",
        minzoom: 10,
        maxzoom: 16,
        filter: ["all", ["==", ["geometry-type"], "Point"], ["match", ["get", "class"], ["hamlet", "isolated_dwelling", "neighbourhood", "quarter", "suburb"], true, ["!", ["has", "class"]]]],
        layout: { "symbol-sort-key": ["to-number", ["get", "rank"]], "text-field": ["coalesce", ["get", "name:en"], ["get", "name"]] },
      },
      {
        id: "Village labels",
        type: "symbol",
        source: "maptiler_planet",
        "source-layer": "place_label",
        minzoom: 10,
        maxzoom: 16,
        filter: ["all", ["==", ["geometry-type"], "Point"], ["match", ["get", "class"], ["village"], true, false]],
        layout: { "symbol-sort-key": ["to-number", ["get", "rank"]], "text-field": ["coalesce", ["get", "name:en"], ["get", "name"]] },
      },
      {
        id: "Town labels",
        type: "symbol",
        source: "maptiler_planet",
        "source-layer": "town_label",
        minzoom: 9,
        maxzoom: 16,
        filter: ["==", ["geometry-type"], "Point"],
        layout: { "symbol-sort-key": ["+", ["case", ["==", ["get", "capital"], 20], -1000, 0], ["to-number", ["get", "rank"]]], "text-field": ["coalesce", ["get", "name:en"], ["get", "name"]], "text-font": ["Noto Sans Regular"] },
      },
    ],
  };
}

function mutableLayer(style: StyleSpecification, id: string): Record<string, unknown> {
  return style.layers.find((layer) => layer.id === id) as unknown as Record<string, unknown>;
}

function hostFor(style: StyleSpecification) {
  const addLayer = vi.fn((layer: LayerSpecification, beforeId?: string) => {
    const index = beforeId === undefined ? -1 : style.layers.findIndex(({ id }) => id === beforeId);
    if (index < 0) style.layers.push(layer);
    else style.layers.splice(index, 0, layer);
  });
  const setPaintProperty = vi.fn((layerId: string, name: string, value: unknown) => {
    const layer = mutableLayer(style, layerId);
    const paint = (layer.paint ??= {}) as Record<string, unknown>;
    paint[name] = value;
  });
  const setLayoutProperty = vi.fn((layerId: string, name: string, value: unknown) => {
    const layer = mutableLayer(style, layerId);
    const layout = (layer.layout ??= {}) as Record<string, unknown>;
    layout[name] = value;
  });
  const setLayerZoomRange = vi.fn((layerId: string, minzoom: number, maxzoom: number) => {
    const layer = mutableLayer(style, layerId);
    layer.minzoom = minzoom;
    layer.maxzoom = maxzoom;
  });
  const host: HybridCartographyHost = {
    getStyle: () => style,
    getLayer: (id) => style.layers.find((layer) => layer.id === id),
    addLayer,
    setPaintProperty,
    setLayoutProperty,
    setLayerZoomRange,
  };
  return { host, addLayer, setPaintProperty, setLayoutProperty, setLayerZoomRange };
}

describe("MapTiler Hybrid-v4 cartography policy", () => {
  it("adds class-aware roads and applies a visible place hierarchy idempotently", () => {
    const style = hybridStyle();
    const target = hostFor(style);

    expect(applyHybridCartography(target.host, "satellite")).toEqual({ status: "applied", diagnostics: [] });
    expect(target.addLayer).toHaveBeenCalledTimes(2);
    expect(style.layers.findIndex(({ id }) => id === HYBRID_LAYER_IDS.roadCasing)).toBeLessThan(style.layers.findIndex(({ id }) => id === HYBRID_LAYER_IDS.road));
    expect(style.layers.findIndex(({ id }) => id === HYBRID_LAYER_IDS.majorRoadLabels)).toBeLessThan(style.layers.findIndex(({ id }) => id === HYBRID_LAYER_IDS.roadLabels));
    expect(target.setLayerZoomRange).toHaveBeenCalledWith("Place labels", 12, 16);
    expect(target.setLayerZoomRange).toHaveBeenCalledWith("Village labels", 11, 16);
    expect(target.setLayoutProperty).toHaveBeenCalledWith("Town labels", "text-font", ["Noto Sans Medium"]);
    expect(target.setLayoutProperty).toHaveBeenCalledWith("Town labels", "text-size", ["interpolate", ["linear"], ["zoom"], 9, 15, 10, 16, 14, 19]);
    expect(target.setPaintProperty).toHaveBeenCalledWith("Road", "line-width", expect.arrayContaining(["interpolate", ["linear"], ["zoom"], 6]));
    expect(validateStyleMin(style)).toEqual([]);

    expect(applyHybridCartography(target.host, "satellite")).toEqual({ status: "applied", diagnostics: [] });
    expect(target.addLayer).toHaveBeenCalledTimes(2);
  });

  it("leaves a changed provider style usable and reports the signature drift", () => {
    const style = hybridStyle();
    mutableLayer(style, "Village labels").minzoom = 9;
    const target = hostFor(style);
    const warn = vi.fn();

    const result = applyHybridCartography(target.host, "satellite", warn);

    expect(result.status).toBe("provider-drift");
    expect(result.diagnostics).toContain("Village labels:signature changed");
    expect(warn).toHaveBeenCalledOnce();
    expect(target.addLayer).not.toHaveBeenCalled();
    expect(target.setPaintProperty).not.toHaveBeenCalled();
  });

  it("does not mutate the Streets style", () => {
    const target = hostFor(hybridStyle());
    expect(applyHybridCartography(target.host, "streets")).toEqual({ status: "not-applicable", diagnostics: [] });
    expect(target.addLayer).not.toHaveBeenCalled();
  });
});

describe("planned route progress", () => {
  it("splits by route distance and shares the interpolated point between both segments", () => {
    const split = splitRouteCoordinates([[0, 0], [1, 0], [3, 0]], 0.5);
    expect(split.passed).toEqual([[0, 0], [1, 0], [1.5, 0]]);
    expect(split.remaining).toEqual([[1.5, 0], [3, 0]]);
  });

  it("keeps an unknown progress route entirely in the remaining segment", () => {
    const route = [[5.8, 61.4], [6.2, 61.8]] as const;
    expect(splitRouteCoordinates(route, null)).toEqual({ passed: [], remaining: route });
  });

  it("builds separate planned-route, breadcrumb, and ordered-stop features", () => {
    const call = (order: number, quayId: string, name: string, longitude: number, latitude: number): StopCall => ({
      order, quayId, name, longitude, latitude,
      stopPlaceId: null,
      aimedArrivalAt: null,
      expectedArrivalAt: null,
      aimedDepartureAt: null,
      expectedDepartureAt: null,
      realtime: false,
      cancellation: false,
    });
    const vehicle = {
      id: "SKY:Vehicle:110",
      lineCode: "110",
      routeName: "Førde–Stryn",
      state: "live",
      latitude: 61.5,
      longitude: 5.9,
      bearing: 45,
      delaySeconds: 0,
      lastSeenAt: "2026-07-10T12:00:00Z",
      version: "2026-07-10T12:00:00Z",
      nextStop: null,
      trail: [{ longitude: 5.8, latitude: 61.4, observedAt: "2026-07-10T11:59:58Z" }, { longitude: 5.9, latitude: 61.5, observedAt: "2026-07-10T12:00:00Z" }],
      upcomingStops: [],
      routeProgress: 0.5,
      monitoredCall: { order: 2, vehicleAtStop: false },
    } as unknown as VehicleState;
    const journey: JourneySnapshot = {
      serviceJourneyId: "ENT:ServiceJourney:110",
      operatingDate: "2026-07-10",
      datedServiceJourneyId: null,
      version: "journey-v1",
      state: "fresh",
      route: { type: "LineString", coordinates: [[5.8, 61.4], [5.9, 61.5], [6.1, 61.7]], distanceMeters: 40_000 },
      calls: [
        call(1, "q1", "Førde", 5.8, 61.4),
        call(2, "q2", "Next stop", 5.9, 61.5),
        call(3, "q3", "Stryn", 6.1, 61.7),
      ],
      refreshedAt: "2026-07-10T12:00:00Z",
      lastSuccessfulAt: "2026-07-10T12:00:00Z",
      warning: null,
    };
    const data = buildTransportData([], undefined, vehicle, journey, true) as { readonly features: readonly { readonly properties: Readonly<Record<string, unknown>> }[] };
    const kinds = data.features.map(({ properties }) => properties.kind);

    expect(kinds).toEqual(expect.arrayContaining(["journey-route-passed", "journey-route-remaining", "vehicle-trail", "vehicle"]));
    expect(data.features.filter(({ properties }) => properties.kind === "journey-stop").map(({ properties }) => properties.role)).toEqual(["start", "next", "end"]);

    const inferred = buildTransportData([], undefined, { ...vehicle, monitoredCall: null, nextStop: journey.calls[1]! }, journey, true) as { readonly features: readonly { readonly properties: Readonly<Record<string, unknown>> }[] };
    expect(inferred.features.filter(({ properties }) => properties.kind === "journey-stop").map(({ properties }) => properties.role)).toEqual(["start", "next", "end"]);
  });
});
