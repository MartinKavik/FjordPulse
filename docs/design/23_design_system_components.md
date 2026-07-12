# 23_design_system_components: Design system component board

**Image:** `23_design_system_components.png`  
**Category:** Design system  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Reusable UI component inventory for FjordPulse dark realtime transport dashboard.

## Why this screen matters

This gives frontend implementation a component vocabulary before building the SolidJS prototype.

## Key visual elements

- Top bar, search input, accessible `NO`/`EN` language switcher, resource status chips.
- Map markers and clusters.
- Departure rows, vehicle rows, focus pills.
- Error/stale banners, skeleton rows, contextual update notice, neutral source attribution, mobile bottom sheet header.

## Implementation notes

- Implement these as reusable components/tokens in the SolidJS prototype.
- Treat user-facing text as reactive Norwegian/English content. The switcher exposes the selected state and localized accessible name, while proper names and transport/diagnostic identifiers remain unmodified data.
- Use consistent colors for live, warning/stale, error, selected, and muted states.
- Normal freshness belongs to the selected resource. Global update health is absent while healthy and renders exactly one exceptional notice across desktop/mobile.
- The generated board is a visual direction; exact spacing/text should be defined in CSS/components.
- Component states should become screenshot-tested independently where practical.
- Exercise long labels in both locales at desktop and mobile widths; buttons, tabs, chips, cards, and sheet actions may wrap or reflow but must not clip or overflow their containers.

## Suggested visual/regression scenarios

- `design_system_components`
- `status chips`
- `contextual update notice`
- `source attribution`
- `map markers`
- `departure rows`
- `mobile bottom sheet header`
- `Norwegian and English component labels`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
