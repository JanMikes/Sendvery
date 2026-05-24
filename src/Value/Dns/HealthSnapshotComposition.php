<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class HealthSnapshotComposition
{
    public function __construct(
        public int $spfScore,
        public int $dkimScore,
        public int $dmarcScore,
        public int $mxScore,
        public int $blacklistScore,
        public int $score,
        public string $grade,
    ) {
    }
}
