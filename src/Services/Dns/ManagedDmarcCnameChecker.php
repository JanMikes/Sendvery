<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Services\ReportAddressProvider;
use App\Value\Dns\CnameVerificationOutcome;
use Spatie\Dns\Dns;

/**
 * Verifies the customer's `_dmarc.<domain>` CNAME for managed DMARC (DEC-058):
 *  - Verified iff it resolves (case-insensitively) to
 *    `<domain>._dmarc.<reportDomain>` (Sendvery's zone);
 *  - PointsElsewhere if a CNAME resolves to something else;
 *  - Missing if there is no CNAME at all.
 *
 * Also offers a LIVE coexisting-TXT check the managed-verify route uses to block
 * setup while the customer still owns a `_dmarc` TXT (a CNAME can't coexist with
 * a TXT, RFC 1034) — read live, never from the possibly-stale cached check.
 */
final readonly class ManagedDmarcCnameChecker
{
    public function __construct(
        private CnameResolver $cnameResolver,
        private ReportAddressProvider $reportAddressProvider,
        private Dns $dns,
    ) {
    }

    public function verify(string $customerDomain): CnameVerificationOutcome
    {
        $expected = $this->expectedTarget($customerDomain);
        if (null === $expected) {
            return CnameVerificationOutcome::Missing;
        }

        try {
            $target = $this->cnameResolver->resolveOrThrow(sprintf('_dmarc.%s', $customerDomain));
        } catch (\Throwable) {
            // Inconclusive — never treat a transient DNS error as "no CNAME".
            return CnameVerificationOutcome::LookupFailed;
        }

        if (null === $target) {
            return CnameVerificationOutcome::Missing;
        }

        return strtolower($target) === $expected
            ? CnameVerificationOutcome::Verified
            : CnameVerificationOutcome::PointsElsewhere;
    }

    /** The immutable CNAME target the customer must point `_dmarc.<domain>` at. */
    public function expectedTarget(string $customerDomain): ?string
    {
        $reportDomain = $this->reportAddressProvider->getReportDomain();
        if (null === $reportDomain) {
            return null;
        }

        return sprintf('%s._dmarc.%s', strtolower($customerDomain), strtolower($reportDomain));
    }

    /**
     * True when a `_dmarc` DMARC TXT record still exists at the customer's own
     * name AND the CNAME isn't yet pointing at us — i.e. the customer must
     * delete their old TXT before the CNAME can take over. Once the CNAME is
     * verified, a TXT can't coexist, so we report no conflict.
     */
    public function hasConflictingDmarcTxt(string $customerDomain): bool
    {
        if (CnameVerificationOutcome::Verified === $this->verify($customerDomain)) {
            return false;
        }

        try {
            $records = $this->dns->getRecords(sprintf('_dmarc.%s', $customerDomain), 'TXT');
        } catch (\Throwable) {
            return false;
        }

        foreach ($records as $record) {
            if (str_contains(strtolower((string) $record), 'v=dmarc1')) {
                return true;
            }
        }

        return false;
    }
}
