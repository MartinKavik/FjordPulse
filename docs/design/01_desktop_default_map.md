# 01_desktop_default_map: Desktop default map

**Image:** `01_desktop_default_map.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Default country-level map with station clusters, no station or vehicle selected.

## Why this screen matters

This is the first impression of FjordPulse and defines the map-first product layout. It establishes that the app starts from station clusters rather than loading every live vehicle immediately.

## Key visual elements

- Full desktop shell with top bar, left rail, right welcome panel, bottom telemetry strip.
- Norway-level dark map with station clusters.
- Førde/Nordfjord cluster is highlighted as product focus but not selected.
- Realtime is idle/ready; no vehicle markers are shown.

## Implementation notes

- Use this as the initial route/view after app boot.
- Station clusters should come from local backend/cache, not Entur calls per visitor.
- Right panel can be a welcome/help panel until a station is selected.
- Bottom telemetry should be implemented as a persistent reusable component.

## Suggested visual/regression scenarios

- `desktop_default_map`
- `station clusters visible`
- `no selected station panel`
- `bottom telemetry visible`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
