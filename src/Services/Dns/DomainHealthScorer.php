<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DomainHealthScore;
use App\Value\Dns\EmailAuthCheckResult;
use App\Value\Dns\HealthCategory;

readonly final class DomainHealthScorer
{
    public function score(EmailAuthCheckResult $result): DomainHealthScore
    {
        $spfScore = $this->scoreSpf($result);
        $dkimScore = $this->scoreDkim($result);
        $dmarcScore = $this->scoreDmarc($result);
        $mxScore = $this->scoreMx($result);

        // Weighted average: DMARC 30%, SPF 25%, DKIM 25%, MX 20%
        $totalScore = (int) round(
            $dmarcScore * 0.30
            + $spfScore * 0.25
            + $dkimScore * 0.25
            + $mxScore * 0.20,
        );

        $grade = match (true) {
            $totalScore >= 90 => 'A',
            $totalScore >= 75 => 'B',
            $totalScore >= 55 => 'C',
            $totalScore >= 35 => 'D',
            default => 'F',
        };

        return new DomainHealthScore(
            grade: $grade,
            score: $totalScore,
            categories: [
                new HealthCategory('SPF', $spfScore, $this->statusFromScore($spfScore)),
                new HealthCategory('DKIM', $dkimScore, $this->statusFromScore($dkimScore)),
                new HealthCategory('DMARC', $dmarcScore, $this->statusFromScore($dmarcScore)),
                new HealthCategory('MX', $mxScore, $this->statusFromScore($mxScore)),
            ],
        );
    }

    private function scoreSpf(EmailAuthCheckResult $result): int
    {
        $spf = $result->spf;

        if (!$spf->hasRecord()) {
            return 0;
        }

        if (!$spf->isValid) {
            return 15;
        }

        $score = 60;

        if ($spf->lookupCount <= 10) {
            $score += 25;
        } elseif ($spf->lookupCount <= 12) {
            $score += 10;
        }

        if ($spf->isPassing()) {
            $score += 15;
        }

        return min(100, $score);
    }

    private function scoreDkim(EmailAuthCheckResult $result): int
    {
        if (!$result->hasDkimKey()) {
            return 0;
        }

        $bestResult = null;
        foreach ($result->dkim as $dkimResult) {
            if ($dkimResult->keyExists && ($bestResult === null || ($dkimResult->keyBits ?? 0) > ($bestResult->keyBits ?? 0))) {
                $bestResult = $dkimResult;
            }
        }

        if ($bestResult === null) {
            return 0;
        }

        $score = 50;

        if ($bestResult->keyBits !== null && $bestResult->keyBits >= 2048) {
            $score += 35;
        } elseif ($bestResult->keyBits !== null && $bestResult->keyBits >= 1024) {
            $score += 15;
        }

        if ($bestResult->issues === []) {
            $score += 15;
        }

        return min(100, $score);
    }

    private function scoreDmarc(EmailAuthCheckResult $result): int
    {
        $dmarc = $result->dmarc;

        if (!$dmarc->hasRecord()) {
            return 0;
        }

        $score = 30;

        if ($dmarc->policy === 'reject') {
            $score += 40;
        } elseif ($dmarc->policy === 'quarantine') {
            $score += 25;
        } elseif ($dmarc->policy === 'none') {
            $score += 5;
        }

        if ($dmarc->ruaAddresses !== []) {
            $score += 15;
        }

        if ($dmarc->pct === null || $dmarc->pct === 100) {
            $score += 10;
        }

        if ($dmarc->issues === []) {
            $score += 5;
        }

        return min(100, $score);
    }

    private function scoreMx(EmailAuthCheckResult $result): int
    {
        $mx = $result->mx;

        if (!$mx->hasRecords()) {
            return 0;
        }

        $score = 40;

        $anyReachable = false;
        $allTls = true;
        foreach ($mx->records as $record) {
            if ($record->reachable) {
                $anyReachable = true;
            }
            if ($record->reachable && $record->tlsSupported !== true) {
                $allTls = false;
            }
        }

        if ($anyReachable) {
            $score += 30;
        }

        if ($allTls) {
            $score += 20;
        }

        if ($mx->issues === []) {
            $score += 10;
        }

        return min(100, $score);
    }

    private function statusFromScore(int $score): string
    {
        return match (true) {
            $score >= 80 => 'pass',
            $score >= 50 => 'warning',
            default => 'fail',
        };
    }
}
