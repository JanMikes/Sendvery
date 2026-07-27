<?php

declare(strict_types=1);

namespace App\Value\Dns;

use App\Entity\DnsCheckResult;
use App\Results\Dns\RuaScenarioResult;
use App\Results\DomainSetupStatus;
use App\Value\DnsCheckType;

/**
 * Everything the three surfaces that render the guided DNS setup need, resolved
 * once by {@see \App\Services\Dns\GuidedDnsSetupProvider}.
 *
 * It carries the intermediate results (`setupStatus`, `latestByType`,
 * `ruaScenario`) alongside the finished `setup` so the page controllers can
 * reuse them for their other panels instead of re-running the same queries — and
 * so the status banner on the domain detail page is guaranteed to be derived
 * from the exact same per-protocol state as the setup surface underneath it.
 */
final readonly class GuidedDnsSetupView
{
    /**
     * @param array<value-of<DnsCheckType>, ?DnsCheckResult> $latestByType
     */
    public function __construct(
        public string $domainId,
        public string $domainName,
        public GuidedDnsSetup $setup,
        public DomainSetupStatus $setupStatus,
        public RuaScenarioResult $ruaScenario,
        public array $latestByType,
    ) {
    }
}
