<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use FjordPulse\Validation\InputValidator;
use FjordPulse\Validation\ValidationFailure;

final class StationsController extends AppController
{
    public function map(): Response
    {
        try {
            $query = $this->getRequest()->getQueryParams();
            $bounds = InputValidator::boundingBox($query['bbox'] ?? null);
            $zoom = InputValidator::zoom($query['zoom'] ?? null);
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            return $this->success($service->stationMap($bounds, $zoom));
        } finally {
            $service->close();
        }
    }

    public function view(string $stationId): Response
    {
        try {
            $stationId = InputValidator::stationId($stationId);
            $refresh = InputValidator::boolean($this->getRequest()->getQuery('refresh'));
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            $data = $service->station($stationId, $refresh);

            return $data === null
                ? $this->failure('station_not_found', 'Station was not found.', ['stationId' => $stationId], 404)
                : $this->success($data);
        } finally {
            $service->close();
        }
    }

    public function departures(string $stationId): Response
    {
        $factory = $this->serviceFactory();
        try {
            $stationId = InputValidator::stationId($stationId);
            $query = $this->getRequest()->getQueryParams();
            $date = ($query['date'] ?? null) === null || $query['date'] === ''
                ? null
                : InputValidator::serviceDate($query['date'], $factory->now());
            $limit = InputValidator::limit($query['limit'] ?? null, 50, 50);
            $cursor = InputValidator::timetableCursor($query['cursor'] ?? null);
            $refresh = InputValidator::boolean($query['refresh'] ?? null);
            if ($date === null && ($cursor !== null || array_key_exists('limit', $query) || $refresh)) {
                throw new ValidationFailure(
                    'invalid_timetable_query',
                    'The date parameter is required when limit, cursor, or refresh is provided.',
                    ['field' => 'date'],
                );
            }
            if ($cursor !== null && $refresh) {
                throw new ValidationFailure(
                    'invalid_timetable_query',
                    'Refresh cannot be combined with a timetable cursor. Restart from the first page.',
                    ['field' => 'refresh'],
                );
            }
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService($factory);
        try {
            try {
                $data = $date === null
                    ? $service->departures($stationId)
                    : $service->dailyDepartures($stationId, $date, $limit, $cursor, $refresh);
            } catch (\InvalidArgumentException $failure) {
                return $this->failure(
                    'invalid_cursor',
                    $failure->getMessage(),
                    ['field' => 'cursor'],
                    400,
                );
            }

            return $data === null
                ? $this->failure('station_not_found', 'Station was not found.', ['stationId' => $stationId], 404)
                : $this->success($data);
        } finally {
            $service->close();
        }
    }

    public function nearbyVehicles(string $stationId): Response
    {
        try {
            $stationId = InputValidator::stationId($stationId);
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            $data = $service->nearbyVehicles($stationId);

            return $data === null
                ? $this->failure('station_not_found', 'Station was not found.', ['stationId' => $stationId], 404)
                : $this->success($data);
        } finally {
            $service->close();
        }
    }
}
