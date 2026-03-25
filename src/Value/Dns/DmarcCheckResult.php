<?php

declare(strict_types=1);

namespace App\Value\Dns;

readonly final class DmarcCheckResult
{
    /**
     * @param array<string> $ruaAddresses
     * @param array<string> $rufAddresses
     * @param array<DnsIssue> $issues
     * @param array<string> $recommendations
     */
    public function __construct(
        public ?string $rawRecord,
        public ?string $policy,
        public ?string $subdomainPolicy,
        public array $ruaAddresses,
        public array $rufAddresses,
        public ?string $adkim,
        public ?string $aspf,
        public ?int $pct,
        public array $issues,
        public array $recommendations,
    ) {
    }

    public function hasRecord(): bool
    {
        return $this->rawRecord !== null;
    }

    public function isEnforcing(): bool
    {
        return $this->policy === 'quarantine' || $this->policy === 'reject';
    }

    public function isPassing(): bool
    {
        return $this->hasRecord() && $this->isEnforcing() && $this->ruaAddresses !== [];
    }
}
