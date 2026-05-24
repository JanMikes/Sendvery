<?php

declare(strict_types=1);

namespace App\Value;

use App\Results\DomainOverviewResult;

enum DomainHealthFilter: string
{
    case Healthy = 'healthy';
    case Attention = 'attention';
    case Unverified = 'unverified';

    /**
     * Classification rule mirrored from the conditional WHERE/HAVING fragments
     * in GetDomainOverview::forTeams() — single source of truth for the
     * read-side severity glyph on DomainCard. A verified domain with zero
     * reports lands on Attention (pass_rate = 0 < 90), matching the query's
     * filter semantics; a brand-new unverified domain stays Unverified.
     */
    public static function fromOverview(DomainOverviewResult $result): self
    {
        if (null === $result->dmarcVerifiedAt) {
            return self::Unverified;
        }

        if ($result->passRate >= 90.0) {
            return self::Healthy;
        }

        return self::Attention;
    }
}
