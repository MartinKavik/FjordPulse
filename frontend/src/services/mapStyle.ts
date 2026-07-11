import { z } from "zod";
import type { BasemapId, MapConfig } from "../types/domain";

export const BASEMAP_STORAGE_KEY = "fjordpulse.basemap.v1";

const expectedStylePath: Readonly<Record<BasemapId, string>> = {
  satellite: "/maps/hybrid-v4/style.json",
  streets: "/maps/streets-v4/style.json",
};

export function isBasemapId(value: unknown): value is BasemapId {
  return value === "satellite" || value === "streets";
}

export function isAllowedMapTilerStyleUrl(value: string, id: BasemapId): boolean {
  try {
    const url = new URL(value);
    if (url.protocol !== "https:" || url.hostname !== "api.maptiler.com" || url.port !== "") return false;
    if (url.username !== "" || url.password !== "" || url.hash !== "" || url.pathname !== expectedStylePath[id]) return false;
    const query = [...url.searchParams.entries()];
    if (query.length !== 1 || query[0]?.[0] !== "key" || query[0][1].trim() === "") return false;
    return true;
  } catch {
    return false;
  }
}

const basemapStyleSchema = z.object({
  id: z.enum(["satellite", "streets"]),
  label: z.string().min(1).max(40),
  styleUrl: z.string().url(),
}).strict().superRefine((style, context) => {
  if (!isAllowedMapTilerStyleUrl(style.styleUrl, style.id)) {
    context.addIssue({ code: "custom", path: ["styleUrl"], message: `Invalid MapTiler ${style.id} style URL` });
  }
});

export const mapConfigSchema: z.ZodType<MapConfig> = z.object({
  provider: z.literal("maptiler"),
  defaultBasemap: z.enum(["satellite", "streets"]),
  basemaps: z.array(basemapStyleSchema).length(2),
}).strict().superRefine((config, context) => {
  const ids = config.basemaps.map(({ id }) => id);
  if (new Set(ids).size !== 2 || !ids.includes("satellite") || !ids.includes("streets")) {
    context.addIssue({ code: "custom", path: ["basemaps"], message: "Satellite and streets styles are both required" });
  }
  if (!ids.includes(config.defaultBasemap)) {
    context.addIssue({ code: "custom", path: ["defaultBasemap"], message: "Default basemap must be available" });
  }
});

function browserStorage(): Storage | null {
  try { return globalThis.localStorage; } catch { return null; }
}

export function initialBasemap(config: MapConfig, storage?: Pick<Storage, "getItem"> | null): BasemapId {
  let stored: string | null = null;
  try { stored = (storage === undefined ? browserStorage() : storage)?.getItem(BASEMAP_STORAGE_KEY) ?? null; } catch { /* Storage can be disabled by browser privacy settings. */ }
  if (isBasemapId(stored) && config.basemaps.some(({ id }) => id === stored)) return stored;
  return config.defaultBasemap;
}

export function rememberBasemap(id: BasemapId, storage?: Pick<Storage, "setItem"> | null): void {
  try { (storage === undefined ? browserStorage() : storage)?.setItem(BASEMAP_STORAGE_KEY, id); } catch { /* A successful map must not fail because storage is unavailable. */ }
}

export function styleUrlFor(config: MapConfig, id: BasemapId): string {
  const style = config.basemaps.find((candidate) => candidate.id === id);
  if (style === undefined) throw new Error(`Basemap ${id} is unavailable`);
  return style.styleUrl;
}
