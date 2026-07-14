<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

enum DatabaseUserRole: string
{
    case Editor = 'EDITOR';
    case Viewer = 'VIEWER';
}
