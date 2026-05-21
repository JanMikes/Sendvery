<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class SmtpProbeResult
{
    public function __construct(
        public bool $reachable,
        public ?bool $tlsSupported,
    ) {
    }

    public static function unreachable(): self
    {
        return new self(reachable: false, tlsSupported: null);
    }
}
