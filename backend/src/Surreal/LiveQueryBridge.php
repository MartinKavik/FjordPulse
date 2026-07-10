<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use Amp\Cancellation;
use FjordPulse\Dto\RealtimeEvent;

interface LiveQueryBridge
{
    /**
     * @param \Closure(RealtimeEvent): void $onEvent
     * @param (\Closure(LiveQueryBridgeStatus): void)|null $onRecovery
     */
    public function run(\Closure $onEvent, ?\Closure $onRecovery = null, ?Cancellation $cancellation = null): void;

    public function stop(): void;

    public function status(): LiveQueryBridgeStatus;
}
