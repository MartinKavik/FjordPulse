# 02_desktop_station_fresh: Desktop station selected — fresh data

**Image:** `02_desktop_station_fresh.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Selected station with fresh departure, vehicle, and stable station-detail resources allocated across three non-overlapping tabs.

## Why this screen matters

This is the core happy path: click a station and move predictably between a departure board, relevant live vehicles, and durable station facts. It defines the most important right-panel information hierarchy without making users scan the same vehicles twice.

## Key visual elements

- Selected “Førde rutebilstasjon” marker is prominent on the map.
- Departures is active by default. The top-level tabs do not carry numeric badges because departure rows and live-vehicle positions have different scopes and their totals are not meaningfully comparable.
- Departure rows include time, line, destination, platform when reported, and on-time/delayed/scheduled/cancelled status. No live-vehicle list is repeated below them.
- The compact board shows the next 20 departures found between now and the end of the current `Europe/Oslo` day. A visible `View today's timetable` action opens the on-demand daily board; that board groups earlier, next, and later departures and progressively reveals long lists.
- Vehicles exclusively contains scoped, independently counted groups: `At station or due within 60 minutes`, `Calls here later`, collapsed `Already passed this station`, and `Other live vehicles within 5 km`. It removes overlaps between station-linked and nearby groups and never shows one unexplained aggregate vehicle number.
- A concise data-coverage sentence remains visible in Vehicles; its exact service window and candidate/queried/truncated figures sit in a collapsed disclosure.
- Details replaces Info. It shows useful stable facts first—place, station type, and transport modes—then a plain-language explanation of what the departure and live-position data covers. Missing locality fields are omitted instead of repeated as placeholder cards. Stop ID, coordinates, and `Europe/Oslo` are available in a collapsed technical-details disclosure.

## Implementation notes

- Keep the station snapshot's departure, station-serving, coverage, and nearby fields independently truthful when one upstream source fails.
- Keep each full resource in exactly one tab. Do not put incomparable departure and vehicle totals in the tab labels; counts belong next to the precisely scoped section they describe.
- Keep the realtime station snapshot bounded to the next 20 rows. Load the Oslo-local calendar-day timetable only after explicit user action through FjordPulse HTTP, cache it separately without a database event, and paginate the rendered result. Never rebroadcast the full daily board on every station refresh.
- Use stable row components for both station-serving and nearby vehicles; clicking either opens the existing vehicle detail and selected map marker.
- Derive station-linked rows only by matching the exact dated service-journey identities from the bounded six-hours-before/six-hours-after station board to currently reporting Vehicle Positions records. Keep call role (`starts_here` or `calls_here`) separate from observed progress (`at_station`, `before_station`, `after_station`, or `unknown`). Time-bucket the result so a service starting several hours later is never presented as starting now. Never present this as an exhaustive all-Norway lookup.
- If either an upstream call list or FjordPulse's 200-journey lookup reaches its coverage limit, prioritize upcoming departures and show a provider-neutral truncation warning inside the coverage disclosure. Candidate counts describe the distinct journeys observed in returned calls and are only lower bounds when truncated.
- Scope loading and failure content to the affected Departures or Vehicles tab. Known Details facts remain readable while transport data loads or fails, with a compact Retry notice in Details when live content fails.
- Station watch should be registered when the panel opens.
- Vehicle rows should be clickable and should open the vehicle panel.

## Suggested visual/regression scenarios

- `desktop_station_fresh_departures`
- `desktop_station_fresh_vehicles`
- `desktop_station_fresh_details`
- `selected station marker`
- `departure rows and platforms visible only under Departures`
- `station-linked rows expose separate call role and progress`
- `far-away matched vehicle opens selected marker`
- `other nearby vehicle rows visible`
- `coverage and technical details collapsed by default`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
