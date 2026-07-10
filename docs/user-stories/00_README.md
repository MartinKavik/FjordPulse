# FjordPulse User Stories + Black-Box Test Scenarios

This package contains the full production-complete user story backlog for FjordPulse, extended with black-box test scenarios.

The tests are intentionally written for a human or AI browser agent using only visible behavior:
- mouse/touch interaction
- keyboard interaction
- browser UI
- public pages
- admin/operator pages
- visible logs/status pages
- browser DevTools network/WS inspection where explicitly useful

The tester should not read application source code to decide whether a story passes.

## Files

- `00_black_box_testing_guide.md` — how to execute and record tests.
- `00_manifest.json` — story IDs, epics, and file mapping.
- `01_epic_A_public_app_shell_and_map_foundation.md`
- `02_epic_B_search_and_discovery.md`
- `03_epic_C_station_details_and_departure_boards.md`
- `04_epic_D_vehicle_details_and_focus_mode.md`
- `05_epic_E_realtime_transport_and_message_protocol.md`
- `06_epic_F_entur_integration_and_data_freshness.md`
- `07_epic_G_surrealdb_persistence_and_migrations.md`
- `08_epic_H_admin_and_observability.md`
- `09_epic_I_frontend_visual_states_and_responsiveness.md`
- `10_epic_J_security_abuse_prevention_and_privacy.md`
- `11_epic_K_deployment_and_operations.md`
- `12_epic_L_testing_and_quality.md`
- `13_epic_M_documentation_and_handoff.md`
- `fjordpulse_all_user_stories_blackbox_tests.md` — monolithic combined version.
- `traceability_matrix.csv` — compact story/test inventory.

## Definition of done

FjordPulse is production-complete only when every story has:
1. implemented behavior,
2. passing acceptance criteria,
3. passing black-box scenarios,
4. visual state verified where applicable,
5. documentation updated where applicable,
6. production or staging evidence captured.
