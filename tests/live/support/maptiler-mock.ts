import type { Page } from "@playwright/test";

const satelliteBlue = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAEAAAABAAQMAAACQp+OdAAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURRdPZ////8dYrdwAAAABYktHRAH/Ai3eAAAAB3RJTUUH6gcKCCshkpwSvwAAAA9JREFUKM9jYBgFo4B8AAACQAABjMWrdwAAAABJRU5ErkJggg==",
  "base64",
);
const satelliteGreen = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAEAAAABAAQMAAACQp+OdAAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURShfS////7hwn6AAAAABYktHRAH/Ai3eAAAAB3RJTUUH6gcKCCsne/+3igAAAA9JREFUKM9jYBgFo4B8AAACQAABjMWrdwAAAABJRU5ErkJggg==",
  "base64",
);
const streetsBeige = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAEAAAABAAQMAAACQp+OdAAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURdjQuP///wqHGLMAAAABYktHRAH/Ai3eAAAAB3RJTUUH6gcKCCshkpwSvwAAAA9JREFUKM9jYBgFo4B8AAACQAABjMWrdwAAAABJRU5ErkJggg==",
  "base64",
);

function varint(value: number): number[] {
  const bytes: number[] = [];
  let remaining = value >>> 0;
  while (remaining >= 0x80) {
    bytes.push((remaining & 0x7f) | 0x80);
    remaining >>>= 7;
  }
  bytes.push(remaining);
  return bytes;
}

function bytesField(tag: number, bytes: readonly number[]): number[] {
  return [...varint((tag << 3) | 2), ...varint(bytes.length), ...bytes];
}

function numberField(tag: number, value: number): number[] {
  return [...varint(tag << 3), ...varint(value)];
}

/** Minimal valid SDF glyph range: deterministic blank glyphs keep MapLibre quiet without external font traffic. */
function glyphRange(start: number, end: number): Buffer {
  const fontstack: number[] = [];
  const bitmap = new Array<number>(49).fill(0);
  for (let id = start; id <= end; id += 1) {
    const glyph = [
      ...numberField(1, id),
      ...bytesField(2, bitmap),
      ...numberField(3, 1),
      ...numberField(4, 1),
      ...numberField(5, 0),
      ...numberField(6, 0),
      ...numberField(7, 8),
    ];
    fontstack.push(...bytesField(3, glyph));
  }
  return Buffer.from(bytesField(1, fontstack));
}

export interface MapTilerMock {
  readonly styleRequests: string[];
  readonly tileRequests: string[];
  readonly vectorRequests: string[];
}

function hybridLayers(): readonly Record<string, unknown>[] {
  const localizedName = ["coalesce", ["get", "name:en"], ["get", "name"]];
  const roadLabelFilter = ["all", ["==", ["geometry-type"], "LineString"], ["any", ["==", ["get", "construction"], false], ["!", ["has", "construction"]]]];
  return [
    { id: "Satellite", type: "raster", source: "satellite" },
    {
      id: "Road", type: "line", source: "maptiler_planet", "source-layer": "road", minzoom: 6,
      filter: ["all", ["==", ["geometry-type"], "LineString"], ["match", ["get", "brunnel"], ["tunnel"], false, true], ["any", ["==", ["get", "construction"], false], ["!", ["has", "construction"]]]],
      layout: { "line-cap": "butt", "line-join": "round", visibility: "visible" }, paint: { "line-color": "rgba(255,255,255,.25)", "line-width": 1 },
    },
    { id: "Road labels", type: "symbol", source: "maptiler_planet", "source-layer": "road_label", minzoom: 11, filter: roadLabelFilter, layout: { "symbol-placement": "line", "text-field": localizedName, "text-font": ["Noto Sans Medium"] } },
    {
      id: "Place labels", type: "symbol", source: "maptiler_planet", "source-layer": "place_label", minzoom: 10, maxzoom: 16,
      filter: ["all", ["==", ["geometry-type"], "Point"], ["match", ["get", "class"], ["hamlet", "isolated_dwelling", "neighbourhood", "quarter", "suburb"], true, ["!", ["has", "class"]]]],
      layout: { "symbol-sort-key": ["to-number", ["get", "rank"]], "text-field": localizedName },
    },
    {
      id: "Village labels", type: "symbol", source: "maptiler_planet", "source-layer": "place_label", minzoom: 10, maxzoom: 16,
      filter: ["all", ["==", ["geometry-type"], "Point"], ["match", ["get", "class"], ["village"], true, false]],
      layout: { "symbol-sort-key": ["to-number", ["get", "rank"]], "text-field": localizedName },
    },
    {
      id: "Town labels", type: "symbol", source: "maptiler_planet", "source-layer": "town_label", minzoom: 9, maxzoom: 16,
      filter: ["==", ["geometry-type"], "Point"],
      layout: { "symbol-sort-key": ["+", ["case", ["==", ["get", "capital"], 20], -1000, 0], ["to-number", ["get", "rank"]]], "text-field": localizedName, "text-font": ["Noto Sans Regular"] },
    },
  ];
}

function streetsLayers(): readonly Record<string, unknown>[] {
  const localizedName = ["coalesce", ["get", "name:en"], ["get", "name"]];
  return [
    {
      id: "Place labels", type: "symbol", source: "maptiler_planet_v4", "source-layer": "place_label", minzoom: 9,
      filter: ["all", ["==", ["geometry-type"], "Point"], ["any", ["match", ["get", "class"], ["neighbourhood", "quarter", "suburb"], true, false], ["all", [">=", ["zoom"], 12], ["match", ["get", "class"], ["hamlet", "isolated_dwelling"], true, false]]]],
      layout: { "symbol-sort-key": ["to-number", ["get", "rank"]], "text-field": "{name}" },
    },
    {
      id: "Village labels", type: "symbol", source: "maptiler_planet_v4", "source-layer": "place_label", minzoom: 10,
      filter: ["all", ["==", ["geometry-type"], "Point"], ["match", ["get", "class"], ["village"], true, false]],
      layout: { "symbol-sort-key": ["to-number", ["get", "rank"]], "text-field": "{name}" },
    },
    {
      id: "Town labels", type: "symbol", source: "maptiler_planet_v4", "source-layer": "town_label", minzoom: 6, maxzoom: 16,
      filter: ["==", ["geometry-type"], "Point"],
      layout: { "symbol-sort-key": ["+", ["case", ["==", ["get", "capital"], 20], -1000, 0], ["to-number", ["get", "rank"]]], "text-field": localizedName },
    },
  ];
}

export async function installMapTilerMock(
  page: Page,
  options: { readonly failStyles?: boolean; readonly failTiles?: boolean } = {},
): Promise<MapTilerMock> {
  const styleRequests: string[] = [];
  const tileRequests: string[] = [];
  const vectorRequests: string[] = [];

  await page.route("https://api.maptiler.com/**", async (route) => {
    const url = new URL(route.request().url());
    const styleMatch = /^\/maps\/(hybrid-v4|streets-v4)\/style\.json$/.exec(url.pathname);
    if (styleMatch !== null) {
      styleRequests.push(url.pathname);
      if (options.failStyles === true) {
        await route.fulfill({ status: 503, contentType: "application/json", body: JSON.stringify({ message: "provider unavailable" }) });
        return;
      }
      const satellite = styleMatch[1] === "hybrid-v4";
      const id = satellite ? "satellite" : "streets";
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          version: 8,
          name: `FjordPulse Playwright ${id}`,
          glyphs: "https://api.maptiler.com/mock/fonts/{fontstack}/{range}.pbf?key=playwright-map-key",
          sources: satellite ? {
            satellite: {
              type: "raster",
              tiles: [`https://api.maptiler.com/mock/${id}/{z}/{x}/{y}.png?key=playwright-map-key`],
              tileSize: 64,
              attribution: "© MapTiler © OpenStreetMap contributors",
            },
            maptiler_planet: {
              type: "vector",
              tiles: ["https://api.maptiler.com/mock/vector/{z}/{x}/{y}.pbf?key=playwright-map-key"],
              minzoom: 0,
              maxzoom: 14,
            },
          } : {
            basemap: {
              type: "raster",
              tiles: [`https://api.maptiler.com/mock/${id}/{z}/{x}/{y}.png?key=playwright-map-key`],
              tileSize: 64,
              attribution: "© MapTiler © OpenStreetMap contributors",
            },
            maptiler_planet_v4: {
              type: "vector",
              tiles: ["https://api.maptiler.com/mock/vector/{z}/{x}/{y}.pbf?key=playwright-map-key"],
              minzoom: 0,
              maxzoom: 14,
            },
          },
          layers: [
            { id: "provider-background", type: "background", paint: { "background-color": satellite ? "#174f67" : "#d8d0b8" } },
            ...(satellite ? hybridLayers() : [{ id: "provider-basemap", type: "raster", source: "basemap" }, ...streetsLayers()]),
          ],
        }),
      });
      return;
    }

    const glyphMatch = /^\/mock\/fonts\/[^/]+\/(\d+)-(\d+)\.pbf$/.exec(url.pathname);
    if (glyphMatch !== null) {
      await route.fulfill({ status: 200, contentType: "application/x-protobuf", body: glyphRange(Number(glyphMatch[1]), Number(glyphMatch[2])) });
      return;
    }

    const tileMatch = /^\/mock\/(satellite|streets)\/(\d+)\/(\d+)\/(\d+)\.png$/.exec(url.pathname);
    if (tileMatch !== null) {
      tileRequests.push(url.pathname);
      if (options.failTiles === true) {
        await route.fulfill({ status: 503, contentType: "text/plain", body: "tile provider unavailable" });
        return;
      }
      const [, id, , x, y] = tileMatch;
      const alternatingSatellite = (Number(x) + Number(y)) % 2 === 0 ? satelliteBlue : satelliteGreen;
      await route.fulfill({
        status: 200,
        contentType: "image/png",
        body: id === "satellite" ? alternatingSatellite : streetsBeige,
      });
      return;
    }

    if (/^\/mock\/vector\/\d+\/\d+\/\d+\.pbf$/.test(url.pathname)) {
      vectorRequests.push(url.pathname);
      await route.fulfill({ status: 200, contentType: "application/x-protobuf", body: Buffer.alloc(0) });
      return;
    }

    await route.abort("blockedbyclient");
  });

  return { styleRequests, tileRequests, vectorRequests };
}
