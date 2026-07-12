# 24_desktop_vehicle_non_passenger: Desktop vehicle not in passenger service

**Image:** `24_desktop_vehicle_non_passenger.png` — generated from the authoritative coded state; no original source mockup exists
**Category:** Desktop app
**Canonical dimensions:** 1440 × 900 px
**State represented:** A live physical vehicle remains selected and followed after it transitions from a public passenger journey to an operational movement.

## Why this screen matters

A vehicle can keep reporting a valid position while travelling to or from a
depot or between public services. Position freshness and passenger-service
state are independent. The screen must preserve useful live tracking without
presenting internal line, destination, delay, or schedule values as rider
information.

## Key visual elements

- The selected vehicle pin remains anchored to the reported map position and
  uses a subdued non-passenger treatment.
- Its single external label says `Not in passenger service`, never `Line 4`.
- The Focus pill says `Following vehicle`, retains Pause and Unfocus, and shows
  the latest observation age.
- The panel retains authoritative mode, vehicle ID, position status, and Last
  seen.
- A concise service-status section explains that the vehicle may be travelling
  to or from a depot or between services.

## Implementation notes

- Render this state only from backend-authored
  `passengerServiceState=non_passenger`; never infer it from warning text or a
  missing Journey Planner result in the browser.
- Keep the live marker, trail, selection, and existing Focus watch. A change in
  passenger-service state must not behave like a position loss or connection
  failure.
- Suppress operational line, route/destination, delay, previous/next stops,
  upcoming-stop progress, route-overview controls, stale-schedule copy, and raw
  Entur warnings.
- If the same physical vehicle later reports a canonical passenger journey,
  restore normal passenger information without reload or reselection.
- Norwegian and English text may wrap, but must not clip, overlap controls, or
  create horizontal page scrolling.

## Suggested visual/regression scenarios

- `desktop_vehicle_non_passenger`
- `live non-passenger selected marker`
- `following vehicle focus pill`
- `passenger metadata absent`
- `Norwegian and English layout without horizontal overflow`

## Notes and caveats

- The deterministic coded scenario and its reviewed bilingual screenshots are
  the visual source of truth for this state.
- Fixture identifiers and internal operational metadata exist to prove they are
  suppressed; they are not rider-facing copy.
- Map geography is deterministic test context rather than a transport claim.
