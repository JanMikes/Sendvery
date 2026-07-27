<?php

declare(strict_types=1);

namespace App\Value;

final readonly class ParsedDmarcRecord
{
    /**
     * @param list<PolicyOverrideReason> $policyOverrideReasons receiver-attested
     *                                                          reasons the published policy was not applied (RFC 7489 §6.7);
     *                                                          empty for the overwhelming majority of reports, which carry no
     *                                                          `<reason>` element at all
     */
    public function __construct(
        public string $sourceIp,
        public int $count,
        public Disposition $disposition,
        public AuthResult $dkimResult,
        public AuthResult $spfResult,
        public string $headerFrom,
        public ?string $dkimDomain = null,
        public ?string $dkimSelector = null,
        public ?string $spfDomain = null,
        public array $policyOverrideReasons = [],
    ) {
    }
}
