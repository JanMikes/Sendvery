<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class EmailAuthCheckResult
{
    /**
     * @param array<DkimCheckResult> $dkim
     */
    public function __construct(
        public string $domain,
        public SpfCheckResult $spf,
        public array $dkim,
        public DmarcCheckResult $dmarc,
        public MxCheckResult $mx,
    ) {
    }

    public function hasDkimKey(): bool
    {
        foreach ($this->dkim as $result) {
            if ($result->keyExists) {
                return true;
            }
        }

        return false;
    }
}
