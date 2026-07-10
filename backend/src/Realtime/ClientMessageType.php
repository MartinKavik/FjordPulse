<?php

declare(strict_types=1);

namespace FjordPulse\Realtime;

enum ClientMessageType: string
{
    case WatchStation = 'watch_station';
    case UnwatchStation = 'unwatch_station';
    case WatchVehicle = 'watch_vehicle';
    case UnwatchVehicle = 'unwatch_vehicle';
    case FocusVehicle = 'focus_vehicle';
    case UnfocusVehicle = 'unfocus_vehicle';
    case PauseFocus = 'pause_focus';
    case ResumeFocus = 'resume_focus';
    case Ping = 'ping';
}
