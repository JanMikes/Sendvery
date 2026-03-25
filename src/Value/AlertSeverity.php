<?php

declare(strict_types=1);

namespace App\Value;

enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
