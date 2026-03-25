<?php

declare(strict_types=1);

namespace App\Events;

use App\Value\DnsCheckType;
use Ramsey\Uuid\UuidInterface;

final readonly class DnsCheckCompleted
{
    public function __construct(
        public UuidInterface $dnsCheckResultId,
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public DnsCheckType $type,
        public bool $hasChanged,
        public bool $isValid,
        public ?string $rawRecord,
        public ?string $previousRawRecord,
    ) {
    }
}
