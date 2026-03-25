<?php

declare(strict_types=1);

namespace App\Value\Dns;

readonly final class SpfCheckResult
{
    /**
     * @param array<DnsIssue> $issues
     * @param array<string> $recommendations
     * @param array<string> $includes
     */
    public function __construct(
        public ?string $rawRecord,
        public bool $isValid,
        public int $mechanismCount,
        public int $lookupCount,
        public array $includes,
        public array $issues,
        public array $recommendations,
    ) {
    }

    public function hasRecord(): bool
    {
        return $this->rawRecord !== null;
    }

    public function isPassing(): bool
    {
        return $this->isValid && $this->lookupCount <= 10 && $this->noCriticalIssues();
    }

    private function noCriticalIssues(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === IssueSeverity::Critical) {
                return false;
            }
        }

        return true;
    }
}
