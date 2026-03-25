<?php

declare(strict_types=1);

namespace App\Value;

final readonly class BlacklistResult
{
    /**
     * @param array<string, array{listed: bool, reason: string|null}> $results
     */
    public function __construct(
        public string $ipAddress,
        public array $results,
        public bool $isListed,
    ) {
    }

    public function listedCount(): int
    {
        return count(array_filter($this->results, static fn (array $r) => $r['listed']));
    }

    public function totalChecked(): int
    {
        return count($this->results);
    }
}
