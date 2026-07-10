export function resolveMapStyleUrl(configured: string | undefined, deterministic: boolean): string | null {
  if (deterministic) return null;
  const value = configured?.trim();
  if (value === undefined || value === "") return null;
  if (!value.startsWith("/") || value.startsWith("//") || value.includes("://")) {
    throw new Error("Map style URL must be a same-origin absolute path");
  }
  return value;
}
