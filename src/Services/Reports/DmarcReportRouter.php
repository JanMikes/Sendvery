<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Repository\MonitoredDomainRepository;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\RoutingDecision;

/**
 * Decides which team owns an incoming DMARC report based on the XML's
 * `<policy_published><domain>` field.
 *
 * The lookup is exact-match against verified monitored_domain rows. If the
 * domain is unverified or unknown, the report is quarantined and a domain
 * verification (or new domain add) will release it.
 *
 * No subdomain fallback: if a customer monitors mail.example.com and we
 * get a report for example.com, they're separate. Customers wanting both
 * add both as monitored domains.
 */
final readonly class DmarcReportRouter
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
    ) {
    }

    public function route(string $policyDomain): RoutingDecision
    {
        $normalized = strtolower(trim($policyDomain));

        if ('' === $normalized) {
            return RoutingDecision::ignored('Empty policy_published.domain — not a routable DMARC report.');
        }

        $domain = $this->monitoredDomainRepository->findAnyByName($normalized);
        if (null === $domain) {
            return RoutingDecision::quarantined($normalized, QuarantineReason::UnknownDomain);
        }

        if (null === $domain->dmarcVerifiedAt) {
            // Domain exists but no one has proven control via DNS yet, so
            // don't expose reports to the team until they verify.
            return RoutingDecision::quarantined($normalized, QuarantineReason::UnverifiedDomain);
        }

        return RoutingDecision::routed($domain);
    }
}
