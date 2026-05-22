<?php

declare(strict_types=1);

namespace App\Value\Reports;

use App\Entity\MonitoredDomain;

/**
 * Tagged-union return value from DmarcReportRouter::route(). Use the static
 * factories to build instances; consumers branch on $kind.
 */
final readonly class RoutingDecision
{
    public function __construct(
        public RoutingKind $kind,
        public ?MonitoredDomain $domain,
        public ?string $domainName,
        public ?QuarantineReason $quarantineReason,
        public ?string $ignoredReason,
    ) {
    }

    public static function routed(MonitoredDomain $domain): self
    {
        return new self(
            kind: RoutingKind::Routed,
            domain: $domain,
            domainName: $domain->domain,
            quarantineReason: null,
            ignoredReason: null,
        );
    }

    public static function quarantined(string $domainName, QuarantineReason $reason): self
    {
        return new self(
            kind: RoutingKind::Quarantined,
            domain: null,
            domainName: $domainName,
            quarantineReason: $reason,
            ignoredReason: null,
        );
    }

    public static function ignored(string $reason): self
    {
        return new self(
            kind: RoutingKind::Ignored,
            domain: null,
            domainName: null,
            quarantineReason: null,
            ignoredReason: $reason,
        );
    }
}
