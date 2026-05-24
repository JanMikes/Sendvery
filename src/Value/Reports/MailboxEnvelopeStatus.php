<?php

declare(strict_types=1);

namespace App\Value\Reports;

/**
 * The visible outcome of an envelope on the mailbox detail page. Collapsed
 * from the join of `received_report_email`, `dmarc_report`, and
 * `quarantined_dmarc_report` so the template doesn't have to re-derive it.
 */
enum MailboxEnvelopeStatus: string
{
    case Parsed = 'parsed';
    case Quarantined = 'quarantined';
    case Unparsed = 'unparsed';
}
