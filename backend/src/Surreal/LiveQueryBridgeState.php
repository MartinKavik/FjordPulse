<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

enum LiveQueryBridgeState: string
{
    case Stopped = 'stopped';
    case Connecting = 'connecting';
    case Healthy = 'healthy';
    case Reconnecting = 'reconnecting';
    case Degraded = 'degraded';
    case Stopping = 'stopping';
}
