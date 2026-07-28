<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Every section a weekly digest can contain, and the single answer to "is this
 * section in THIS week's digest?".
 *
 * WHY THIS EXISTS. The digest is sent as two alternatives — an HTML part and a
 * text/plain part — produced by two different renderers. Each section used to
 * decide its own visibility twice: once in `emails/weekly_digest.html.twig` and
 * once in the plain-text renderer. The two drifted, exactly as duplicated
 * conditions always do. "Waiting for your review" shipped HTML-only and had to
 * be hand-added to the text afterwards, and even then its "Review these
 * senders" link never made it across — a reader on a text-only client was told
 * a decision was outstanding and given no way to act on it.
 *
 * Both renderers now ask this enum instead, and the parity test iterates its
 * cases, so a section cannot quietly exist in one alternative only: adding a
 * case without teaching both renderers about it fails the test, and adding a
 * section to the template without adding a case here fails the section guard.
 *
 * This is a registry of *sections*, not of markup. What each alternative looks
 * like is still each renderer's business — the HTML is hand-tuned, inline-styled
 * table markup for hostile mail clients and the text alternative is deliberately
 * a different shape. Only the question "does this week's digest talk about X?"
 * is shared.
 */
enum WeeklyDigestSection: string
{
    /** Headline volume + pass rate. Always present: a digest with no numbers is not a digest. */
    case Summary = 'summary';

    /** AI-plan teams only, and only when the provider actually answered. */
    case AiSummary = 'ai_summary';

    case DomainBreakdown = 'domain_breakdown';

    /** Unresolved, non-Success alerts from the window, grouped and capped. */
    case AttentionAlerts = 'attention_alerts';

    /** Problems observed fixed during the window — the digest's good news. */
    case ResolvedAlerts = 'resolved_alerts';

    /** Standing authorization state: senders nobody has decided about yet. */
    case SenderReview = 'sender_review';

    /** Senders seen for the first time this week. */
    case NewSenders = 'new_senders';

    case BrokenDns = 'broken_dns';

    case DnsChanges = 'dns_changes';

    public function isPresentIn(WeeklyDigestData $digest, bool $hasAiSummary): bool
    {
        return match ($this) {
            self::Summary => true,
            self::AiSummary => $hasAiSummary,
            self::DomainBreakdown => [] !== $digest->domains,
            self::AttentionAlerts => [] !== $digest->attentionAlerts,
            self::ResolvedAlerts => $digest->resolvedAlertsCount > 0,
            self::SenderReview => $digest->sendersAwaitingReviewCount() > 0,
            self::NewSenders => $digest->newSendersCount() > 0,
            self::BrokenDns => [] !== $digest->currentlyBrokenDns,
            self::DnsChanges => $digest->dnsChangesCount > 0,
        };
    }

    /**
     * True when the HTML alternative introduces this section with a
     * `ui.subheading()` or `ui.callout()` call.
     *
     * Read by the section guard, which counts those macro calls in the template
     * and compares the total against this. That is what catches a section added
     * straight to the markup without registering a case here — the case the
     * enum alone cannot see, because unregistered markup does not ask it
     * anything.
     */
    public function hasHeadingInHtml(): bool
    {
        return match ($this) {
            // The stat tiles and the AI card are their own bespoke blocks: they
            // open the email rather than being introduced inside it.
            self::Summary, self::AiSummary => false,
            default => true,
        };
    }
}
