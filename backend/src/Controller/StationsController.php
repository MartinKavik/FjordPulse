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
        try {
            $stationId = InputValidator::stationId($stationId);
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            $data = $service->departures($stationId);

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
