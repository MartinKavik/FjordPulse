import { DEFAULT_LANGUAGE, languageLocale, localize, type Language } from "../state/i18n";

function osloFormatter(language: Language, options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
  return new Intl.DateTimeFormat(languageLocale(language), { timeZone: "Europe/Oslo", ...options });
}

export function formatTransportTime(value: string, language: Language = DEFAULT_LANGUAGE): string {
  return osloFormatter(language, { hour: "2-digit", minute: "2-digit" }).format(new Date(value));
}

export function formatOsloDateTime(value: string, language: Language = DEFAULT_LANGUAGE): string {
  return osloFormatter(language, {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  }).format(new Date(value));
}

export function formatOsloTime(value: string | number | Date, language: Language = DEFAULT_LANGUAGE): string {
  return osloFormatter(language, { hour: "2-digit", minute: "2-digit", second: "2-digit" }).format(new Date(value));
}

export function formatRelativeTime(value: string | null, nowMs: number, language: Language = DEFAULT_LANGUAGE): string {
  if (value === null) return "—";
  const timestamp = Date.parse(value);
  if (!Number.isFinite(timestamp)) return "—";
  const seconds = Math.max(0, Math.floor((nowMs - timestamp) / 1_000));
  if (seconds < 1) return localize(language, { nb: "nå", en: "now" });
  if (seconds < 60) return localize(language, { nb: "{count} sek siden", en: "{count}s ago" }, { count: seconds });
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return localize(language, { nb: "{count} min siden", en: "{count}m ago" }, { count: minutes });
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return localize(language, { nb: "{count} t siden", en: "{count}h ago" }, { count: hours });
  return localize(language, { nb: "{count} d siden", en: "{count}d ago" }, { count: Math.floor(hours / 24) });
}

const COMPASS_POINTS = {
  nb: [
    "N", "NNØ", "NØ", "ØNØ", "Ø", "ØSØ", "SØ", "SSØ",
    "S", "SSV", "SV", "VSV", "V", "VNV", "NV", "NNV",
  ],
  en: [
    "N", "NNE", "NE", "ENE", "E", "ESE", "SE", "SSE",
    "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW",
  ],
} as const satisfies Record<Language, readonly string[]>;

export function compassPoint(bearing: number, language: Language = DEFAULT_LANGUAGE): string {
  const normalized = ((bearing % 360) + 360) % 360;
  return COMPASS_POINTS[language][Math.round(normalized / 22.5) % 16] ?? "N";
}

export function formatBearing(bearing: number | null, language: Language = DEFAULT_LANGUAGE): string {
  return bearing === null
    ? localize(language, { nb: "Ikke oppgitt", en: "Not reported" })
    : `${Math.round(bearing)}° ${compassPoint(bearing, language)}`;
}

export function formatDelay(seconds: number | null, language: Language = DEFAULT_LANGUAGE): string {
  if (seconds === null) return localize(language, { nb: "Ikke oppgitt", en: "Not reported" });
  if (Math.abs(seconds) < 30) return localize(language, { nb: "I rute", en: "On time" });
  const minutes = Math.max(1, Math.round(Math.abs(seconds) / 60));
  return seconds < 0
    ? localize(language, { nb: "{count} min før tiden", en: "{count} min early" }, { count: minutes })
    : `+${minutes} min`;
}
