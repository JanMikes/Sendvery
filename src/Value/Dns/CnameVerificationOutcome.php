<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The result of checking the customer's `_dmarc.<domain>` CNAME:
 *  - Verified: it resolves to `<domain>._dmarc.<reportDomain>` (Sendvery).
 *  - PointsElsewhere: a CNAME exists but targets something other than us.
 *  - Missing: the lookup SUCCEEDED and found no CNAME (not propagated yet, or removed).
 *  - LookupFailed: the DNS lookup itself errored — inconclusive. Distinct from
 *    Missing so a transient blip is never treated as "the customer removed their
 *    CNAME" (which would wrongly un-verify the ramp or tear down a live record).
 */
enum CnameVerificationOutcome: string
{
    case Verified = 'verified';
    case PointsElsewhere = 'points_elsewhere';
    case Missing = 'missing';
    case LookupFailed = 'lookup_failed';
}
