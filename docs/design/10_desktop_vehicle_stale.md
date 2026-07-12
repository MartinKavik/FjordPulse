# 10_desktop_vehicle_stale: Desktop vehicle stale

**Image:** `10_desktop_vehicle_stale.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Selected vehicle has not reported a new position recently, but the app can continue monitoring and request a fresh position.

## Why this screen matters

Stale vehicle is different from lost vehicle. It should preserve context and allow continued watching.

## Key visual elements

- Amber stale badge.
- Message says last seen 2 min ago.
- Buttons: Refresh position and Stop watching.
- Map shows stale marker/trail without movement emphasis.

## Implementation notes

- Use the 30-second stale threshold before the five-minute unavailable-position threshold; a temporarily omitted nationwide-feed row must age through the same grace rather than jumping straight to lost.
- Refresh position performs the existing bounded retry without closing or recreating the current room/watch.
- Disable and relabel the action while the request is running; on failure, retain the stale vehicle and show an explicit error above the last known details.
- Fade marker and trail instead of removing the vehicle immediately.
- If updates resume, transition back to live/following.

## Suggested visual/regression scenarios

- `desktop_vehicle_stale`
- `amber stale badge`
- `refresh position button`
- `stop watching button`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- The original generated stale-vehicle image had a non-16:9 aspect ratio; this packaged version is fitted onto a 16:9 dark canvas so it works better with the desktop screenshot set.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
