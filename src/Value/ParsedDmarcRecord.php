<?php

declare(strict_types=1);

namespace App\Value;

final readonly class ParsedDmarcRecord
{
    public function __construct(
        public string $sourceIp,
        public int $count,
        public Disposition $disposition,
        public AuthResult $dkimResult,
        public AuthResult $spfResult,
        public string $headerFrom,
        public ?string $dkimDomain = null,
        public ?string $dkimSelector = null,
        public ?string $spfDomain = null,
    ) {
    }
}
