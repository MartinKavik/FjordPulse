const OSLO_TIME = new Intl.DateTimeFormat("en-GB", {
  timeZone: "Europe/Oslo",
  hour: "2-digit",
  minute: "2-digit",
});

const OSLO_DATE_TIME = new Intl.DateTimeFormat("en-GB", {
  timeZone: "Europe/Oslo",
  day: "2-digit",
  month: "short",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
});

const OSLO_TIME_WITH_SECONDS = new Intl.DateTimeFormat("en-GB", {
  timeZone: "Europe/Oslo",
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
});

export function formatTransportTime(value: string): string {
  return OSLO_TIME.format(new Date(value));
}

export function formatOsloDateTime(value: string): string {
  return OSLO_DATE_TIME.format(new Date(value));
}

export function formatOsloTime(value: string | number | Date): string {
  return OSLO_TIME_WITH_SECONDS.format(new Date(value));
}

export function formatRelativeTime(value: string | null, nowMs: number): string {
  if (value === null) return "—";
  const timestamp = Date.parse(value);
  if (!Number.isFinite(timestamp)) return "—";
  const seconds = Math.max(0, Math.floor((nowMs - timestamp) / 1_000));
  if (seconds < 1) return "now";
  if (seconds < 60) return `${seconds}s ago`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

const COMPASS_POINTS = [
  "N", "NNE", "NE", "ENE", "E", "ESE", "SE", "SSE",
  "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW",
] as const;

export function compassPoint(bearing: number): string {
  const normalized = ((bearing % 360) + 360) % 360;
  return COMPASS_POINTS[Math.round(normalized / 22.5) % 16] ?? "N";
}

export function formatBearing(bearing: number | null): string {
  return bearing === null ? "Not reported" : `${Math.round(bearing)}° ${compassPoint(bearing)}`;
}

export function formatDelay(seconds: number | null): string {
  if (seconds === null) return "Not reported";
  if (Math.abs(seconds) < 30) return "On time";
  const minutes = Math.max(1, Math.round(Math.abs(seconds) / 60));
  return seconds < 0 ? `${minutes} min early` : `+${minutes} min`;
}
