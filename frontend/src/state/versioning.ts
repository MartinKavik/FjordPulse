import type { ServerMessage } from "../types/domain";

export interface VersionLedger {
  readonly byScope: Readonly<Record<string, string>>;
  readonly eventIds: ReadonlySet<string>;
}

export function compareVersions(left: string, right: string): number {
  if (left === right) return 0;

  const leftDate = Date.parse(left);
  const rightDate = Date.parse(right);
  if (Number.isFinite(leftDate) && Number.isFinite(rightDate)) {
    return Math.sign(leftDate - rightDate);
  }

  const leftNumber = Number(left);
  const rightNumber = Number(right);
  if (Number.isFinite(leftNumber) && Number.isFinite(rightNumber)) {
    return Math.sign(leftNumber - rightNumber);
  }

  return left.localeCompare(right);
}

export function isNewerVersion(current: string | undefined, incoming: string): boolean {
  return current === undefined || compareVersions(incoming, current) > 0;
}

export function shouldApplyMessage(ledger: VersionLedger, message: ServerMessage): boolean {
  if (message.eventId !== undefined && ledger.eventIds.has(message.eventId)) return false;
  if (message.scope === undefined || message.version === undefined) return true;
  return isNewerVersion(ledger.byScope[message.scope], message.version);
}

export function recordMessage(ledger: VersionLedger, message: ServerMessage, maxEventIds = 500): VersionLedger {
  const eventIds = new Set(ledger.eventIds);
  if (message.eventId !== undefined) eventIds.add(message.eventId);
  while (eventIds.size > maxEventIds) {
    const first = eventIds.values().next().value as string | undefined;
    if (first === undefined) break;
    eventIds.delete(first);
  }

  const byScope = { ...ledger.byScope };
  if (message.scope !== undefined && message.version !== undefined) byScope[message.scope] = message.version;
  return { byScope, eventIds };
}

export const emptyVersionLedger = (): VersionLedger => ({ byScope: {}, eventIds: new Set<string>() });
