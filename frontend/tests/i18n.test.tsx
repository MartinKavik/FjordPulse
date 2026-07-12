import { cleanup, fireEvent, render, screen } from "@solidjs/testing-library";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { LanguageSwitcher } from "../src/components/LanguageSwitcher";
import { WelcomePanel } from "../src/components/Panels";
import {
  DEFAULT_LANGUAGE,
  I18nProvider,
  LANGUAGE_STORAGE_KEY,
  localize,
  readLanguage,
  rememberLanguage,
} from "../src/state/i18n";

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

beforeEach(() => {
  window.localStorage.clear();
  document.documentElement.lang = "en";
});

describe("localization state", () => {
  it("defaults to Norwegian Bokmål independently of the browser locale", () => {
    const browserLanguage = vi.spyOn(window.navigator, "language", "get").mockReturnValue("en-GB");
    expect(navigator.language).toBe("en-GB");
    expect(readLanguage()).toBe(DEFAULT_LANGUAGE);
    render(() => <I18nProvider><LanguageSwitcher /></I18nProvider>);
    expect(document.documentElement.lang).toBe("nb");
    expect(screen.getByRole("button", { name: "Bytt språk til norsk" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "Bytt språk til engelsk" })).toHaveAttribute("aria-pressed", "false");
    browserLanguage.mockRestore();
  });

  it("updates visible public copy immediately without a reload", async () => {
    render(() => (
      <I18nProvider>
        <LanguageSwitcher />
        <WelcomePanel expanded onExpandedChange={() => undefined} />
      </I18nProvider>
    ));

    expect(screen.getByLabelText("Velkommen")).toHaveTextContent("Finn en holdeplass, se kommende avganger og følg et kjøretøy langs ruten.");
    await fireEvent.click(screen.getByRole("button", { name: "Bytt språk til engelsk" }));
    expect(screen.getByLabelText("Welcome")).toHaveTextContent("Find a station, see upcoming departures, and follow a vehicle along its route.");
    expect(window.localStorage.getItem(LANGUAGE_STORAGE_KEY)).toBe("en");
  });

  it("restores, switches, and persists the selected language reactively", () => {
    window.localStorage.setItem(LANGUAGE_STORAGE_KEY, "en");
    render(() => <I18nProvider><LanguageSwitcher /></I18nProvider>);
    expect(document.documentElement.lang).toBe("en");
    fireEvent.click(screen.getByRole("button", { name: "Switch language to Norwegian" }));
    expect(document.documentElement.lang).toBe("nb");
    expect(window.localStorage.getItem(LANGUAGE_STORAGE_KEY)).toBe("nb");
    expect(screen.getByRole("button", { name: "Bytt språk til norsk" })).toHaveAttribute("aria-pressed", "true");
  });

  it("rejects invalid storage and survives blocked reads and writes", () => {
    expect(readLanguage({ getItem: () => "no", setItem: () => undefined })).toBe("nb");
    expect(readLanguage({ getItem: () => { throw new DOMException("blocked"); }, setItem: () => undefined })).toBe("nb");
    expect(() => rememberLanguage("en", { getItem: () => null, setItem: () => { throw new DOMException("blocked"); } })).not.toThrow();
  });

  it("interpolates complete language pairs without losing unknown placeholders", () => {
    const message = { nb: "Vis alle {count} holdeplasser ({missing})", en: "Show all {count} stops ({missing})" } as const;
    expect(localize("nb", message, { count: 7 })).toBe("Vis alle 7 holdeplasser ({missing})");
    expect(localize("en", message, { count: 7 })).toBe("Show all 7 stops ({missing})");
  });
});
