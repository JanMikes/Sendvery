<?php

declare(strict_types=1);

namespace App\Value\Reports;

enum QuarantineReason: string
{
    /** No team has this domain in monitored_domain at all. */
    case UnknownDomain = 'unknown_domain';

    /** Domain exists in monitored_domain but no team has verified it yet. */
    case UnverifiedDomain = 'unverified_domain';
}
