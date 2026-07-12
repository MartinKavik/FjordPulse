# 25_mobile_vehicle_non_passenger: Mobile vehicle not in passenger service

**Image:** `25_mobile_vehicle_non_passenger.png` — generated from the authoritative coded state; no original source mockup exists
**Category:** Mobile app
**Canonical dimensions:** 390 × 844 px
**State represented:** A live non-passenger vehicle remains selected and followed with a responsive vehicle sheet.

## Why this screen matters

The mobile sheet has less room to explain an operational movement, yet it must
remain immediately clear that the map position is live while no public
passenger journey is active. Focus controls and the map marker must remain
usable without leaking internal schedule data into the compact layout.

## Key visual elements

- A subdued selected pin remains visible on the map with the external label
  `Not in passenger service`.
- The Focus pill says `Following vehicle` and keeps Pause and Unfocus reachable.
- The expanded sheet shows mode, vehicle ID, position status, Last seen, and the
  concise non-passenger explanation.
- No line, route, delay, previous/next stop, upcoming-stop, or route-overview
  content appears.

## Implementation notes

- Share passenger-service semantics with desktop; mobile is a responsive
  presentation, not a separate classifier.
- Verify the marker and Focus pill before expanding the sheet, then verify all
  explanatory content and actions in the expanded sheet.
- Do not render stale-schedule language or raw Entur diagnostics for the
  backend-classified non-passenger state.
- Both Norwegian and English must fit at 390 × 844 and the supported 320 px
  narrow width without clipped controls or horizontal viewport scrolling.

## Suggested visual/regression scenarios

- `mobile_vehicle_non_passenger`
- `mobile live non-passenger marker`
- `mobile following vehicle focus pill`
- `expanded non-passenger sheet`
- `320 px Norwegian and English overflow guard`

## Notes and caveats

- The deterministic coded scenario and its reviewed bilingual screenshots are
  the visual source of truth for this state.
- Fixture identifiers and internal operational metadata exist only to verify
  suppression.
- Map geography is deterministic test context rather than a transport claim.
