<?php

declare(strict_types=1);

namespace FjordPulse\Controller;

use Cake\Http\Response;
use FjordPulse\Validation\InputValidator;
use FjordPulse\Validation\ValidationFailure;

final class SearchController extends AppController
{
    public function index(): Response
    {
        try {
            $parameters = $this->getRequest()->getQueryParams();
            $query = InputValidator::query($parameters['q'] ?? null);
            $limit = InputValidator::limit($parameters['limit'] ?? null);
        } catch (ValidationFailure $failure) {
            return $this->failure($failure->errorCode, $failure->getMessage(), $failure->details, 400);
        }
        $service = $this->openService();
        try {
            return $this->success($service->search($query, $limit));
        } finally {
            $service->close();
        }
    }
}
