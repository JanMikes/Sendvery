<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Why a receiver chose *not* to apply the domain's published DMARC policy to a
 * message — the `<reason><type>` inside `<policy_evaluated>`, RFC 7489 §6.7.3.
 *
 * This is the strongest forwarder evidence available short of a DKIM signature
 * surviving the hop, because it is **receiver-attested**: a sender can publish
 * any PTR record it likes, but it cannot make Gmail claim it overrode a policy.
 * Gmail emits `local_policy` with an `arc=pass` comment once it has validated
 * an ARC chain; Microsoft 365 only honours sealers on the tenant's trusted
 * list. Neither is forgeable from the sending side.
 *
 * Its present value is nil: **0 of the 81 DMARC reports in production today
 * carry a `<reason>` element at all**. This is forward-looking instrumentation
 * for reporters that do emit it — it explains nothing about the data we already
 * have, and it is not a fix for any current misclassification.
 */
enum PolicyOverrideReasonType: string
{
    /** The receiver knows the message was forwarded. */
    case Forwarded = 'forwarded';

    /** The message fell outside the `pct=` sample, so the policy was not applied. */
    case SampledOut = 'sampled_out';

    /** The relay is on a list of forwarders this receiver trusts. */
    case TrustedForwarder = 'trusted_forwarder';

    /** Mailing-list expansion, which routinely breaks SPF and often DKIM too. */
    case MailingList = 'mailing_list';

    /** Receiver-side policy won over DMARC — Gmail's ARC-validated bucket. */
    case LocalPolicy = 'local_policy';

    /** The RFC's own catch-all; the accompanying `<comment>` carries the detail. */
    case Other = 'other';

    /**
     * Maps a raw `<type>` token from a third-party report onto a case.
     *
     * Reports are written by outside parties, so an unregistered or vendor
     * specific token must never fail an otherwise-valid report. Folding those
     * into `Other` is not a shortcut — RFC 7489 §6.7.3 defines `other` as
     * exactly this bucket ("some policy exception not covered by the other
     * entries in this list"), with the free-text `<comment>` carrying the
     * detail. Matches the parser's existing forgiving `tryFrom(…) ?? Default`
     * style for `disposition`, `dkim` and `spf`.
     */
    public static function fromReportValue(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Other;
    }
}
