<?php

declare(strict_types=1);

namespace App\Value\Dns;

enum DkimLookupOutcome: string
{
    case KeyFound = 'key_found';
    case KeyRevoked = 'key_revoked';
    case CnameTargetMissingKey = 'cname_target_missing_key';
    case RecordsButNoDkim = 'records_but_no_dkim';
    case NoRecord = 'no_record';
}
