import { For, Show, type Component, type JSX } from "solid-js";
import type { SearchResult, ServiceState } from "../types/domain";
import { FjordPulseLogo, StatusChip } from "./DesignSystem";
import { Icon, type IconName } from "./Icon";

export const TopBar: Component<{
  readonly query: string;
  readonly searchOpen: boolean;
  readonly realtimeState: ServiceState;
  readonly onQuery: (value: string) => void;
  readonly onSearchFocus: () => void;
  readonly onSearchKeyDown: JSX.EventHandlerUnion<HTMLInputElement, KeyboardEvent>;
  readonly setSearchRef?: (element: HTMLInputElement) => void;
}> = (props) => (
  <header class="topbar">
    <FjordPulseLogo />
    <label class="search-field">
      <span class="sr-only">Search for station, place, line, or vehicle</span>
      <Icon name="search" size={22} />
      <input
        ref={(element) => props.setSearchRef?.(element)}
        type="search"
        value={props.query}
        placeholder="Search station, place, line or vehicle…"
        autocomplete="off"
        onInput={(event) => props.onQuery(event.currentTarget.value)}
        onFocus={props.onSearchFocus}
        onKeyDown={props.onSearchKeyDown}
      />
      <kbd>⌘ K</kbd>
    </label>
    <StatusChip state={props.realtimeState} label={props.realtimeState === "connected" ? "Live connected" : props.realtimeState === "idle" ? "Live ready" : undefined} />
    <a class="icon-button desktop-only" href="/admin/status" aria-label="Open admin status"><Icon name="gear" size={22} /></a>
  </header>
);

const navItems: readonly { readonly label: string; readonly icon: IconName; readonly href: string }[] = [
  { label: "Map", icon: "map", href: "/" },
  { label: "Search", icon: "search", href: "#search" },
  { label: "Saved", icon: "star", href: "#saved" },
  { label: "Alerts", icon: "alert", href: "#alerts" },
  { label: "Menu", icon: "menu", href: "#menu" },
];

export const NavigationRail: Component<{ readonly onSearch: () => void }> = (props) => (
  <nav class="navigation-rail" aria-label="Main navigation">
    <For each={navItems}>{(item, index) => (
      <a
        href={item.href}
        class={index() === 0 ? "is-active" : ""}
        aria-current={index() === 0 ? "page" : undefined}
        onClick={(event) => {
          if (item.label === "Search") {
            event.preventDefault();
            props.onSearch();
          }
        }}
      >
        <Icon name={item.icon} size={23} />
        <span>{item.label}</span>
      </a>
    )}</For>
  </nav>
);

function resultIcon(type: SearchResult["type"]): IconName {
  if (type === "line" || type === "vehicle") return "bus";
  if (type === "place") return "pin";
  return "map";
}

export const SearchOverlay: Component<{
  readonly open: boolean;
  readonly query: string;
  readonly results: readonly SearchResult[];
  readonly activeIndex: number;
  readonly loading: boolean;
  readonly onSelect: (result: SearchResult) => void;
  readonly onClose: () => void;
}> = (props) => (
  <Show when={props.open}>
    <div class="search-scrim" onClick={props.onClose} aria-hidden="true" />
    <section class="search-results" id="search-results" aria-label="Search results">
      <Show when={props.loading}><p class="search-message"><span class="spinner" /> Searching FjordPulse…</p></Show>
      <Show when={!props.loading && props.query.trim().length === 0}>
        <p class="search-message"><strong>Explore Norway</strong><span>Try a station, place, line, or known vehicle.</span></p>
      </Show>
      <Show when={!props.loading && props.query.trim().length > 0 && props.results.length > 0}>
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
                <span class="result-icon"><Icon name={resultIcon(result.type)} size={21} /></span>
                <span><strong>{result.label}</strong><small>{result.secondaryText ?? "No additional details"}</small></span>
                <span class="result-type">{result.type}</span>
                <Icon name="chevron" size={16} />
              </button>
            </li>
          )}</For>
        </ul>
      </Show>
      <Show when={!props.loading && props.query.trim().length > 0 && props.results.length === 0}>
        <div class="search-empty">
          <span class="empty-icon"><Icon name="search" size={28} /></span>
          <strong>No stations found.</strong>
          <p>Try a station, place, or line name. Check the spelling or search a nearby town.</p>
        </div>
      </Show>
      <footer><span><kbd>↑</kbd><kbd>↓</kbd> Navigate</span><span><kbd>↵</kbd> Select</span><span><kbd>Esc</kbd> Close</span></footer>
    </section>
  </Show>
);
