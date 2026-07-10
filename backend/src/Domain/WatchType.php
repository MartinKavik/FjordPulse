<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum WatchType: string
{
    case Station = 'station';
    case Vehicle = 'vehicle';
    case Focus = 'focus';
}
