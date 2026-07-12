import type { VehicleTransportMode } from "../types/domain";
import { localize, type Language, type LocalizedText } from "../state/i18n";
import type { IconName } from "./Icon";

const labels = {
  air: { nb: "Fly", en: "Air" },
  bus: { nb: "Buss", en: "Bus" },
  coach: { nb: "Ekspressbuss", en: "Coach" },
  ferry: { nb: "Ferje", en: "Ferry" },
  metro: { nb: "T-bane", en: "Metro" },
  taxi: { nb: "Taxi", en: "Taxi" },
  tram: { nb: "Trikk", en: "Tram" },
  rail: { nb: "Tog", en: "Train" },
  unknown: { nb: "Kjøretøy", en: "Vehicle" },
} as const satisfies Record<VehicleTransportMode, LocalizedText>;

export function vehicleModeLabel(mode: VehicleTransportMode, language: Language): string {
  return localize(language, labels[mode]);
}

export function vehicleModeIcon(mode: VehicleTransportMode): IconName {
  if (mode === "air") return "plane";
  if (mode === "ferry") return "ferry";
  if (mode === "rail" || mode === "metro") return "rail";
  if (mode === "tram") return "tram";
  if (mode === "taxi") return "taxi";
  if (mode === "unknown") return "activity";
  return "bus";
}
