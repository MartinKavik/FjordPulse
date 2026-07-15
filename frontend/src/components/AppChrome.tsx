import { For, Show, type Component, type JSX } from "solid-js";
import type { SearchResult, Telemetry } from "../types/domain";
import { localize, useI18n, type Language, type LocalizedText } from "../state/i18n";
import { FjordPulseLogo } from "./DesignSystem";
import { Icon, type IconName } from "./Icon";
import { LanguageSwitcher } from "./LanguageSwitcher";
import { vehicleModeIcon, vehicleModeLabel } from "./VehicleMode";

function searchShortcutLabel(): string {
  return typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform) ? "⌘ K" : "Ctrl K";
}

export const TopBar: Component<{
  readonly query: string;
  readonly searchOpen: boolean;
  readonly updateNotice: RiderUpdateNotice | null;
  readonly onQuery: (value: string) => void;
  readonly onSearchFocus: () => void;
  readonly onSearchKeyDown: JSX.EventHandlerUnion<HTMLInputElement, KeyboardEvent>;
  readonly setSearchRef?: (element: HTMLInputElement) => void;
}> = (props) => {
  const i18n = useI18n();
  return (
    <header class="topbar">
      <FjordPulseLogo />
      <label class={`search-field${props.searchOpen ? " is-open" : ""}`}>
        <span class="sr-only">{i18n.text({ nb: "Søk etter holdeplass, sted, linje eller kjøretøy", en: "Search for station, place, line, or vehicle" })}</span>
        <Icon name="search" size={22} />
        <input
          ref={(element) => props.setSearchRef?.(element)}
          type="search"
          value={props.query}
          placeholder={i18n.text({ nb: "Søk etter holdeplass, sted, linje eller kjøretøy …", en: "Search station, place, line or vehicle…" })}
          autocomplete="off"
          autocapitalize="none"
          enterkeyhint="search"
          spellcheck={false}
          aria-controls={props.searchOpen ? "search-results" : undefined}
          onInput={(event) => props.onQuery(event.currentTarget.value)}
          onFocus={props.onSearchFocus}
          onKeyDown={props.onSearchKeyDown}
        />
        <kbd>{searchShortcutLabel()}</kbd>
      </label>
      <Show when={props.updateNotice} keyed>{(notice) => <UpdateNotice notice={notice} />}</Show>
      <LanguageSwitcher class="topbar-language-switcher" />
      <a class="icon-button desktop-only" href="/admin/status" aria-label={i18n.text({ nb: "Åpne systemstatus", en: "Open admin status" })}><Icon name="gear" size={22} /></a>
    </header>
  );
};

export type RiderUpdateNotice = "unavailable" | "polling" | "reconnecting";

/**
 * Transport health is useful to riders only while they are viewing a resource.
 * A working polling fallback outranks transient socket states, while a failed
 * backend and socket must never be softened to "updating periodically".
 */
export function riderUpdateNotice(telemetry: Telemetry, hasActiveResource: boolean): RiderUpdateNotice | null {
  if (!hasActiveResource) return null;
  if (telemetry.backend === "offline"
    || (telemetry.backend === "degraded" && telemetry.realtime === "offline")
    || (telemetry.realtime === "offline" && telemetry.refreshMode !== "polling")) return "unavailable";
  if (telemetry.refreshMode === "polling") return "polling";
  if (telemetry.realtime === "reconnecting") return "reconnecting";
  return null;
}

const updateNoticeContent = {
  unavailable: { icon: "alert", message: { nb: "Oppdateringer er midlertidig utilgjengelige · Viser lagret informasjon", en: "Updates temporarily unavailable · Showing saved information" } },
  polling: { icon: "clock", message: { nb: "Sanntidsforbindelsen er brutt · Oppdaterer regelmessig", en: "Live connection interrupted · Updating periodically" } },
  reconnecting: { icon: "wifi", message: { nb: "Kobler til sanntidsoppdateringer på nytt …", en: "Reconnecting to live updates…" } },
} as const satisfies Readonly<Record<RiderUpdateNotice, { readonly icon: IconName; readonly message: LocalizedText }>>;

const navItems = [
  { id: "map", label: { nb: "Kart", en: "Map" }, icon: "map", href: "/" },
  { id: "search", label: { nb: "Søk", en: "Search" }, icon: "search", href: "#search" },
] as const satisfies readonly { readonly id: "map" | "search"; readonly label: LocalizedText; readonly icon: IconName; readonly href: string }[];

const resultTypeLabels = {
  station: { nb: "Holdeplass", en: "Station" },
  place: { nb: "Sted", en: "Place" },
  line: { nb: "Linje", en: "Line" },
  vehicle: { nb: "Kjøretøy", en: "Vehicle" },
} as const satisfies Record<SearchResult["type"], LocalizedText>;

function resultLabel(result: SearchResult, language: Language): string {
  if (result.type === "line") {
    return localize(language, { nb: "Linje {line}", en: "Line {line}" }, { line: result.lineCode ?? result.label.replace(/^Line\s+/i, "") });
  }
  if (result.type === "vehicle") {
    const identifier = result.label.replace(/^Vehicle\s+/i, "");
    return localize(language, { nb: "{mode} {id}", en: "{mode} {id}" }, { mode: vehicleModeLabel(result.transportMode ?? "unknown", language), id: identifier });
  }
  return result.label;
}

function resultSecondaryText(value: string | null, language: Language): string | null {
  if (value === null || language === "en") return value;
  return value
    .replace(/^Not in passenger service$/, "Ikke i passasjertrafikk")
    .replace(/^Station · /, "Holdeplass · ")
    .replace(/^Ferry terminal · /, "Ferjeterminal · ")
    .replace(/^Place · /, "Sted · ")
    .replace(/^Line /, "Linje ");
}

function searchErrorMessage(language: Language): string {
  return language === "nb"
    ? "Kontroller tilkoblingen og prøv igjen."
    : "Check your connection and try again.";
}

export const UpdateNotice: Component<{ readonly notice: RiderUpdateNotice }> = (props) => {
  const i18n = useI18n();
  // Treat the lookup as fallible at runtime. A development HMR boundary or an
  // older persisted component tree can briefly carry a value that predates the
  // current public notice contract; that must never break resource rendering.
  const content = () => updateNoticeContent[props.notice] as (typeof updateNoticeContent)[RiderUpdateNotice] | undefined;
  return (
    <Show when={content()} keyed>{(value) => (
      <div class={`update-notice state-${props.notice}`} role="status" aria-label={i18n.text({ nb: "Oppdateringsstatus", en: "Update status" })} data-state={props.notice}>
        <Icon name={value.icon} size={18} />
        <strong>{i18n.text(value.message)}</strong>
      </div>
    )}</Show>
  );
};

export const NavigationRail: Component<{ readonly onSearch: () => void }> = (props) => {
  const i18n = useI18n();
  return (
    <nav class="navigation-rail" aria-label={i18n.text({ nb: "Hovedmeny", en: "Main navigation" })}>
      <For each={navItems}>{(item, index) => (
        <a
          href={item.href}
          class={`${index() === 0 ? "is-active" : ""}${item.id === "search" ? " navigation-search" : ""}`}
          aria-current={index() === 0 ? "page" : undefined}
          onClick={(event) => {
            if (item.id === "search") {
              event.preventDefault();
              props.onSearch();
            }
          }}
        >
          <Icon name={item.icon} size={23} />
          <span>{i18n.text(item.label)}</span>
        </a>
      )}</For>
      <a class="mobile-navigation-only" href="/admin/status">
        <Icon name="gear" size={23} />
        <span>{i18n.text({ nb: "Admin", en: "Admin" })}</span>
      </a>
    </nav>
  );
};

function resultIcon(result: SearchResult): IconName {
  if (result.type === "vehicle") return vehicleModeIcon(result.transportMode ?? "unknown");
  if (result.type === "line") return "bus";
  if (result.type === "place") return "pin";
  return "map";
}

export const SearchOverlay: Component<{
  readonly open: boolean;
  readonly query: string;
  readonly settledQuery?: string;
  readonly results: readonly SearchResult[];
  readonly activeIndex: number;
  readonly waiting?: boolean;
  readonly loading: boolean;
  readonly progressVisible?: boolean;
  readonly error?: string | null;
  readonly onSelect: (result: SearchResult) => void;
  readonly onRetry?: () => void;
  readonly onClose: () => void;
}> = (props) => {
  const i18n = useI18n();
  const waiting = () => props.waiting === true;
  const pending = () => waiting() || props.loading;
  const showProgress = () => props.loading && props.progressVisible === true;
  const trimmedQuery = () => props.query.trim();
  const completedQuery = () => props.settledQuery?.trim() || trimmedQuery();
  const queryTooShort = () => trimmedQuery().length > 0 && trimmedQuery().length < 2;
  return (
    <Show when={props.open}>
      <div class="search-scrim" onClick={props.onClose} aria-hidden="true" />
      <section class="search-results" id="search-results" aria-label={i18n.text({ nb: "Søkeresultater", en: "Search results" })} aria-busy={props.loading}>
        <Show when={pending() && !showProgress()}><div class="search-pending-placeholder" aria-hidden="true" /></Show>
        <Show when={showProgress()}>
          <p class="search-message" role="status" aria-live="polite" aria-atomic="true">
            <span class="spinner" aria-hidden="true" />
            <span>{i18n.text({ nb: "Søker etter «{query}» …", en: "Searching for “{query}”…" }, { query: completedQuery() })}</span>
          </p>
        </Show>
        <Show when={!pending() && props.error !== undefined && props.error !== null}>
          <div class="search-empty" role="alert">
            <span class="empty-icon"><Icon name="alert" size={28} /></span>
            <strong>{i18n.text({ nb: "Søket er midlertidig utilgjengelig.", en: "Search is temporarily unavailable." })}</strong>
            <p>{searchErrorMessage(i18n.language())}</p>
            <Show when={props.onRetry}><button type="button" class="button button-primary search-retry" onClick={() => props.onRetry?.()}>{i18n.text({ nb: "Prøv igjen", en: "Retry" })}</button></Show>
          </div>
        </Show>
        <Show when={!pending() && props.error == null && trimmedQuery().length === 0}>
          <p class="search-message"><strong>{i18n.text({ nb: "Utforsk Norge", en: "Explore Norway" })}</strong><span>{i18n.text({ nb: "Prøv en holdeplass, et sted, en linje eller et kjent kjøretøy.", en: "Try a station, place, line, or known vehicle." })}</span></p>
        </Show>
        <Show when={!pending() && props.error == null && queryTooShort()}>
          <p class="search-message"><strong>{i18n.text({ nb: "Skriv minst to tegn for å søke.", en: "Type at least two characters to search." })}</strong></p>
        </Show>
        <Show when={!pending() && props.error == null && !queryTooShort() && trimmedQuery().length > 0 && props.results.length > 0}>
          <ul role="listbox">
            <For each={props.results}>{(result, index) => (
              <li>
                <button
                  type="button"
                  class={index() === props.activeIndex ? "is-active" : ""}
                  role="option"
                  aria-selected={index() === props.activeIndex}
                  onClick={() => props.onSelect(result)}
                >
                  <span class="result-icon"><Icon name={resultIcon(result)} size={21} /></span>
                  <span><strong>{resultLabel(result, i18n.language())}</strong><small>{resultSecondaryText(result.secondaryText, i18n.language()) ?? i18n.text({ nb: "Ingen flere opplysninger", en: "No additional details" })}</small></span>
                  <span class="result-type">{result.type === "vehicle" ? vehicleModeLabel(result.transportMode ?? "unknown", i18n.language()) : i18n.text(resultTypeLabels[result.type])}</span>
                  <Icon name="chevron" size={16} />
                </button>
              </li>
            )}</For>
          </ul>
        </Show>
        <Show when={!pending() && props.error == null && !queryTooShort() && trimmedQuery().length > 0 && props.results.length === 0}>
          <div class="search-empty" role="status" aria-live="polite">
            <span class="empty-icon"><Icon name="search" size={28} /></span>
            <strong>{i18n.text({ nb: "Ingen treff for «{query}».", en: "No results for “{query}”." }, { query: completedQuery() })}</strong>
            <p>{i18n.text({ nb: "Prøv et annet steds- eller holdeplassnavn, linjenummer eller kjøretøy-ID.", en: "Try another place or station name, line number, or vehicle ID." })}</p>
          </div>
        </Show>
        <footer>
          <span><kbd>↑</kbd><kbd>↓</kbd> {i18n.text({ nb: "Naviger", en: "Navigate" })}</span>
          <span><kbd>↵</kbd> {i18n.text({ nb: "Velg", en: "Select" })}</span>
          <span><kbd>Esc</kbd> {i18n.text({ nb: "Lukk", en: "Close" })}</span>
        </footer>
      </section>
    </Show>
  );
};
