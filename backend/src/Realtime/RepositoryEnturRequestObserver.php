<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

use FjordPulse\Dto\EnturRequestLog;
use FjordPulse\Entur\EnturRequestObserverInterface;
use FjordPulse\Surreal\EnturRequestLogRepository;

final readonly class RepositoryEnturRequestObserver implements EnturRequestObserverInterface
{
    /** @var (\Closure(EnturRequestLog): void)|null */
    private ?\Closure $onRecord;

    /** @param (\Closure(EnturRequestLog): void)|null $onRecord */
    public function __construct(private EnturRequestLogRepository $repository, ?\Closure $onRecord = null)
    {
        $this->onRecord = $onRecord;
    }

    public function record(EnturRequestLog $entry): void
    {
        $this->repository->append($entry);
        if ($this->onRecord !== null) {
            ($this->onRecord)($entry);
        }
    }
}
