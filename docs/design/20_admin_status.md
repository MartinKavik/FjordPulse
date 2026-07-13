# 20_admin_status: Admin system status

**Image:** `20_admin_status.png`  
**Category:** Admin/dev  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** A focused operator overview answering whether FjordPulse is working, what needs attention, and where to investigate next.

## Why this screen matters

System status is a triage surface, not a dump of every available diagnostic. An
operator should understand the overall state and the affected path within one
desktop viewport, then follow a clear link to the page that owns the evidence.

## Information architecture

- The overall-health banner names the current state, explains neutral
  demand-driven inactivity, and shows compact environment, data-mode, and build
  context.
- Service health is one compact, divided list rather than a grid of oversized
  cards. Each state sits immediately beside its service name; latency remains a
  quieter trailing measurement. Realtime delivery groups the server and
  database-event bridge as adjacent independent subchecks with their own state
  and latency.
- Healthy services show state and latency without repeating success prose.
  Idle, degraded, reconnecting, and offline services retain their explanatory
  diagnostic.
- `Live demand` / `Sanntidsaktivitet` presents browser connections plus active
  station, vehicle, and Focus scopes as one neutral grouped panel. These are
  operational concurrency figures, never unique-visitor analytics.
- Links lead to Infrastructure, Active watches, Realtime diagnostics, and
  Persisted events. Routine event rows, raw payloads, room details, and watch
  lifecycle rows remain on those dedicated pages.
- Map-provider configuration, deployment/database identity, CPU/RAM/disk, and
  stored-data inventory live on the distinct Infrastructure page.
- The complete FjordPulse-to-Entur allowance and request evidence live on Entur
  request log.

## Navigation and responsive behavior

- `Systemstatus` / `System status` remains the one canonical health destination.
  `/admin` and the former `/admin/overview` resolve compatibly to it but do not
  create duplicate navigation labels.
- `Infrastruktur` / `Infrastructure` is a genuine second task and route, not a
  renamed alias.
- Desktop uses the persistent sidebar. At widths up to 900 px, a labelled Menu
  button opens the same navigation, overall connection state, signed-in
  identity, and explicit Log out action in a modal drawer. The page behind it
  is inert, Tab/Shift+Tab remain inside, the scrim or Escape closes it, and focus
  returns to Menu.
- Norwegian and English text may wrap but must not clip controls, overlap, or
  introduce horizontal page scrolling.

## Reliability and truthfulness

- Realtime-server and live-query-bridge fields remain separate in backend and
  protocol contracts even though the overview groups them visually.
- Entur with no request in five minutes is neutral `NOT RECENTLY USED` / `IKKE
  BRUKT NYLIG`; only a recent failure, timeout, provider limit, or backoff is a
  degraded state.
- Healthy map configuration must not be presented as proof that a browser can
  currently reach the tile provider; Infrastructure explains the verification
  boundary.
- Missing metrics are omitted. Active watches require connected persisted
  demand, a future lease, and a non-expired state.
- Supporting diagnostics use at least 14 px type and compact state labels at
  least 12 px, with meaning that does not depend on color.
- The page remains protected by admin authentication and never substitutes
  fixture claims for missing production measurements.

## Suggested visual/regression scenarios

- `admin_status` in Norwegian and English
- healthy and degraded Realtime delivery subchecks
- neutral Entur inactivity
- desktop single-viewport hierarchy
- mobile Menu drawer, Escape focus return, and no-overflow checks

## Notes and caveats

The packaged image predates the final information split. The coded SolidJS
scenario and reviewed Playwright baselines define the current exact hierarchy,
copy, and geometry.
