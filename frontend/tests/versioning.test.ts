import { describe, expect, it } from "vitest";
import { compareVersions, emptyVersionLedger, isNewerVersion, recordMessage, shouldApplyMessage } from "../src/state/versioning";
import type { ServerMessage } from "../src/types/domain";

const event = (eventId: string, version: string): ServerMessage => ({
  protocolVersion: 1,
  type: "vehicle_moved",
  scope: "vehicle:SKY:Vehicle:123",
  entityId: "SKY:Vehicle:123",
  eventId,
  version,
  createdAt: version,
  payload: {},
});

describe("version ordering", () => {
  it("orders RFC3339 versions and numeric fallback versions", () => {
    expect(compareVersions("2026-07-10T10:00:00Z", "2026-07-10T10:00:01Z")).toBeLessThan(0);
    expect(compareVersions("12", "9")).toBeGreaterThan(0);
    expect(isNewerVersion(undefined, "1")).toBe(true);
  });

  it("ignores duplicate event IDs and old versions", () => {
    let ledger = emptyVersionLedger();
    const current = event("evt_2", "2026-07-10T10:00:02Z");
    expect(shouldApplyMessage(ledger, current)).toBe(true);
    ledger = recordMessage(ledger, current);
    expect(shouldApplyMessage(ledger, current)).toBe(false);
    expect(shouldApplyMessage(ledger, event("evt_1", "2026-07-10T10:00:01Z"))).toBe(false);
    expect(shouldApplyMessage(ledger, event("evt_3", "2026-07-10T10:00:03Z"))).toBe(true);
  });
});
