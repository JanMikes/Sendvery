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

    /**
     * A record went from "not published" (or empty) straight to a VALID value.
     * That is the outcome the setup flow asks for, so it must not share the
     * yellow "record changed, review it" treatment — it is reported green and
     * never emailed.
     */
    case DnsRecordPublished = 'dns_record_published';
    case MailboxConnectionError = 'mailbox_connection_error';
    case IpBlacklisted = 'ip_blacklisted';

    /**
     * A domain that was reliably receiving DMARC reports has gone quiet for
     * longer than its own observed cadence.
     *
     * The gap this closes: "no reports yet" was only ever evaluated while
     * `first_report_at` was NULL, so once a domain reported even once the check
     * was unreachable forever. A domain that reported daily for a year and then
     * went silent produced no signal at all — in a product whose entire promise
     * is monitoring, the monitoring stopping was the one thing nobody was told.
     *
     * Warning, not Critical, and deliberately so. Silence has an innocent
     * explanation (the domain genuinely stopped sending mail) as often as a
     * broken one, and this alert is raised ONLY when our own pipeline is
     * provably healthy — so it is a real question for the owner, not a
     * confirmed fault we can assert.
     */
    case ReportsStopped = 'reports_stopped';

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
            self::MailboxConnectionError,
            self::ReportsStopped => AlertSeverity::Warning,
            self::PolicyRecommendation,
            self::ManagedDmarcAdvanced,
            self::ManagedDmarcReady => AlertSeverity::Info,
            self::DnsRecordPublished => AlertSeverity::Success,
        };
    }
}
