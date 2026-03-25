<?php

declare(strict_types=1);

namespace App\Value;

enum AlertType: string
{
    case NewUnknownSender = 'new_unknown_sender';
    case FailureSpike = 'failure_spike';
    case PolicyRecommendation = 'policy_recommendation';
    case DnsRecordChanged = 'dns_record_changed';
    case DnsRecordInvalid = 'dns_record_invalid';
    case DnsRecordMissing = 'dns_record_missing';
    case MailboxConnectionError = 'mailbox_connection_error';
    case IpBlacklisted = 'ip_blacklisted';
}
