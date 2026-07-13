<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final readonly class MigrationDiagnosticsReport
{
    private const string INTERRUPTED_ATTEMPT_MESSAGE = 'Migration attempt was still running after five minutes and is treated as interrupted.';

    /** @param array<string, mixed> $data */
    private function __construct(private array $data)
    {
    }

    /** @param list<Migration> $releaseMigrations */
    public static function inspect(
        array $releaseMigrations,
        MigrationDiagnosticsSnapshot $database,
        ?DateTimeImmutable $checkedAt = null,
    ): self
    {
        $checkedAt = ($checkedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $releaseByName = [];
        foreach ($releaseMigrations as $migration) {
            $releaseByName[$migration->name] = $migration;
        }

        $appliedByName = [];
        $lastAppliedAt = null;
        foreach ($database->applied as $applied) {
            $appliedByName[$applied->name] = $applied;
            if ($lastAppliedAt === null || $applied->appliedAt > $lastAppliedAt) {
                $lastAppliedAt = $applied->appliedAt;
            }
        }

        $attemptsByName = [];
        foreach ($database->attempts as $attempt) {
            $attemptsByName[$attempt->name] ??= [];
            $attemptsByName[$attempt->name][] = $attempt;
        }

        $names = array_values(array_unique(array_merge(
            array_keys($releaseByName),
            array_keys($appliedByName),
            array_keys($attemptsByName),
        )));
        sort($names, SORT_STRING);
        $counts = [
            'applied' => 0,
            'pending' => 0,
            'checksumMismatch' => 0,
            'orphaned' => 0,
            'failed' => 0,
        ];
        $rows = [];

        foreach ($names as $name) {
            $release = $releaseByName[$name] ?? null;
            $applied = $appliedByName[$name] ?? null;
            $attempt = self::latestAttempt($attemptsByName[$name] ?? [], $release?->checksum);
            $state = self::state($release, $applied, $attempt, $checkedAt);
            $countKey = $state === 'checksum_mismatch' ? 'checksumMismatch' : $state;
            $counts[$countKey]++;
            $inspection = $release === null ? null : MigrationSourceInspection::fromMigration($release);

            $rows[] = [
                'name' => $name,
                'description' => $inspection === null ? '' : ($inspection->description ?? ''),
                'state' => $state,
                'releaseChecksum' => $release?->checksum,
                'databaseChecksum' => $applied?->checksum,
                'appliedAt' => $applied?->appliedAt->format(DateTimeInterface::RFC3339_EXTENDED),
                'lastAttemptedAt' => ($attempt === null ? $applied?->appliedAt : $attempt->startedAt)
                    ?->format(DateTimeInterface::RFC3339_EXTENDED),
                'failureMessage' => self::failureMessage($state, $attempt, $checkedAt),
                'source' => $release?->surql,
                'affectedObjects' => $inspection === null ? [] : $inspection->affectedObjects,
            ];
        }

        $state = $counts['failed'] > 0
            ? 'failed'
            : (($counts['checksumMismatch'] > 0 || $counts['orphaned'] > 0)
                ? 'drift'
                : ($counts['pending'] > 0 ? 'pending' : 'in_sync'));

        return new self([
            'readOnly' => true,
            'checkedAt' => $checkedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'state' => $state,
            'counts' => $counts,
            'lastAppliedAt' => $lastAppliedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            'migrations' => $rows,
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @param list<MigrationAttempt> $attempts */
    private static function latestAttempt(array $attempts, ?string $releaseChecksum): ?MigrationAttempt
    {
        $latest = null;
        foreach ($attempts as $attempt) {
            if ($releaseChecksum !== null && !hash_equals($releaseChecksum, $attempt->checksum)) {
                continue;
            }
            if ($latest === null || $attempt->startedAt > $latest->startedAt) {
                $latest = $attempt;
            }
        }

        return $latest;
    }

    /** @return 'applied'|'pending'|'checksum_mismatch'|'orphaned'|'failed' */
    private static function state(
        ?Migration $release,
        ?AppliedMigration $applied,
        ?MigrationAttempt $attempt,
        DateTimeImmutable $checkedAt,
    ): string {
        if ($release === null) {
            if ($applied !== null) {
                return 'orphaned';
            }

            return self::attemptWithoutLedgerState($attempt, $checkedAt);
        }
        if ($applied !== null && !hash_equals($release->checksum, $applied->checksum)) {
            return 'checksum_mismatch';
        }
        if ($applied !== null) {
            return 'applied';
        }

        return self::attemptWithoutLedgerState($attempt, $checkedAt);
    }

    /** @return 'pending'|'orphaned'|'failed' */
    private static function attemptWithoutLedgerState(
        ?MigrationAttempt $attempt,
        DateTimeImmutable $checkedAt,
    ): string {
        if ($attempt === null) {
            return 'pending';
        }
        if ($attempt->state === 'running') {
            return self::isStaleRunningAttempt($attempt, $checkedAt) ? 'failed' : 'pending';
        }
        if (in_array($attempt->state, ['failed', 'checksum_mismatch', 'applied'], true)) {
            return 'failed';
        }

        return 'orphaned';
    }

    private static function isStaleRunningAttempt(
        MigrationAttempt $attempt,
        DateTimeImmutable $checkedAt,
    ): bool {
        return $attempt->startedAt < $checkedAt->sub(new DateInterval('PT5M'));
    }

    private static function failureMessage(
        string $state,
        ?MigrationAttempt $attempt,
        DateTimeImmutable $checkedAt,
    ): ?string {
        if ($state !== 'failed' || $attempt === null) {
            return null;
        }
        if ($attempt->state === 'running' && self::isStaleRunningAttempt($attempt, $checkedAt)) {
            return self::INTERRUPTED_ATTEMPT_MESSAGE;
        }
        if ($attempt->failureMessage !== null) {
            return $attempt->failureMessage;
        }

        return match ($attempt->state) {
            'failed' => 'Migration attempt failed without a recorded error message.',
            'checksum_mismatch' => 'Migration attempt reported a checksum mismatch without a recorded error message.',
            'applied' => 'Migration attempt was marked applied, but no migration ledger entry was found.',
            default => null,
        };
    }
}
