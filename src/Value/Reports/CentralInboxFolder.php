<?php

declare(strict_types=1);

namespace App\Value\Reports;

/**
 * The four IMAP folders the central reports inbox uses. Folder paths come
 * from env vars at config time (Sendvery/Pending, Sendvery/Processed, …) —
 * this enum is just the logical role each one plays.
 */
enum CentralInboxFolder
{
    case Pending;
    case Processed;
    case Failed;
    case Junk;
}
