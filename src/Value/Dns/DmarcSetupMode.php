<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * How a monitored domain's DMARC record is hosted:
 *  - SelfTxt: the customer owns the `_dmarc` TXT record (the free default).
 *  - ManagedCname: the customer points `_dmarc` at Sendvery via an immutable
 *    CNAME, and Sendvery hosts + ramps the policy (paid managed DMARC, DEC-058).
 */
enum DmarcSetupMode: string
{
    case SelfTxt = 'self_txt';
    case ManagedCname = 'managed_cname';
}
