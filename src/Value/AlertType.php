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

    // Managed DMARC (DEC-058). Regression/dangling are Critical so they also
    // flow through the existing critical-email path; advanced/ready are
    // informational (their own transactional emails carry the detail).
    case ManagedDmarcRegression = 'managed_dmarc_regression';
    case ManagedDmarcDangling = 'managed_dmarc_dangling';
    case ManagedDmarcAdvanced = 'managed_dmarc_advanced';
    case ManagedDmarcReady = 'managed_dmarc_ready';

    /**
     * The natural severity for this alert type. Legacy alert handlers pass an
     * explicit severity; the managed-DMARC handlers derive it from here so the
     * Critical/informational split lives in one place.
     */
    public function defaultSeverity(): AlertSeverity
    {
        return match ($this) {
            self::FailureSpike,
            self::DnsRecordChanged,
            self::DnsRecordInvalid,
            self::DnsRecordMissing,
            self::IpBlacklisted,
            self::ManagedDmarcRegression,
            self::ManagedDmarcDangling => AlertSeverity::Critical,
            self::NewUnknownSender,
            self::MailboxConnectionError => AlertSeverity::Warning,
            self::PolicyRecommendation,
            self::ManagedDmarcAdvanced,
            self::ManagedDmarcReady => AlertSeverity::Info,
        };
    }
}
