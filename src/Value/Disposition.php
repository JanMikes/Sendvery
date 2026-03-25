<?php

declare(strict_types=1);

namespace App\Value;

enum Disposition: string
{
    case None = 'none';
    case Quarantine = 'quarantine';
    case Reject = 'reject';
}
