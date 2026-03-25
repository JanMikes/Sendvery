<?php

declare(strict_types=1);

namespace App\Value;

enum DmarcAlignment: string
{
    case Relaxed = 'r';
    case Strict = 's';
}
