# 23_design_system_components: Design system component board

**Image:** `23_design_system_components.png`  
**Category:** Design system  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Reusable UI component inventory for FjordPulse dark realtime transport dashboard.

## Why this screen matters

This gives frontend implementation a component vocabulary before building the SolidJS prototype.

## Key visual elements

- Top bar, search input, status chips.
- Map markers and clusters.
- Departure rows, vehicle rows, focus pills.
- Error/stale banners, skeleton rows, telemetry strip, mobile bottom sheet header.

## Implementation notes

- Implement these as reusable components/tokens in the SolidJS prototype.
- Use consistent colors for live, warning/stale, error, selected, and muted states.
- The generated board is a visual direction; exact spacing/text should be defined in CSS/components.
- Component states should become screenshot-tested independently where practical.

## Suggested visual/regression scenarios

- `design_system_components`
- `status chips`
- `map markers`
- `departure rows`
- `mobile bottom sheet header`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
