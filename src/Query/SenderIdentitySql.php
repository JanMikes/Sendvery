<?php

declare(strict_types=1);

namespace App\Query;

use App\Value\SenderRole;

/**
 * The SQL half of {@see \App\Value\ResolvedSender} (DEC-059 §3.2, D1).
 *
 * Every dashboard surface that groups DMARC records by "who sent this" has to
 * agree on what a sender *is*, and the incident that produced DEC-059 was
 * exactly a disagreement: the read queries keyed senders on an IP-derived
 * COALESCE while the enrichment that could have identified them lived
 * elsewhere. Keeping the expressions in one place means a surface cannot
 * silently drift into its own definition of identity again.
 *
 * Identity is the **registrable domain of the PTR record**, never the IP and
 * never the curated organisation name. That is the only key that works with no
 * mapping table at all: `eu.`/`ca.`/`us.cloud-sec-av.com` are one gateway
 * product and `mxb-{1,2,3}-*.seznam.cz` is one relay pool, yet neither vendor
 * appears in {@see \App\Services\OrganizationMapper}'s hand-maintained list —
 * and no hand-maintained list will ever be complete.
 *
 * `rec.resolved_org` / `rec.resolved_hostname` stay at the end of every chain as
 * a fallback. An IP can legitimately have no `sender_identity` row — never
 * resolved, or a failed lookup sitting in retry backoff — and a sender must
 * never vanish from a list or lose its label because a cache row is missing.
 */
final readonly class SenderIdentitySql
{
    private const string FORWARDER = SenderRole::Forwarder->value;

    private const string ESP = SenderRole::Esp->value;

    private const string UNKNOWN = SenderRole::Unknown->value;

    /**
     * Outer join on purpose: the identity cache is populated lazily and
     * bounded per batch, so its absence is a normal state, not an error.
     */
    public const string JOIN = 'LEFT JOIN sender_identity si ON si.source_ip = rec.source_ip';

    /**
     * Mirrors {@see \App\Value\ResolvedSender::identityKey()}.
     */
    public const string IDENTITY_KEY = 'COALESCE(si.registrable_domain, si.hostname, rec.resolved_org, rec.resolved_hostname, rec.source_ip)';

    /**
     * Mirrors {@see \App\Value\ResolvedSender::displayLabel()} — curated
     * organisation, then registrable domain, then hostname, then the raw
     * address as the honest last resort — applied across a whole group.
     *
     * The aggregate sits *inside* each branch rather than around the COALESCE
     * so that a group keyed on `cloud-sec-av.com` still shows a curated
     * organisation name if any single member has one: preference wins over
     * row order.
     */
    public const string GROUPED_DISPLAY_LABEL = 'COALESCE(MAX(si.organization), MAX(si.registrable_domain), MAX(si.hostname), MAX(rec.resolved_org), MAX(rec.resolved_hostname), MAX(rec.source_ip))';

    /**
     * The group's role, most explanatory first.
     *
     * A group is a forwarder as soon as one of its addresses is: knowing that
     * an appliance re-injected the mail is what turns "4% of your mail failed"
     * from an accusation into an explanation, and a pool where only some nodes
     * have been classified yet must not lose that. Only the three objective
     * roles can appear — `own_relay` and `suspicious` are per-team verdicts and
     * are deliberately never written to the shared cache row.
     *
     * NULL (no ELSE branch) means no member of the group has an identity row at
     * all, which reads as "not classified", not as "unknown sender".
     */
    public const string GROUPED_ROLE = "CASE
                WHEN bool_or(si.role = '".self::FORWARDER."') THEN '".self::FORWARDER."'
                WHEN bool_or(si.role = '".self::ESP."') THEN '".self::ESP."'
                WHEN bool_or(si.role IS NOT NULL) THEN '".self::UNKNOWN."'
            END";
}
