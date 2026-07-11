# 01_desktop_default_map: Desktop default map

**Image:** `01_desktop_default_map.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Default country-level map with station clusters, no station or vehicle selected.

## Why this screen matters

This is the first impression of FjordPulse and defines the map-first product layout. It establishes that the app starts from station clusters rather than loading every live vehicle immediately.

## Key visual elements

- Full desktop shell with top bar, left rail, right welcome panel, and an unobtrusive neutral transport-source credit.
- The welcome panel has a clear close control; once collapsed, the map uses the released column and a compact labelled `About` edge control restores it.
- Norway-level dark map with station clusters.
- Førde/Nordfjord cluster is highlighted as product focus but not selected.
- Realtime remains lazily idle until a resource is selected; no ready badge or vehicle markers are shown.

## Implementation notes

- Use this as the initial route/view after app boot.
- Station clusters should come from local backend/cache, not Entur calls per visitor.
- Right panel can be a welcome/help panel until a station is selected.
- Keep the first desktop visit expanded, persist only an explicit expanded/collapsed choice, and never let the welcome panel replace station or vehicle details.
- Healthy update delivery and ordinary initial loading should not consume persistent global chrome. Reuse one contextual notice only for reconnecting, periodically updating, or unavailable states.
- Real mode credits `Transport data: Entur` separately from health; fake mode uses the prominent `Demo data` provenance badge.

## Suggested visual/regression scenarios

- `desktop_default_map`
- `station clusters visible`
- `no selected station panel`
- `no duplicate healthy status`
- `neutral transport attribution`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
