<?php

declare(strict_types=1);

namespace App\Value\Reports;

enum ReportSource: string
{
    case CentralInbox = 'central_inbox';
    case ByoMailbox = 'byo_mailbox';
}
