import { defineConfig } from "vitest/config";
import solid from "vite-plugin-solid";

export default defineConfig({
  plugins: [solid()],
  server: {
    proxy: {
      "/api": { target: "http://localhost:8080", changeOrigin: false },
      "/live": { target: "ws://localhost:8081", ws: true, changeOrigin: false },
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
