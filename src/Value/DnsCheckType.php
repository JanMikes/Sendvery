<?php

declare(strict_types=1);

namespace App\Value;

enum DnsCheckType: string
{
    case Spf = 'spf';
    case Dkim = 'dkim';
    case Dmarc = 'dmarc';
    case Mx = 'mx';
}
