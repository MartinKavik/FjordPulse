<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum WatchPriority: string
{
    case Background = 'background';
    case Station = 'station';
    case Vehicle = 'selected_vehicle';
    case Focus = 'focus';
}
