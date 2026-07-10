<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use FjordPulse\Validation\InputValidator;
use FjordPulse\Validation\ValidationFailure;

final class VehiclesController extends AppController
{
    public function view(string $vehicleId): Response
    {
        try {
            $vehicleId = InputValidator::vehicleId($vehicleId);
            $refresh = InputValidator::boolean($this->getRequest()->getQuery('refresh'));
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            $data = $service->vehicle($vehicleId, $refresh);

            return $data === null
                ? $this->failure('vehicle_not_found', 'Vehicle was not found.', ['vehicleId' => $vehicleId], 404)
                : $this->success($data);
        } finally {
            $service->close();
        }
    }
}
