# Epic I — Frontend visual states and responsiveness

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-071 — Implement generated desktop visual states

**User story:** As a user, I want desktop screens to match the approved mockup states, so that the product feels polished and predictable.

### Acceptance criteria

- Desktop states cover default, station, vehicle, fallback, search states, plus the expanded and collapsed introduction layout.
- Collapsing the introduction releases its map column and exposes a small labelled restore control.

### Black-box test scenarios

1. Use the visual test scenario selector or fixtures to open each desktop state. Compare visually to the packaged mockup.
2. Verify text, color, layout, and primary actions match the intended state.
3. Resize to common desktop widths. Verify panels do not overlap critical map controls.
4. Collapse and restore the introduction with mouse and keyboard. Verify the map resizes, focus is preserved, and the explicit choice survives reload.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-072 — Implement mobile default map

**User story:** As a mobile user, I want a clean map-first mobile interface, so that FjordPulse works well on phones.

### Acceptance criteria

- Mobile default shows a full-screen map, compact top bar, clusters, bottom nav, and a small labelled control for the collapsed introduction.
- Opening the introduction uses a compact bottom overlay; it is collapsed by default when no preference has been saved.

### Black-box test scenarios

1. Open on mobile viewport 390x844 or real phone. Verify default map fills screen.
2. Verify bottom nav has Map, Search, Saved, Alerts, Menu.
3. Verify no station panel is open initially.
4. Verify the introduction is initially collapsed, open it from the labelled restore control, and close it again. Confirm the map remains visible and the explicit choice survives reload.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-073 — Implement mobile station bottom sheet

**User story:** As a mobile user, I want station details in a bottom sheet, so that the map remains visible while I inspect departures.

### Acceptance criteria

- Selecting station opens a half-height bottom sheet with station, updated age or exceptional warning, departures, and nearby summary.

### Black-box test scenarios

1. On mobile, tap a station. Verify a half-height bottom sheet appears.
2. Verify selected marker remains visible above/behind the sheet.
3. Swipe/click controls inside the sheet. Verify large touch targets.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-074 — Implement mobile station full sheet

**User story:** As a mobile user, I want to expand station details, so that I can read more departures and nearby vehicles.

### Acceptance criteria

- Station sheet expands full height with tabs/sections usable by touch.

### Black-box test scenarios

1. With station sheet open, drag it upward or tap expand. Verify it becomes full-height.
2. Tap Departures/Vehicles/Info tabs. Verify content switches without losing station context.
3. Collapse/close the sheet and verify map returns.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-075 — Implement mobile vehicle Focus mode

**User story:** As a mobile user, I want Focus mode to work naturally on a phone, so that I can follow a vehicle live.

### Acceptance criteria

- Mobile focus shows vehicle marker, trail, following pill, bottom sheet with details.

### Black-box test scenarios

1. On mobile, select vehicle and tap Focus. Verify centered vehicle marker and route/trail.
2. Verify green Following Line 100 pill and Pause control.
3. Verify bottom sheet shows line, route, last seen, delay, Unfocus/Details buttons.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-076 — Implement mobile vehicle lost state

**User story:** As a mobile user, I want a clear lost vehicle state, so that I know tracking has stopped or become unavailable.

### Acceptance criteria

- Mobile lost shows dimmed marker, warning sheet, last seen, Stop following/Try again.

### Black-box test scenarios

1. Use lost vehicle fixture on mobile. Verify bottom sheet warning appears.
2. Tap Try again. Verify retry/loading and outcome.
3. Tap Stop following. Verify lost state closes and map remains usable.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-077 — Implement design system components

**User story:** As a developer, I want reusable components for markers, chips, rows, banners, panels, and contextual update health, so that UI states remain consistent.

### Acceptance criteria

- Components exist for top bar, search, chips, markers, rows, pills, banners, skeletons, contextual update notices, source attribution, and sheet header.

### Black-box test scenarios

1. Open the component/storybook/design page if available. Verify each required component is shown.
2. Compare components across desktop/mobile screens for consistent colors/spacing.
3. Change global status fixtures from healthy to reconnecting, periodic refresh, and unavailable. Verify healthy global chrome stays absent, one exceptional notice appears consistently, and resource-level ages/warnings remain independent.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
