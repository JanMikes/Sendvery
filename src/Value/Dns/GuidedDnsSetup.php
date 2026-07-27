<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The whole guided DNS setup surface for one domain, tiered so a user can see
 * in one glance what to do next.
 *
 * This is the SINGLE canonical model behind the per-domain DNS setup UI. The
 * domain detail page and `/app/domains/{id}/health` both render it — the detail
 * page compactly (lead action expanded, the rest summarised, drilling down into
 * the health page) and the health page in full. They used to present
 * overlapping-but-different versions of the same facts, which is how one of
 * them ended up offering only the self-managed TXT path.
 *
 * `checkInProgress` is deliberately its own flag rather than a fourth tier: a
 * domain whose first check is still queued has NO verdict at all, and rendering
 * red "missing record" rows in that window is a wrong-information bug, not an
 * impatient truth.
 */
final readonly class GuidedDnsSetup
{
    /**
     * @param list<GuidedSetupStep>      $actionRequired  at most one — the single next thing to do
     * @param list<GuidedSetupStep>      $later           unfinished, but not what to touch first
     * @param list<GuidedSetupStep>      $done            finished; kept visible so progress is legible
     * @param list<ReportDeliveryOption> $deliveryOptions self-managed TXT + managed CNAME, always both
     */
    public function __construct(
        public array $actionRequired,
        public array $later,
        public array $done,
        public array $deliveryOptions,
        public bool $checkInProgress,
        public string $headline,
        public string $lede,
    ) {
    }

    /**
     * Whether anything at all is unfinished — drives whether the surface renders
     * its record region. A fully configured domain gets the confirmation line
     * only, never an empty "here are your records" shell.
     */
    public function hasOutstandingWork(): bool
    {
        return [] !== $this->actionRequired || [] !== $this->later;
    }
}
