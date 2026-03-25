<?php

declare(strict_types=1);

namespace App\Value;

enum DmarcPolicy: string
{
    case None = 'none';
    case Quarantine = 'quarantine';
    case Reject = 'reject';
}
