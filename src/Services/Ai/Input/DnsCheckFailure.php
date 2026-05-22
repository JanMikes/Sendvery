<?php

declare(strict_types=1);

namespace App\Services\Ai\Input;

/**
 * Minimal context for AI remediation guidance. The real implementation
 * will need the failing record type (SPF/DKIM/DMARC/MX), the domain, and
 * a human-readable summary of what went wrong; the stub returns canned
 * copy and doesn't introspect any of it.
 */
final readonly class DnsCheckFailure
{
    public function __construct(
        public string $recordType,
        public string $domain,
        public string $details,
    ) {
    }
}
