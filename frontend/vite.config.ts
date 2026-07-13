import { defineConfig } from "vitest/config";
import solid from "vite-plugin-solid";

export default defineConfig({
  plugins: [solid()],
  server: {
    proxy: {
      // Rewrite the proxy Host header to the loopback upstream so Caddy serves
      // its configured site for LAN clients. The browser Origin header remains
      // intact and is still checked by CakePHP and the WebSocket acceptor.
      "/api": { target: "http://127.0.0.1:8080", changeOrigin: true },
      "/live": { target: "ws://127.0.0.1:8081", ws: true, changeOrigin: true },
    },
  },
  build: {
    target: "es2023",
    sourcemap: true,
    // MapLibre is intentionally isolated behind Solid lazy loading; the entry chunk stays small.
    chunkSizeWarningLimit: 1_100,
  },
  test: {
    environment: "jsdom",
    setupFiles: ["./tests/setup.ts"],
    restoreMocks: true,
  },
});
