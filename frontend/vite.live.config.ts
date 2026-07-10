import { defineConfig } from "vite";
import solid from "vite-plugin-solid";

const httpOrigin = process.env.FJORDPULSE_LIVE_HTTP_ORIGIN ?? "http://127.0.0.1:19080";
const realtimeOrigin = process.env.FJORDPULSE_LIVE_REALTIME_ORIGIN ?? "ws://127.0.0.1:19081";

/**
 * Isolated Vite configuration for the real-stack browser proof.
 *
 * The normal development config intentionally keeps its conventional ports.
 * This config lets Playwright own an independent CakePHP/realtime stack and,
 * crucially, leaves VITE_DATA_MODE=api so no browser-local fixture path can
 * satisfy the test.
 */
export default defineConfig({
  plugins: [solid()],
  server: {
    strictPort: true,
    proxy: {
      "/api": { target: httpOrigin, changeOrigin: false },
      "/live": { target: realtimeOrigin, ws: true, changeOrigin: false },
    },
  },
  build: {
    target: "es2023",
    sourcemap: true,
    chunkSizeWarningLimit: 1_100,
  },
});
