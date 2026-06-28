<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Services\ReportAddressProvider;
use App\Value\DmarcAlignment;
use App\Value\Dns\DmarcRecordSerializer;
use App\Value\Dns\ManagedDmarcPolicy;

/**
 * Composes the expected hosted-record content for a managed DMARC policy:
 * Sendvery-only `rua` (DEC-058a), relaxed alignment, and `fo=1`. This is the
 * single place both the publish event-handler and the sync-reconcile cron use
 * to compute expected content for drift comparison — so they never disagree.
 */
final readonly class ManagedDmarcPolicyComposer
{
    public function __construct(
        private ReportAddressProvider $reportAddressProvider,
        private DmarcRecordSerializer $serializer,
    ) {
    }

    public function compose(ManagedDmarcPolicy $policy): string
    {
        return $this->serializer->serialize(
            p: $policy->p,
            sp: $policy->sp,
            pct: $policy->pct,
            ruaAddresses: [$this->reportAddressProvider->get()],
            adkim: DmarcAlignment::Relaxed,
            aspf: DmarcAlignment::Relaxed,
            fo: '1',
        );
    }
}
