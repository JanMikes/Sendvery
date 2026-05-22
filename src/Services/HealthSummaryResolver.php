<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Results\HealthSummaryResult;
use App\Value\DomainVerificationSeverity;

/**
 * Aggregates per-domain pass-rate and DMARC verification state into a single
 * "are we healthy?" headline for the dashboard overview banner. Pure
 * computation: the caller pre-fetches data, this just classifies.
 */
final readonly class HealthSummaryResolver
{
    private const float HEALTHY_PASS_RATE_THRESHOLD = 90.0;

    /**
     * @param array<DomainOverviewResult> $domains
     */
    public function resolve(
        array $domains,
        ?DomainVerificationStatusResult $verificationStatus,
        ?DomainVerificationSeverity $verificationSeverity,
    ): HealthSummaryResult {
        $total = count($domains);

        // Per-domain unverified count for multi-domain teams is a v2 enhancement
        // once GetDnsHealthOverview-style data lands on this surface. For now we
        // surface the headline domain's verification state only.
        $unverified = (
            DomainVerificationSeverity::Critical === $verificationSeverity
            && null !== $verificationStatus
            && null === $verificationStatus->dmarcVerifiedAt
        ) ? 1 : 0;

        $attention = count(array_filter(
            $domains,
            static fn (DomainOverviewResult $domain): bool => $domain->passRate < self::HEALTHY_PASS_RATE_THRESHOLD,
        ));

        // Guard against double-counting: a domain might be both unverified and
        // below 90%. We don't want to claim "negative healthy" — clamp at 0.
        $healthy = max(0, $total - $attention - $unverified);

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
