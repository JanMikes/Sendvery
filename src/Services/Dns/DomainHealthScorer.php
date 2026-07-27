<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DomainHealthScore;
use App\Value\Dns\EmailAuthCheckResult;
use App\Value\Dns\HealthCategory;

final readonly class DomainHealthScorer
{
    /**
     * Weights of the five categories. Blacklist only participates once it has
     * actually been measured — see {@see self::weightedAverage()}.
     */
    private const array WEIGHTS = [
        'DMARC' => 0.25,
        'SPF' => 0.20,
        'DKIM' => 0.20,
        'MX' => 0.15,
        'Blacklist' => 0.20,
    ];

    /**
     * @param int|null $blacklistScore null when no blacklist check has run for
     *                                 this domain. It used to default to 100,
     *                                 which handed every unchecked domain a
     *                                 perfect fifth of its grade — and since
     *                                 nothing dispatched CheckBlacklist, that
     *                                 meant every domain in the product.
     */
    public function score(EmailAuthCheckResult $result, ?int $blacklistScore = null): DomainHealthScore
    {
        $measured = [
            'SPF' => $this->scoreSpf($result),
            'DKIM' => $this->scoreDkim($result),
            'DMARC' => $this->scoreDmarc($result),
            'MX' => $this->scoreMx($result),
            'Blacklist' => $blacklistScore,
        ];

        $totalScore = $this->weightedAverage($measured);

        $grade = match (true) {
            $totalScore >= 90 => 'A',
            $totalScore >= 75 => 'B',
            $totalScore >= 55 => 'C',
            $totalScore >= 35 => 'D',
            default => 'F',
        };

        $categories = [];
        foreach ($measured as $name => $categoryScore) {
            $categories[] = new HealthCategory($name, $categoryScore, $this->statusFromScore($categoryScore));
        }

        return new DomainHealthScore(
            grade: $grade,
            score: $totalScore,
            categories: $categories,
        );
    }

    /**
     * Averages over the categories actually measured, renormalising their
     * weights to sum to 1.
     *
     * This is what makes an unmeasured category cost nothing and earn nothing.
     * A perfect domain scores 100 whether or not its blacklist was checked; an
     * imperfect one is graded on the evidence that exists rather than being
     * topped up by evidence that does not.
     *
     * @param array<string, int|null> $scores
     */
    private function weightedAverage(array $scores): int
    {
        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach ($scores as $name => $score) {
            if (null === $score) {
                continue;
            }

            $weighted += $score * self::WEIGHTS[$name];
            $totalWeight += self::WEIGHTS[$name];
        }

        // Every category unmeasured. Cannot happen today (the four DNS scores
        // are always computed) but returning 0 here would be a fabricated
        // verdict of the exact kind this method exists to prevent.
        if (0.0 === $totalWeight) {
            return 0;
        }

        return (int) round($weighted / $totalWeight);
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
            if ($dkimResult->keyExists && (null === $bestResult || ($dkimResult->keyBits ?? 0) > ($bestResult->keyBits ?? 0))) {
                $bestResult = $dkimResult;
            }
        }

        if (null === $bestResult) {
            return 0;
        }

        $score = 50;

        if (null !== $bestResult->keyBits && $bestResult->keyBits >= 2048) {
            $score += 35;
        } elseif (null !== $bestResult->keyBits && $bestResult->keyBits >= 1024) {
            $score += 15;
        }

        if ([] === $bestResult->issues) {
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

        if ('reject' === $dmarc->policy) {
            $score += 40;
        } elseif ('quarantine' === $dmarc->policy) {
            $score += 25;
        } elseif ('none' === $dmarc->policy) {
            $score += 5;
        }

        if ([] !== $dmarc->ruaAddresses) {
            $score += 15;
        }

        if (null === $dmarc->pct || 100 === $dmarc->pct) {
            $score += 10;
        }

        if ([] === $dmarc->issues) {
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

        // Score on what DNS proves (records resolve), not on port-25 probes —
        // a blocked outbound 25 on the checking host must not tank the grade.
        $anyResolvable = false;
        $allTls = true;
        foreach ($mx->records as $record) {
            if (null !== $record->ip) {
                $anyResolvable = true;
            }
            if ($record->reachable && true !== $record->tlsSupported) {
                $allTls = false;
            }
        }

        if ($anyResolvable) {
            $score += 30;
        }

        if ($allTls) {
            $score += 20;
        }

        if ([] === $mx->issues) {
            $score += 10;
        }

        return min(100, $score);
    }

    /**
     * The `unknown` status is the point of the null arm: it keeps "we have not
     * looked" out of `fail`, which is where an unmeasured category would land
     * if the default arm carried the error tone.
     */
    private function statusFromScore(?int $score): string
    {
        return match (true) {
            null === $score => 'unknown',
            $score >= 80 => 'pass',
            $score >= 50 => 'warning',
            default => 'fail',
        };
    }
}
