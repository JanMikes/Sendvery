<?php

declare(strict_types=1);

namespace App\Value;

final readonly class WeeklyDigestBrokenDnsItem
{
    /** @param list<string> $issueMessages */
    public function __construct(
        public string $domainName,
        public string $checkType,
        public \DateTimeImmutable $checkedAt,
        public array $issueMessages,
    ) {
    }
}
