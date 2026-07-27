<?php

declare(strict_types=1);

namespace App\Results;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final readonly class BlacklistCheckCandidate
{
    public function __construct(
        public UuidInterface $domainId,
        public string $ipAddress,
    ) {
    }

    /**
     * @param array{domain_id: string, source_ip: string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: Uuid::fromString($row['domain_id']),
            ipAddress: $row['source_ip'],
        );
    }
}
