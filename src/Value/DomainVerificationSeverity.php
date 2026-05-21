<?php

declare(strict_types=1);

namespace App\Value;

enum DomainVerificationSeverity: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
