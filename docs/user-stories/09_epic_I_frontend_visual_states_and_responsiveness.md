# Epic I — Frontend visual states and responsiveness

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-071 — Implement generated desktop visual states

**User story:** As a user, I want desktop screens to match the approved mockup states, so that the product feels polished and predictable.

### Acceptance criteria

- Desktop states cover default, station, vehicle, fallback, search states, plus the expanded and collapsed introduction layout in Norwegian and English.
- The `NO`/`EN` switcher remains easy to reach, and localized headings, tabs, status chips, table cells, and action labels wrap or reflow without clipping, unintended horizontal scrolling, or obscuring map controls.

### Black-box test scenarios

1. Use the visual test scenario selector or fixtures to open each desktop state in both Norwegian and English. Compare visually to the packaged mockup and approved coded baselines.
2. Verify text, color, layout, and primary actions match the intended state.
3. Resize to common desktop widths in both languages. Verify localized text does not overflow buttons, cards, navigation, tables, or the viewport and panels do not overlap critical map controls.
4. Collapse and restore the introduction and change language with mouse and keyboard. Verify the map resizes, focus is preserved, and both explicit choices survive reload independently.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-072 — Implement mobile default map

**User story:** As a mobile user, I want a clean map-first mobile interface, so that FjordPulse works well on phones.

### Acceptance criteria

- Mobile default shows a full-screen map, compact top bar, clusters, bottom nav, a small labelled control for the collapsed introduction, and an always-reachable `NO`/`EN` switcher.
- Opening the introduction uses a compact bottom overlay; Norwegian and English text reflows without clipped controls or horizontal viewport overflow, and it is collapsed by default when no preference has been saved.

### Black-box test scenarios

1. Open on mobile viewport 390x844 or real phone in Norwegian and English. Verify the default map fills the screen and the language switcher remains visible without crowding the search control.
2. Verify bottom navigation has the corresponding localized Map, Search, Saved, Alerts, and Menu labels.
3. Verify no station panel is open initially.
4. Verify the introduction is initially collapsed, open it from the labelled restore control, and close it again in each language. Confirm text and actions remain fully visible, the map remains visible, and the explicit choices survive reload.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-073 — Implement mobile station bottom sheet

**User story:** As a mobile user, I want station details in a bottom sheet, so that the map remains visible while I inspect departures.

### Acceptance criteria

- Selecting a station opens a half-height bottom sheet with station, updated age or exceptional warning, count-badged Departures and Vehicles tabs, and Departures active by default. Full vehicle lists are not repeated below the departure board.

### Black-box test scenarios

1. On mobile, tap a station. Verify a half-height bottom sheet appears.
2. Verify selected marker remains visible above/behind the sheet.
3. Swipe/click controls and switch tabs inside the sheet. Verify large touch targets, stable station context, and no duplicated departure/vehicle lists.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-074 — Implement mobile station full sheet

**User story:** As a mobile user, I want to expand station details, so that I can inspect departures, relevant vehicles, and station facts without losing map context.

### Acceptance criteria

- Station sheet expands full height with non-overlapping Departures, Vehicles, and Details tabs usable by touch. Details keeps stable station facts readable while its plain-language data scope and collapsed technical fields avoid crowding the transport lists.

### Black-box test scenarios

1. With station sheet open, drag it upward or tap expand. Verify it becomes full-height.
2. Tap Departures, Vehicles, and Details. Verify content switches without losing station context; platform stays with departures, serving/nearby rows and collapsed coverage stay with Vehicles, and stable facts plus collapsed ID/coordinates/timezone stay with Details.
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

- Components exist for top bar, search, chips, markers, rows, pills, banners, skeletons, contextual update notices, source attribution, sheet header, and an accessible two-state language switcher shared by public, admin, and deterministic scenario surfaces.

### Black-box test scenarios

1. Open the component/storybook/design page if available. Verify each required component is shown in Norwegian and English, with `NO`/`EN` state exposed to assistive technology.
2. Compare components across desktop/mobile/admin screens and both languages for consistent colors/spacing and no label clipping or control overflow.
3. Change global status fixtures from healthy to reconnecting, periodic refresh, and unavailable. Verify healthy global chrome stays absent, one exceptional notice appears consistently, and resource-level ages/warnings remain independent.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
