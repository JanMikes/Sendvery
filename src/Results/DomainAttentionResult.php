<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\DomainAttentionReason;
use App\Value\DomainHealthFilter;

/**
 * One row of the `/app` "Needs your attention" list: which domain, why, and the
 * single deep link that takes the user to the surface where they fix it.
 *
 * Everything user-visible here is produced by
 * {@see \App\Services\DomainAttentionResolver} from the SAME
 * {@see DomainSetupStatus} the per-domain page renders — `headline`, each
 * reason's `detail`, and the CTA route/fragment are all passed through
 * unchanged. The dashboard therefore cannot name a problem, or send the user
 * somewhere, that the domain page disagrees with.
 */
final readonly class DomainAttentionResult
{
    /**
     * @param list<DomainAttentionReason> $reasons        ordered so the one the CTA jumps to comes first
     * @param array<string, string>       $ctaRouteParams includes `_fragment` when the fix surface has an anchor
     * @param int                         $hiddenReasons  reasons trimmed off the end for compactness; 0 when all are shown
     */
    public function __construct(
        public string $domainId,
        public string $domainName,
        public DomainHealthFilter $severity,
        public string $headline,
        public array $reasons,
        public string $ctaLabel,
        public string $ctaRoute,
        public array $ctaRouteParams,
        public ?float $passRate,
        public bool $awaitingFirstReport,
        public bool $checkInProgress,
        public int $hiddenReasons = 0,
    ) {
    }

    /**
     * daisyUI semantic token for the row glyph + left border. Mirrors the
     * DomainCard mapping exactly (Healthy → success, Attention → warning,
     * Unverified → error) so the same domain is the same colour on `/app`, on
     * `/app/domains` and on its own page.
     *
     * A domain whose first DNS check is still running is deliberately `info`,
     * not `error`: we have not yet looked, so there is nothing to accuse it of.
     */
    public function severityTone(): string
    {
        if ($this->checkInProgress) {
            return 'info';
        }

        return match ($this->severity) {
            DomainHealthFilter::Healthy => 'success',
            DomainHealthFilter::Attention => 'warning',
            DomainHealthFilter::Unverified => 'error',
        };
    }
}
