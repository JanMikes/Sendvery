<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Asks the worker to release any quarantined DMARC reports waiting for the
 * given (now-verified) domain, dispatching them through the normal
 * ProcessDmarcReport pipeline so they land in the dashboard.
 *
 * Dispatched by ReleaseQuarantinedReportsWhenDomainVerified the first time
 * a team verifies a domain.
 */
final readonly class ReleaseQuarantinedReportsForDomain
{
    public function __construct(
        public UuidInterface $domainId,
        public string $domainName,
    ) {
    }
}
