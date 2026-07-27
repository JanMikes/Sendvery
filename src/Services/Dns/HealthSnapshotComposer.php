<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Value\Dns\HealthSnapshotComposition;

final readonly class HealthSnapshotComposer
{
    /**
     * @param int|null $blacklistScore null when no blacklist check has run for
     *                                 this domain. It used to default to 100,
     *                                 so every nightly snapshot ever written
     *                                 banked a perfect fifth of its grade for a
     *                                 lookup that had not happened.
     */
    public function compose(
        ?DnsCheckResult $spf,
        ?DnsCheckResult $dkim,
        ?DnsCheckResult $dmarc,
        ?DnsCheckResult $mx,
        ?int $blacklistScore = null,
    ): HealthSnapshotComposition {
        $spfScore = $this->scoreFor($spf);
        $dkimScore = $this->scoreFor($dkim);
        $dmarcScore = $this->scoreFor($dmarc);
        $mxScore = $this->scoreFor($mx);

        // Renormalised over measured categories only, matching DomainHealthScorer.
        // Weights: DMARC 25%, SPF 20%, DKIM 20%, MX 15%, Blacklist 20%.
        $weighted = $dmarcScore * 0.25 + $spfScore * 0.20 + $dkimScore * 0.20 + $mxScore * 0.15;
        $totalWeight = 0.80;

        if (null !== $blacklistScore) {
            $weighted += $blacklistScore * 0.20;
            $totalWeight = 1.0;
        }

        $score = (int) round($weighted / $totalWeight);

        $grade = match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 55 => 'C',
            $score >= 35 => 'D',
            default => 'F',
        };

        return new HealthSnapshotComposition(
            spfScore: $spfScore,
            dkimScore: $dkimScore,
            dmarcScore: $dmarcScore,
            mxScore: $mxScore,
            blacklistScore: $blacklistScore,
            score: $score,
            grade: $grade,
        );
    }

    private function scoreFor(?DnsCheckResult $result): int
    {
        if (null === $result) {
            return 0;
        }

        return $result->isValid ? 100 : 0;
    }
}
