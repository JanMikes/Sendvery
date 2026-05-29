<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\DnsCheckType;
use Ramsey\Uuid\UuidInterface;

/**
 * Dispatched when a domain's DNS check comes back invalid, so the AI remediation
 * guidance is generated off the request path (async). The domain-health page then
 * only reads the cached result — it never blocks on a live Anthropic call.
 */
final readonly class GenerateRemediationInsight
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public DnsCheckType $recordType,
        public UuidInterface $dnsCheckResultId,
    ) {
    }
}
