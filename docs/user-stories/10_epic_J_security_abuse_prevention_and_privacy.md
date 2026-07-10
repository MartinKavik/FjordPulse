# Epic J — Security, abuse prevention, and privacy

Each story includes acceptance criteria and black-box test scenarios executable through the UI/admin/operator surfaces only.

## FP-078 — Keep secrets server-side

**User story:** As an operator, I want secrets to remain server-side, so that users cannot access Entur configuration, admin tokens, or database credentials.

### Acceptance criteria

- No Entur/DB secrets in frontend.
- Secrets stored in Coolify env.
- Logs hide secrets.

### Black-box test scenarios

1. Open browser DevTools Sources and Network. Search visible frontend/network payloads for obvious secrets or DB credentials.
2. Use public app features. Verify frontend never receives Entur client secrets or SurrealDB credentials.
3. Open logs in Coolify and verify tokens/secrets are masked or absent.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-079 — Validate HTTP requests

**User story:** As a developer, I want all HTTP API inputs validated, so that malformed requests do not cause undefined behavior.

### Acceptance criteria

- Station IDs, vehicle IDs, bbox, zoom, filters validated.
- Invalid input returns 4xx JSON.

### Black-box test scenarios

1. In browser address bar or API client, request invalid station ID. Verify structured 4xx response.
2. Request invalid bbox/zoom values. Verify error response and no server crash.
3. Use UI after invalid requests. Verify app remains usable.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-080 — Validate WebSocket messages

**User story:** As a developer, I want all WebSocket messages validated, so that the realtime server is robust.

### Acceptance criteria

- Unknown/invalid/malformed/oversized messages handled safely.

### Black-box test scenarios

1. Use browser console/test harness to send unknown WS message type. Verify structured error response.
2. Send invalid payload for watch_station. Verify validation error and connection remains open.
3. Send malformed JSON/oversized message if harness supports it. Verify safe close/error.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-081 — Rate-limit public users

**User story:** As an operator, I want public user actions rate-limited, so that one user cannot overload the backend or Entur.

### Acceptance criteria

- Limits apply to search, watches, focus, retries, WS message frequency.
- Feedback shown.

### Black-box test scenarios

1. Rapidly type many searches or use automated keyboard input. Verify UI stays responsive and eventually throttles if needed.
2. Rapidly click many stations/focus buttons. Verify rate-limit feedback and admin budget protection.
3. Verify rate limit does not permanently block normal usage after cooldown.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-082 — Protect admin pages

**User story:** As an operator, I want admin pages protected from public access, so that internal telemetry is not exposed.

### Acceptance criteria

- Admin routes and APIs require auth.

### Black-box test scenarios

1. In private browser, open `/admin/status`, `/admin/watches`, and admin API endpoints. Verify access denied/login.
2. Log in, verify access. Log out, verify access removed.
3. Try direct refresh/deep links after logout. Verify protected.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-083 — Minimize personal data

**User story:** As a public user, I want the app to avoid unnecessary personal data collection, so that privacy risk is low.

### Acceptance criteria

- Public browsing no account.
- Random non-identifying sessions.
- No exact user location required.
- Privacy documented.

### Black-box test scenarios

1. Open public app and use core features without signing in. Verify no account prompt.
2. Deny browser location permission if requested. Verify core features still work; ideally location is not requested.
3. Open privacy/about page. Verify data collection behavior is described.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.

## FP-084 — Handle CORS and origins correctly

**User story:** As an operator, I want allowed origins configured, so that only the intended frontend can use the API/WebSocket in production.

### Acceptance criteria

- Production CORS and WS origin checks enforced.
- Dev origins separate.

### Black-box test scenarios

1. From the production frontend domain, verify API and WebSocket work.
2. From an unauthorized origin/test page, attempt API/WS call. Verify request is rejected.
3. Verify dev/staging origins do not work against production unless intentionally allowed.

### Pass evidence

- Screenshot/video or admin/status observation proving the scenario passed.
