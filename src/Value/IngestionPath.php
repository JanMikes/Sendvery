<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Classifies how a single monitored domain currently receives its DMARC
 * reports. Computed by sampling the most-recent envelopes backing each
 * domain's parsed reports — see {@see \App\Query\GetDomainIngestionMatrix}.
 *
 * The four cases are mutually exclusive by construction: `Mixed` is reserved
 * for the unambiguous "both sources appear in the sample window" case (a
 * misconfiguration the UI nudges the user to resolve), so a domain is always
 * exactly one of the four.
 */
enum IngestionPath: string
{
    case Dns = 'dns';
    case Mailbox = 'mailbox';
    case Mixed = 'mixed';
    case None = 'none';
}
