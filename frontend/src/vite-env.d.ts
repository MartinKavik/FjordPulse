/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE?: string;
  readonly VITE_REALTIME_PATH?: string;
  readonly VITE_DATA_MODE?: "api" | "fixture";
  readonly VITE_ENABLE_FIXTURES?: "true" | "false";
  readonly VITE_FALLBACK_POLL_MS?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
