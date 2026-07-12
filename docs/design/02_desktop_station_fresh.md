# 02_desktop_station_fresh: Desktop station selected — fresh data

**Image:** `02_desktop_station_fresh.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Selected station with fresh departure, vehicle, and stable station-detail resources allocated across three non-overlapping tabs.

## Why this screen matters

This is the core happy path: click a station and move predictably between a departure board, relevant live vehicles, and durable station facts. It defines the most important right-panel information hierarchy without making users scan the same vehicles twice.

## Key visual elements

- Selected “Førde rutebilstasjon” marker is prominent on the map.
- Departures is active by default and its badge reports the rendered upcoming-row count.
- Departure rows include time, line, destination, platform when reported, and on-time/delayed/scheduled/cancelled status. No live-vehicle list is repeated below them.
- Vehicles has its own unique-row count and exclusively contains `Vehicles serving this station` plus `Other nearby vehicles`. It separates on-the-way/at-station, unknown-progress, and passed relations and removes overlaps between the two groups.
- A concise data-coverage sentence remains visible in Vehicles; its exact service window and candidate/queried/truncated figures sit in a collapsed disclosure.
- Details replaces Info. It shows useful stable facts first—place, station type, and transport modes—then a plain-language explanation of what the departure and live-position data covers. Missing locality fields are omitted instead of repeated as placeholder cards. Stop ID, coordinates, and `Europe/Oslo` are available in a collapsed technical-details disclosure.

## Implementation notes

- Keep the station snapshot's departure, station-serving, coverage, and nearby fields independently truthful when one upstream source fails.
- Keep each full resource in exactly one tab. Count badges must derive from rendered departures and de-duplicated rendered vehicles, not raw source-array totals.
- Use stable row components for both station-serving and nearby vehicles; clicking either opens the existing vehicle detail and selected map marker.
- Derive station-serving rows only by matching the exact dated service-journey identities from the bounded six-hours-before/six-hours-after station board to currently reporting Vehicle Positions records. Never present this as an exhaustive all-Norway lookup.
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
- `station-serving relation rows visible`
- `far-away matched vehicle opens selected marker`
- `other nearby vehicle rows visible`
- `coverage and technical details collapsed by default`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
