<?php

declare(strict_types=1);

namespace App\Value;

enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Good news. Some monitored transitions are unambiguously *desired* —
     * publishing a valid SPF/DKIM/DMARC record for the first time, for
     * instance. Rendering those in the same yellow as "something changed,
     * check it" trained users to read every alert as a problem, so positive
     * events get their own green treatment and never trigger an email.
     */
    case Success = 'success';
}
