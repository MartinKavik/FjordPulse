# FjordPulse Design Bundle

This bundle contains the renamed FjordPulse UI mockups and a companion Markdown note for each image.

The set is intended to guide the first SolidJS prototype, CakePHP/AMPHP realtime integration UI, and screenshot/visual-regression test scenarios. The images are design references, not exact implementation specs.

## Verification summary

- Desktop user-facing screens: 14
- Mobile user-facing screens: 5
- Admin/developer screens: 3
- Design system board: 1
- Total image/Markdown pairs: 23

## Screen index

| ID | Title | Category | Image | Notes |
|---|---|---|---|---|
| `01_desktop_default_map` | Desktop default map | Desktop app | `01_desktop_default_map.png` | `01_desktop_default_map.md` |
| `02_desktop_station_fresh` | Desktop station selected — fresh data | Desktop app | `02_desktop_station_fresh.png` | `02_desktop_station_fresh.md` |
| `03_desktop_station_loading` | Desktop station selected — loading | Desktop app | `03_desktop_station_loading.png` | `03_desktop_station_loading.md` |
| `04_desktop_station_empty` | Desktop station selected — no departures | Desktop app | `04_desktop_station_empty.png` | `04_desktop_station_empty.md` |
| `05_desktop_station_stale` | Desktop station selected — stale data | Desktop app | `05_desktop_station_stale.png` | `05_desktop_station_stale.md` |
| `06_desktop_station_error` | Desktop station selected — error | Desktop app | `06_desktop_station_error.png` | `06_desktop_station_error.md` |
| `07_desktop_vehicle_selected` | Desktop vehicle selected | Desktop app | `07_desktop_vehicle_selected.png` | `07_desktop_vehicle_selected.md` |
| `08_desktop_vehicle_focus_following` | Desktop vehicle Focus — following | Desktop app | `08_desktop_vehicle_focus_following.png` | `08_desktop_vehicle_focus_following.md` |
| `09_desktop_vehicle_focus_paused` | Desktop vehicle Focus — paused by user | Desktop app | `09_desktop_vehicle_focus_paused.png` | `09_desktop_vehicle_focus_paused.md` |
| `10_desktop_vehicle_stale` | Desktop vehicle stale | Desktop app | `10_desktop_vehicle_stale.png` | `10_desktop_vehicle_stale.md` |
| `11_desktop_vehicle_lost` | Desktop vehicle lost | Desktop app | `11_desktop_vehicle_lost.png` | `11_desktop_vehicle_lost.md` |
| `12_desktop_degraded_fallback` | Desktop degraded fallback mode | Desktop app | `12_desktop_degraded_fallback.png` | `12_desktop_degraded_fallback.md` |
| `13_desktop_search_results` | Desktop search results | Desktop app | `13_desktop_search_results.png` | `13_desktop_search_results.md` |
| `14_desktop_search_empty` | Desktop search empty | Desktop app | `14_desktop_search_empty.png` | `14_desktop_search_empty.md` |
| `15_mobile_default_map` | Mobile default map | Mobile app | `15_mobile_default_map.png` | `15_mobile_default_map.md` |
| `16_mobile_station_sheet` | Mobile station half sheet | Mobile app | `16_mobile_station_sheet.png` | `16_mobile_station_sheet.md` |
| `17_mobile_station_full_sheet` | Mobile station full sheet | Mobile app | `17_mobile_station_full_sheet.png` | `17_mobile_station_full_sheet.md` |
| `18_mobile_vehicle_focus` | Mobile vehicle Focus | Mobile app | `18_mobile_vehicle_focus.png` | `18_mobile_vehicle_focus.md` |
| `19_mobile_vehicle_lost` | Mobile vehicle lost | Mobile app | `19_mobile_vehicle_lost.png` | `19_mobile_vehicle_lost.md` |
| `20_admin_status` | Admin system status | Admin/dev | `20_admin_status.png` | `20_admin_status.md` |
| `21_admin_watches` | Admin active watches | Admin/dev | `21_admin_watches.png` | `21_admin_watches.md` |
| `22_admin_entur_log` | Admin Entur request log | Admin/dev | `22_admin_entur_log.png` | `22_admin_entur_log.md` |
| `23_design_system_components` | Design system component board | Design system | `23_design_system_components.png` | `23_design_system_components.md` |


## Recommended next step

Use these references to build a SolidJS/Vite static prototype with deterministic mock data. Once the prototype exists, generate screenshots from the real components and treat those as the visual testing baseline.
