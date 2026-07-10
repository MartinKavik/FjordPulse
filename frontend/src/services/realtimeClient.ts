import type { ClientMessage, ClientMessageType, ServerMessage, ServiceState } from "../types/domain";
import { PROTOCOL_VERSION } from "../types/domain";
import { parseServerMessage } from "../types/validators";
import { clientMessageSchema } from "../types/validators";
import { emptyVersionLedger, recordMessage, shouldApplyMessage, type VersionLedger } from "../state/versioning";
import type { HttpClient } from "./httpClient";

export interface RealtimeClientOptions {
  readonly path?: string;
  readonly tokenProvider?: () => Promise<string | null>;
  readonly webSocketFactory?: (url: string) => WebSocket;
  readonly onState?: (state: ServiceState) => void;
  readonly onMessage?: (message: ServerMessage) => void;
  readonly onFallback?: () => void;
}

export function websocketUrl(path: string, token: string | null, location: Pick<Location, "protocol" | "host"> = window.location): string {
  if (!path.startsWith("/") || path.startsWith("//") || path.includes("://")) {
    throw new Error("FjordPulse realtime path must be same-origin");
  }
  const protocol = location.protocol === "https:" ? "wss:" : "ws:";
  const url = new URL(`${protocol}//${location.host}${path}`);
  if (token !== null) url.searchParams.set("token", token);
  return url.toString();
}

export function reconnectDelay(attempt: number): number {
  return Math.min(15_000, 500 * 2 ** Math.min(attempt, 5));
}

function messageKey(type: ClientMessageType, payload: Readonly<Record<string, string>>): string {
  return `${type}:${payload.stationId ?? payload.vehicleId ?? "connection"}`;
}

export class RealtimeClient {
  private socket: WebSocket | null = null;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private reconnectAttempt = 0;
  private manuallyClosed = false;
  private readonly active = new Map<string, ClientMessage>();
  private readonly lastEventByScope = new Map<string, string>();
  private ledger: VersionLedger = emptyVersionLedger();
  private state: ServiceState = "idle";

  public constructor(private readonly options: RealtimeClientOptions = {}) {}

  public get connectionState(): ServiceState {
    return this.state;
  }

  public async connect(): Promise<void> {
    if (this.socket?.readyState === 1 || this.socket?.readyState === 0) return;
    this.manuallyClosed = false;
    this.setState(this.reconnectAttempt === 0 ? "connecting" : "reconnecting");

    try {
      const token = this.options.tokenProvider === undefined ? null : await this.options.tokenProvider();
      if (this.manuallyClosed) return;
      const path = this.options.path ?? import.meta.env.VITE_REALTIME_PATH ?? "/live";
      const socket = (this.options.webSocketFactory ?? ((url) => new WebSocket(url)))(websocketUrl(path, token));
      this.socket = socket;
      socket.addEventListener("open", () => this.handleOpen());
      socket.addEventListener("message", (event) => this.handleRawMessage(String(event.data)));
      socket.addEventListener("close", () => this.handleClose());
      socket.addEventListener("error", () => socket.close());
    } catch {
      this.scheduleReconnect();
    }
  }

  public close(): void {
    this.manuallyClosed = true;
    if (this.reconnectTimer !== null) clearTimeout(this.reconnectTimer);
    if (this.heartbeatTimer !== null) clearInterval(this.heartbeatTimer);
    this.reconnectTimer = null;
    this.heartbeatTimer = null;
    this.socket?.close(1000, "client shutdown");
    this.socket = null;
    this.setState("idle");
  }

  public send(type: ClientMessageType, payload: Readonly<Record<string, string>> = {}, persistent = false): string {
    const id = globalThis.crypto?.randomUUID?.() ?? `msg_${Date.now()}_${Math.random().toString(16).slice(2)}`;
    const message: ClientMessage = { protocolVersion: PROTOCOL_VERSION, id, type, payload };
    if (!clientMessageSchema.safeParse(message).success) throw new Error(`Invalid FjordPulse realtime command: ${type}`);
    if (persistent) this.active.set(messageKey(type, payload), message);
    this.write(message);
    return id;
  }

  public forget(type: ClientMessageType, payload: Readonly<Record<string, string>>): void {
    this.active.delete(messageKey(type, payload));
  }

  private write(message: ClientMessage): void {
    if (this.socket?.readyState === 1) this.socket.send(JSON.stringify(message));
  }

  private handleOpen(): void {
    this.reconnectAttempt = 0;
    this.setState("connected");
    for (const message of this.active.values()) {
      const entityId = message.payload.stationId ?? message.payload.vehicleId;
      const scope = entityId === undefined ? undefined : `${message.payload.stationId === undefined ? "vehicle" : "station"}:${entityId}`;
      const allowsKnownState = message.type === "watch_station" || message.type === "watch_vehicle" || message.type === "focus_vehicle";
      const knownVersion = !allowsKnownState || scope === undefined ? undefined : this.ledger.byScope[scope];
      const lastEventId = !allowsKnownState || scope === undefined ? undefined : this.lastEventByScope.get(scope);
      this.write({ ...message, id: globalThis.crypto?.randomUUID?.() ?? `${message.id}_resubscribe`, payload: { ...message.payload, ...(knownVersion === undefined ? {} : { knownVersion }), ...(lastEventId === undefined ? {} : { lastEventId }) } });
    }
    if (this.heartbeatTimer !== null) clearInterval(this.heartbeatTimer);
    this.heartbeatTimer = setInterval(() => this.send("ping", { sentAt: new Date().toISOString() }), 20_000);
  }

  private handleRawMessage(raw: string): void {
    const message = parseServerMessage(raw);
    if (message === null || !shouldApplyMessage(this.ledger, message)) return;
    this.ledger = recordMessage(this.ledger, message);
    if (message.scope !== undefined && message.eventId !== undefined) this.lastEventByScope.set(message.scope, message.eventId);
    if (message.type === "realtime_degraded") {
      this.setState("degraded");
      this.options.onFallback?.();
    }
    if (message.type === "resync_required") {
      for (const active of this.active.values()) this.write({ ...active, id: globalThis.crypto?.randomUUID?.() ?? `${active.id}_resync` });
    }
    this.options.onMessage?.(message);
  }

  private handleClose(): void {
    this.socket = null;
    if (this.heartbeatTimer !== null) clearInterval(this.heartbeatTimer);
    this.heartbeatTimer = null;
    if (!this.manuallyClosed) this.scheduleReconnect();
  }

  private scheduleReconnect(): void {
    if (this.manuallyClosed || this.reconnectTimer !== null) return;
    this.reconnectAttempt += 1;
    this.setState(this.reconnectAttempt >= 4 ? "degraded" : "reconnecting");
    if (this.reconnectAttempt === 4) this.options.onFallback?.();
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      void this.connect();
    }, reconnectDelay(this.reconnectAttempt));
  }

  private setState(state: ServiceState): void {
    this.state = state;
    this.options.onState?.(state);
  }
}

export function createRealtimeClient(http: HttpClient, options: Omit<RealtimeClientOptions, "tokenProvider"> = {}): RealtimeClient {
  return new RealtimeClient({ ...options, tokenProvider: () => http.createRealtimeToken() });
}
