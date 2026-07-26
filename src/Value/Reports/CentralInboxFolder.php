<?php

declare(strict_types=1);

namespace App\Value\Reports;

/**
 * The destination IMAP folders the central reports inbox files messages
 * into after processing. Folder paths come from env vars at config time
 * (Sendvery/Processed, Sendvery/Failed, …) — this enum is just the logical
 * role each one plays. Unprocessed messages stay in INBOX (flagged \Seen),
 * so there is no holding folder here.
 */
enum CentralInboxFolder
{
    case Processed;
    case Failed;
    case Junk;
}
