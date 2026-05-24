<?php

declare(strict_types=1);

namespace App\Value;

enum DomainHealthFilter: string
{
    case Healthy = 'healthy';
    case Attention = 'attention';
    case Unverified = 'unverified';
}
