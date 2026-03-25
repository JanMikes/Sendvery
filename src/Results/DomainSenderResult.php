<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DomainSenderResult
{
    public function __construct(
        public string $sourceIp,
        public ?string $resolvedOrg,
        public int $totalMessages,
        public int $passCount,
        public int $failCount,
    ) {
    }

    /** @param array{source_ip: string, resolved_org: string|null, total_messages: int|string, pass_count: int|string, fail_count: int|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            sourceIp: $row['source_ip'],
            resolvedOrg: $row['resolved_org'],
            totalMessages: (int) $row['total_messages'],
            passCount: (int) $row['pass_count'],
            failCount: (int) $row['fail_count'],
        );
    }
}
