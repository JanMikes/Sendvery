<?php

declare(strict_types=1);

namespace App\Value\Dns;

enum IssueSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
