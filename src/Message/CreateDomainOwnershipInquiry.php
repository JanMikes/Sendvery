<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class CreateDomainOwnershipInquiry
{
    public function __construct(
        public UuidInterface $inquiryId,
        public string $domain,
        public UuidInterface $inquiringUserId,
        public UuidInterface $inquiringTeamId,
    ) {
    }
}
