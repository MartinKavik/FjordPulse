<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Entur\EnturRequestObserverInterface;
use FjordPulse\Surreal\EnturRequestLogRepository;

final readonly class RepositoryEnturRequestObserver implements EnturRequestObserverInterface
{
    public function __construct(private EnturRequestLogRepository $repository)
    {
    }

    public function record(EnturRequestLog $entry): void
    {
        $this->repository->append($entry);
    }
}
