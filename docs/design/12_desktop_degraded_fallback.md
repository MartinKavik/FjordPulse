# 12_desktop_degraded_fallback: Desktop degraded fallback mode

**Image:** `12_desktop_degraded_fallback.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** WebSocket/live updates unavailable; app falls back to periodic refresh.

## Why this screen matters

This proves the app remains useful when realtime transport is unavailable.

## Key visual elements

- One amber notice: `Live connection interrupted · Updating periodically`.
- Map remains usable with station clusters.
- Right panel explains periodic refresh.
- The selected resource and last-known data remain visible; technical Backend, WebSocket, and Entur cards stay on Admin status.

## Implementation notes

- Realtime transport should be abstracted so UI can switch between WebSocket and polling.
- Do not block station browsing when live mode fails.
- Use one contextual public notice across desktop and mobile. Do not duplicate it in the top bar and footer.
- The backend health endpoint should distinguish API availability from realtime availability.

## Suggested visual/regression scenarios

- `desktop_degraded_fallback`
- `periodic refresh notice`
- `saved data preserved`
- `no public service matrix`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
