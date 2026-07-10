<?php

declare(strict_types=1);

namespace FjordPulse\Entur;

use FjordPulse\Dto\EnturRequestLog;

interface EnturRequestObserverInterface
{
    public function record(EnturRequestLog $entry): void;
}
