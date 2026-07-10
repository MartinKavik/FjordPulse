<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\EnturRequestLog;

final readonly class NullEnturRequestObserver implements EnturRequestObserverInterface
{
    public function record(EnturRequestLog $entry): void
    {
        unset($entry);
    }
}
