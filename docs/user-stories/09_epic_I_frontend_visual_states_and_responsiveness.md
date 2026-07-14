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

- Mobile default shows a full-screen map, a compact top bar with a normally sized search input visible before interaction, clusters, bottom nav, a small labelled control for the collapsed introduction, and an always-reachable `NO`/`EN` switcher.
- The header search action and bottom-navigation Search action focus the same visible input; opening the software keyboard never hides the current query or collapses the input to an icon.
- Opening the introduction uses a compact bottom overlay; Norwegian and English text reflows without clipped controls or horizontal viewport overflow, and it is collapsed by default when no preference has been saved.

### Black-box test scenarios

1. Open on mobile viewport 390x844 or a real phone in Norwegian and English. Verify the default map fills the screen, a usable search input is already visible, and the language switcher remains reachable without crowding it.
2. Tap the header search action and the localized Search item in the bottom navigation. Verify both focus the same visible input, the software keyboard opens, and typed text remains legible.
3. Verify bottom navigation has the corresponding localized Map, Search, Saved, Alerts, and Menu labels and that no station panel is open initially.
4. Type enough search results to overflow the available height. Verify only the result list scrolls, with the input and query still visible and no horizontal viewport overflow.
5. Verify the introduction is initially collapsed, open it from the labelled restore control, and close it again in each language. Confirm text and actions remain fully visible, the map remains visible, and the explicit choices survive reload.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-073 — Implement mobile station bottom sheet

**User story:** As a mobile user, I want station details in a bottom sheet, so that the map remains visible while I inspect departures.

### Acceptance criteria

- Selecting a station opens a half-height bottom sheet with station, updated age or exceptional warning, Departures, Vehicles, and Details tabs, and Departures active by default. The tabs do not show aggregate counts because scheduled departures and live positions have different scopes. Full vehicle lists are not repeated below the departure board.
- The mobile sheet has peek, half, and full snap states. Its grabber remains visible below the top bar with at least a 44 × 44 px touch target; drag, tap, Enter, and Space change snap state without clearing the selected station, active watch, active tab, or loaded data. Only the explicit X control closes the sheet and clears the selection/watch.

### Black-box test scenarios

1. On mobile, tap a station. Verify a half-height bottom sheet appears with its grabber reachable below the top bar and a selected marker visible above/behind it.
2. Drag the grabber down to peek and up through half/full; also use tap and keyboard activation. Verify the sheet snaps predictably, the map is progressively revealed, and the selection, watch, active tab, and loaded data remain unchanged.
3. Switch tabs inside the sheet, minimize it, restore it, and finally use X. Verify large touch targets, stable station context without duplicated departure/vehicle lists, and that only X closes the sheet and clears the selection/watch.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-074 — Implement mobile station full sheet

**User story:** As a mobile user, I want to expand station details, so that I can inspect departures, relevant vehicles, and station facts without losing map context.

### Acceptance criteria

- Station sheet expands full height with non-overlapping Departures, Vehicles, and Details tabs usable by touch. Details keeps stable station facts readable while its plain-language data scope and collapsed technical fields avoid crowding the transport lists.
- The always-reachable grabber moves the same sheet between peek, half, and full by drag, tap, Enter, or Space. A downward gesture from full restores map context rather than closing; selection/watch/tab state survives every snap transition, while X remains the sole close/deselect action.

### Black-box test scenarios

1. With station sheet open, drag it upward or tap/keyboard-activate the grabber. Verify it becomes full-height while the grabber remains reachable below the top bar.
2. Tap Departures, Vehicles, and Details. Verify content switches without losing station context; platform stays with departures, serving/nearby rows and collapsed coverage stay with Vehicles, and stable facts plus collapsed ID/coordinates/timezone stay with Details.
3. Drag full → half → peek and restore it. Verify progressively more map returns without losing selection, watch, tab, or loaded content; then use X and verify that explicit close alone clears the sheet and selection/watch.

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
