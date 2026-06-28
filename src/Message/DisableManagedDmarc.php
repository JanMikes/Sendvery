<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Switch a domain back from managed DMARC to self-TXT. Always allowed (even
 * when frozen) — taking control back must never be blocked. Teardown of the
 * hosted record is dangling-safe (deferred until the CNAME no longer points at
 * us).
 */
final readonly class DisableManagedDmarc
{
    public function __construct(
        public UuidInterface $domainId,
        public string $teamId,
        public ?UuidInterface $actorUserId,
    ) {
    }
}
