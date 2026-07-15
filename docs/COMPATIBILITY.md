# Compatibility and requirements

FjordPulse has two different compatibility surfaces: using the deployed web
application and developing or hosting the complete stack. This document keeps
those boundaries explicit instead of implying that every platform has passed
the same tests.

## Web application

The production frontend is compiled to ES2023 and uses MapLibre GL JS. A browser
therefore needs:

- JavaScript and ES2023 support;
- WebGL 2 with hardware acceleration or a compatible software renderer;
- Fetch, WebSocket, CSS Grid, and modern accessibility APIs;
- HTTPS access to `fjordpulse.kavik.cz` and the configured MapTiler origin.

`localStorage` is optional. When it is unavailable, language, map-layer, and
welcome-panel preferences fall back safely for the current page rather than
preventing the application from loading.

| Browser surface | Current evidence |
|---|---|
| Chromium on Linux | Full automated unit, black-box, accessibility, WebGL map, reconnect, and visual gates |
| Firefox on Linux | Used manually against the production application, including map and realtime views |
| Mobile-sized Chromium | Automated responsive layouts cover 320 px and 390 px browser viewports; `make dev-mobile` exposes the app for a separate physical-device check |
| Physical Android, Safari, and iOS | Not yet recorded in the verified test matrix; ES2023 and WebGL 2 remain required |

The public map requires WebGL 2. Admin diagnostics are ordinary HTML/CSS and do
not require map rendering.

## Local development host

The checked-in tool launchers currently download verified **Linux x86-64**
binaries. The supported local-host baseline is therefore:

- 64-bit Linux on x86-64; Pop!_OS 24.04 and Ubuntu 24.04 are exercised;
- Node.js `22.22.0` for the root workspace (`frontend/package.json` accepts
  Node.js 22.12 or newer);
- GNU Make, Bash, Git, Python 3, ca-certificates, curl, jq, tar, gzip,
  bzip2, coreutils, libstdc++, and util-linux;
- `iproute2` for automatic LAN address detection in `make dev-mobile`;
- Xvfb plus Playwright's Chromium system libraries for browser and visual
  gates.

After installing Node.js `22.22.0`, a practical Debian or Ubuntu bootstrap is:

```bash
sudo apt-get update
sudo apt-get install -y \
  bash bzip2 ca-certificates coreutils curl git gzip iproute2 jq libstdc++6 \
  make python3 tar util-linux xvfb
make install
PLAYWRIGHT_BROWSERS_PATH="$PWD/.tools/playwright" \
  npx playwright install-deps chromium
```

`make install` downloads checksum-pinned FrankenPHP/PHP, Composer, SurrealDB,
Restic, and Chromium artifacts where needed. A separate system PHP, Composer,
SurrealDB, Caddy, FrankenPHP, or database server is not required.

The native launchers are not currently packaged for ARM64, macOS, or Windows.
Use an x86-64 Linux VM for those hosts. WSL2 may work as a Linux environment,
but it is not part of the verified matrix and browser/WebGL tests still need a
working X display.

## Pinned application stack

| Component | Pinned version |
|---|---:|
| Node.js | `22.22.0` |
| SolidJS | `1.9.14` |
| TypeScript | `7.0.2` |
| Vite | `8.1.4` |
| MapLibre GL JS | `5.24.0` |
| FrankenPHP | `1.12.4` |
| PHP | `8.5.8` |
| Caddy embedded by FrankenPHP | `2.11.4` |
| Composer | `2.10.2` |
| CakePHP | pinned `6.x` source commit in `backend/composer.lock` |
| AMPHP | exact packages in `backend/composer.lock` |
| SurrealDB server | `3.2.0` |
| SurrealDB PHP SDK | `2.0.0-alpha.1` |
| Restic | `0.19.1` |
| Playwright | `1.61.1` |

The npm and Composer lockfiles are authoritative. Do not replace these with
unbounded global packages.

## External services

- Entur's open APIs need no user account or API key. The backend sends the
  non-secret `ET-Client-Name` application identifier.
- Normal maps require an operator-managed MapTiler browser key restricted to
  the local or production origins.
- The browser never needs or receives Entur, SurrealDB, Coolify, or server SSH
  credentials.

## Production host

As verified on 15 July 2026, the demo deployment runs on Ubuntu 24.04 x86-64
with Docker 29.6.1 and Coolify 4.1.2. Coolify manages Traefik, TLS, the Compose deployment,
environment configuration, service lifecycle, and the scheduled backup. The
application image contains its own pinned PHP/Caddy/FrankenPHP runtime, so the
host does not install those libraries directly.

The proven host capacity is 4 vCPUs, advertised 8 GB RAM (7.8 GiB visible to
Linux), 2 GiB swap, and an advertised 100 GB disk (95.8 GiB filesystem). This
is the supported demo reference size, not a claim that every smaller server
will pass the same import, build, restore, and recovery gates.
