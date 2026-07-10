# 10_desktop_vehicle_stale: Desktop vehicle stale

**Image:** `10_desktop_vehicle_stale.png`  
**Category:** Desktop app  
**Packaged dimensions:** 1672 × 941 px  
**State represented:** Selected vehicle has not reported a new position recently, but the app can keep watching.

## Why this screen matters

Stale vehicle is different from lost vehicle. It should preserve context and allow continued watching.

## Key visual elements

- Amber stale badge.
- Message says last seen 2 min ago.
- Buttons: Keep watching and Stop watching.
- Map shows stale marker/trail without movement emphasis.

## Implementation notes

- Use stale threshold before lost threshold.
- Keep current room/watch alive if user chooses Keep watching.
- Fade marker and trail instead of removing the vehicle immediately.
- If updates resume, transition back to live/following.

## Suggested visual/regression scenarios

- `desktop_vehicle_stale`
- `amber stale badge`
- `keep watching button`
- `stop watching button`

## Notes and caveats

- This image is an AI-generated visual reference, not a pixel-perfect specification. Use the later SolidJS prototype as the source of truth for exact typography, spacing, and text.
- The original generated stale-vehicle image had a non-16:9 aspect ratio; this packaged version is fitted onto a 16:9 dark canvas so it works better with the desktop screenshot set.
- Map geography is approximate in mockups and should be replaced by actual MapLibre map rendering in implementation.
- Text and data are representative seed data for design/testing, not live Entur data.
