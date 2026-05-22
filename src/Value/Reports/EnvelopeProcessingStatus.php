<?php

declare(strict_types=1);

namespace App\Value\Reports;

enum EnvelopeProcessingStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Quarantined = 'quarantined';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
