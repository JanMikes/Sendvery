<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The result of checking the customer's `_dmarc.<domain>` CNAME:
 *  - Verified: it resolves to `<domain>._dmarc.<reportDomain>` (Sendvery).
 *  - PointsElsewhere: a CNAME exists but targets something other than us.
 *  - Missing: no CNAME at all (not propagated yet, or removed).
 */
enum CnameVerificationOutcome: string
{
    case Verified = 'verified';
    case PointsElsewhere = 'points_elsewhere';
    case Missing = 'missing';
}
