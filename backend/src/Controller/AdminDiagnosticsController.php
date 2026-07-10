<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use DateTimeImmutable;
use FjordPulse\Validation\InputValidator;
use FjordPulse\Validation\ValidationFailure;

final class AdminDiagnosticsController extends AppController
{
    public function status(): Response
    {
        $service = $this->openService();
        try {
            return $this->success($service->adminStatus());
        } finally {
            $service->close();
        }
    }

    public function watches(): Response
    {
        try {
            $query = $this->getRequest()->getQueryParams();
            $type = self::optionalEnum($query['type'] ?? null, ['station', 'vehicle', 'focus'], 'type');
            $state = self::optionalEnum($query['state'] ?? null, ['active', 'stale', 'backoff', 'failed', 'expired'], 'state');
            $scope = self::optionalText($query['scope'] ?? null, 'scope', 256);
            $limit = InputValidator::limit($query['limit'] ?? null, 50, 200);
            self::optionalCursor($query['cursor'] ?? null);
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            return $this->success($service->watches($type, $state, $scope, $limit));
        } finally {
            $service->close();
        }
    }

    public function enturLog(): Response
    {
        try {
            $query = $this->getRequest()->getQueryParams();
            $source = self::optionalEnum($query['service'] ?? null, [
                'stop_place_register',
                'geocoder',
                'journey_planner',
                'vehicle_positions',
            ], 'service');
            $outcome = self::optionalEnum($query['outcome'] ?? null, [
                'success',
                'cache_hit',
                'skipped_budget',
                'rate_limited',
                'backoff',
                'timeout',
                'error',
            ], 'outcome');
            $scope = self::optionalText($query['scope'] ?? null, 'scope', 256);
            $from = self::optionalDate($query['from'] ?? null, 'from');
            $to = self::optionalDate($query['to'] ?? null, 'to');
            if ($from !== null && $to !== null && $from > $to) {
                throw new ValidationFailure('invalid_time_range', '`from` must not be later than `to`.', ['field' => 'from']);
            }
            $limit = InputValidator::limit($query['limit'] ?? null, 50, 200);
            self::optionalCursor($query['cursor'] ?? null);
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            return $this->success($service->enturLog($source, $outcome, $scope, $from, $to, $limit));
        } finally {
            $service->close();
        }
    }

    public function realtime(): Response
    {
        $service = $this->openService();
        try {
            return $this->success($service->adminRealtime());
        } finally {
            $service->close();
        }
    }

    public function events(): Response
    {
        try {
            $query = $this->getRequest()->getQueryParams();
            $scope = self::optionalText($query['scope'] ?? null, 'scope', 256);
            $type = self::optionalEnum($query['type'] ?? null, [
                'station_snapshot_changed',
                'vehicle_moved',
                'vehicle_stale',
                'vehicle_lost',
            ], 'type');
            $limit = InputValidator::limit($query['limit'] ?? null, 50, 200);
            self::optionalCursor($query['cursor'] ?? null);
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            return $this->success($service->adminEvents($scope, $type, $limit));
        } finally {
            $service->close();
        }
    }

    public function migrations(): Response
    {
        $service = $this->openService();
        try {
            return $this->success($service->adminMigrations());
        } finally {
            $service->close();
        }
    }

    /** @param list<string> $allowed */
    private static function optionalEnum(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ValidationFailure('invalid_filter', "{$field} filter is invalid.", ['field' => $field]);
        }

        return $value;
    }

    private static function optionalText(mixed $value, string $field, int $maximum): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $maximum) {
            throw new ValidationFailure('invalid_filter', "{$field} filter is invalid.", ['field' => $field]);
        }

        return $value;
    }

    private static function optionalDate(mixed $value, string $field): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 64
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new ValidationFailure('invalid_date', "{$field} must be an RFC3339 timestamp.", ['field' => $field]);
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $error) {
            throw new ValidationFailure('invalid_date', "{$field} must be an RFC3339 timestamp.", ['field' => $field], $error);
        }
    }

    private static function optionalCursor(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value) || strlen($value) > 512) {
            throw new ValidationFailure('invalid_cursor', 'Cursor is invalid.', ['field' => 'cursor']);
        }
    }
}
