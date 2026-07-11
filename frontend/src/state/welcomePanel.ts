export const WELCOME_PANEL_STORAGE_KEY = "fjordpulse:welcome-panel";

type WelcomePanelStorage = Pick<Storage, "getItem" | "setItem">;

function browserStorage(): WelcomePanelStorage | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

export function readWelcomePanelPreference(storage: WelcomePanelStorage | null = browserStorage()): boolean | null {
  if (storage === null) return null;
  try {
    const value = storage.getItem(WELCOME_PANEL_STORAGE_KEY);
    if (value === "expanded") return true;
    if (value === "collapsed") return false;
  } catch {
    // Public browsing still works when storage is disabled or unavailable.
  }
  return null;
}

export function rememberWelcomePanelPreference(
  expanded: boolean,
  storage: WelcomePanelStorage | null = browserStorage(),
): void {
  if (storage === null) return;
  try {
    storage.setItem(WELCOME_PANEL_STORAGE_KEY, expanded ? "expanded" : "collapsed");
  } catch {
    // The in-memory choice remains usable for this page even if persistence fails.
  }
}

export function defaultWelcomePanelExpanded(savedPreference: boolean | null, mobile: boolean): boolean {
  return savedPreference ?? !mobile;
}
