<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Results\HealthSummaryResult;
use App\Value\DomainHealthFilter;
use App\Value\DomainVerificationSeverity;

/**
 * Aggregates per-domain pass-rate and DMARC verification state into a single
 * "are we healthy?" headline for the dashboard overview banner. Pure
 * computation: the caller pre-fetches data, this just classifies.
 *
 * TASK-098: counts now come from {@see DomainHealthClassifier} so the
 * "X domains need attention" line agrees with the per-card glyphs on the
 * `/app/domains` list and the per-domain banner on `/app/domains/{id}` for
 * the same set of domains. Previously this resolver counted "pass rate < 90"
 * directly, which could over-count (e.g. a verified domain with 99% pass but
 * a missing SPF record was Healthy here but Attention on the list/detail) or
 * under-count (e.g. an unverified domain with 99% pass was Unverified here
 * but Attention on the list when the list filter was Attention). The
 * classifier collapses the three surfaces onto one rule.
 */
final readonly class HealthSummaryResolver
{
    public function __construct(
        private DomainHealthClassifier $domainHealthClassifier,
    ) {
    }

    /**
     * @param array<DomainOverviewResult> $domains
     */
    public function resolve(
        array $domains,
        ?DomainVerificationStatusResult $verificationStatus,
        ?DomainVerificationSeverity $verificationSeverity,
    ): HealthSummaryResult {
        $total = count($domains);

        // Classify each domain via the same service the list cards + detail
        // banner use. `classifyOverview` reads the joined-in DNS-snapshot
        // fields directly off the overview row — no extra query per domain.
        $healthy = 0;
        $attention = 0;
        $unverifiedFromDomains = 0;
        foreach ($domains as $domain) {
            $severity = $this->domainHealthClassifier->classifyOverview($domain);
            match ($severity) {
                DomainHealthFilter::Healthy => $healthy++,
                DomainHealthFilter::Attention => $attention++,
                DomainHealthFilter::Unverified => $unverifiedFromDomains++,
            };
        }

        // Headline-domain verification fallback: when the `$domains` list is
        // empty (LIMIT pruning, partial fetch) but the team has at least one
        // unverified headline domain, surface that as the unverified count
        // anyway so the empty-domains branch doesn't silently render "all
        // healthy". This preserves the pre-TASK-098 single-domain headline
        // behaviour for callers that don't pass the full domains array.
        $unverifiedFallback = (
            DomainVerificationSeverity::Critical === $verificationSeverity
            && null !== $verificationStatus
            && null === $verificationStatus->dmarcVerifiedAt
        ) ? 1 : 0;
        $unverified = max($unverifiedFromDomains, $unverifiedFallback);

        if (0 === $total) {
            return new HealthSummaryResult(
                headline: 'Setup not finished',
                severity: 'error',
                domainsHealthyCount: 0,
                domainsAttentionCount: 0,
                domainsUnverifiedCount: 0,
                domainsTotalCount: 0,
            );
        }

        if ($unverified === $total) {
            return new HealthSummaryResult(
                headline: 'Setup not finished',
                severity: 'error',
                domainsHealthyCount: $healthy,
                domainsAttentionCount: $attention,
                domainsUnverifiedCount: $unverified,
                domainsTotalCount: $total,
            );
        }

        if (0 === $attention && 0 === $unverified) {
            return new HealthSummaryResult(
                headline: 'All domains healthy',
                severity: 'success',
                domainsHealthyCount: $healthy,
                domainsAttentionCount: 0,
                domainsUnverifiedCount: 0,
                domainsTotalCount: $total,
            );
        }

        if (1 === $attention) {
            return new HealthSummaryResult(
                headline: '1 domain needs attention',
                severity: 'warning',
                domainsHealthyCount: $healthy,
                domainsAttentionCount: 1,
                domainsUnverifiedCount: $unverified,
                domainsTotalCount: $total,
            );
        }

        if ($attention > 1) {
            return new HealthSummaryResult(
                headline: sprintf('%d domains need attention', $attention),
                severity: 'warning',
                domainsHealthyCount: $healthy,
                domainsAttentionCount: $attention,
                domainsUnverifiedCount: $unverified,
                domainsTotalCount: $total,
            );
        }

        // Catch-all: unverified > 0 && attention === 0 && unverified < total.
        // At least one domain isn't yet verified, so we can't say "all healthy".
        return new HealthSummaryResult(
            headline: 'Setup not finished',
            severity: 'error',
            domainsHealthyCount: $healthy,
            domainsAttentionCount: $attention,
            domainsUnverifiedCount: $unverified,
            domainsTotalCount: $total,
        );
    }
}
