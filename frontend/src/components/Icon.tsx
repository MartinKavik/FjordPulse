import type { Component } from "solid-js";

export type IconName =
  | "activity"
  | "alert"
  | "arrow"
  | "bus"
  | "check"
  | "chevron"
  | "clock"
  | "close"
  | "database"
  | "focus"
  | "ferry"
  | "gear"
  | "layers"
  | "logout"
  | "map"
  | "menu"
  | "pause"
  | "plane"
  | "pin"
  | "refresh"
  | "rail"
  | "search"
  | "server"
  | "star"
  | "taxi"
  | "tram"
  | "users"
  | "wifi";

export interface IconProps {
  readonly name: IconName;
  readonly size?: number;
  readonly label?: string;
}

const paths: Record<IconName, string> = {
  activity: "M3 12h4l2-7 4 14 2-7h6",
  alert: "M12 3 2.5 20h19L12 3Zm0 6v4m0 3h.01",
  arrow: "m5 12 14-7-5 14-2-6-7-1Z",
  bus: "M6 17h12M7 20h.01M17 20h.01M6 4h12a2 2 0 0 1 2 2v11H4V6a2 2 0 0 1 2-2Zm-2 7h16M8 7h8",
  check: "m5 12 4 4L19 6",
  chevron: "m9 18 6-6-6-6",
  clock: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-13v5l3 2",
  close: "m6 6 12 12M18 6 6 18",
  database: "M4 6c0-2 3.6-3 8-3s8 1 8 3-3.6 3-8 3-8-1-8-3Zm0 0v6c0 2 3.6 3 8 3s8-1 8-3V6M4 12v6c0 2 3.6 3 8 3s8-1 8-3v-6",
  focus: "M8 3H3v5m13-5h5v5M8 21H3v-5m13 5h5v-5M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z",
  ferry: "M4 16h16l-2 5H6l-2-5Zm2 0V8h12v8M8 8l1-4h6l1 4M3 12h18M4 22c2-1 4-1 6 0s4 1 6 0 4-1 5 0",
  gear: "M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7.4-3.5 1.6 1.2-2 3.5-1.9-.8a7 7 0 0 1-2 1.2l-.2 2H9l-.2-2a7 7 0 0 1-2-1.2l-1.9.8-2-3.5L4.6 12a7 7 0 0 1 0-2L3 8.8l2-3.5 1.9.8a7 7 0 0 1 2-1.2L9 3h6l.2 2a7 7 0 0 1 2 1.2l1.9-.8 2 3.5L19.4 10a7 7 0 0 1 0 2Z",
  layers: "m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5",
  logout: "M10 17l5-5-5-5m5 5H3m11-8h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4",
  map: "m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16",
  menu: "M4 7h16M4 12h16M4 17h16",
  pause: "M8 5v14m8-14v14",
  plane: "M12 2a2 2 0 0 0-2 2v7l-7 4v2l7-2v5l-2 1v1l4-1 4 1v-1l-2-1v-5l7 2v-2l-7-4V4a2 2 0 0 0-2-2Z",
  pin: "M12 21s7-6 7-12a7 7 0 1 0-14 0c0 6 7 12 7 12Zm0-9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z",
  refresh: "M21 12a9 9 0 1 1-2.64-6.36L21 8M21 3v5h-5",
  rail: "M7 3h10a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3Zm-3 8h16M8 15h.01M16 15h.01M8 19l-2 3m10-3 2 3M8 22h8",
  search: "m21 21-4.3-4.3M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z",
  server: "M4 4h16v6H4V4Zm0 10h16v6H4v-6Zm3-7h.01M7 17h.01",
  star: "m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z",
  taxi: "M5 17h14l-1-6-2-4H8l-2 4-1 6Zm1-6h12M7 17v2m10-2v2M8 14h.01M16 14h.01M10 7V5h4v2",
  tram: "M7 4h10a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3Zm-3 7h16M8 15h.01M16 15h.01M8 19l-2 3m10-3 2 3M8 22h8M9 1l3 3 3-3",
  users: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m7-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8",
  wifi: "M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01",
};

export const Icon: Component<IconProps> = (props) => (
  <svg
    class="icon"
    width={props.size ?? 20}
    height={props.size ?? 20}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    role={props.label === undefined ? undefined : "img"}
    aria-hidden={props.label === undefined ? "true" : undefined}
    aria-label={props.label}
  >
    <path d={paths[props.name]} />
  </svg>
);
