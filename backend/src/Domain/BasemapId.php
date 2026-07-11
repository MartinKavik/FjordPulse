<?php

declare(strict_types=1);

namespace FjordPulse\Domain;

enum BasemapId: string
{
    case Satellite = 'satellite';
    case Streets = 'streets';
}
