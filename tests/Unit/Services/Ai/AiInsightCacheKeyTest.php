<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai;

use App\Services\Ai\AiInsightCacheKey;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiInsightCacheKeyTest extends TestCase
{
    #[Test]
    public function reportAndAnomalyKeysAreStableForAnImmutableReport(): void
    {
        self::assertSame('report_explanation:r-1', AiInsightCacheKey::reportExplanation('r-1'));
        self::assertSame('anomaly_explanation:r-1', AiInsightCacheKey::anomalyExplanation('r-1'));
    }

    #[Test]
    public function digestKeyRollsPerIsoWeek(): void
    {
        $midWeek = new \DateTimeImmutable('2026-05-27 10:00:00'); // ISO week 22 of 2026
        $sameWeek = new \DateTimeImmutable('2026-05-29 23:00:00');
        $nextWeek = new \DateTimeImmutable('2026-06-02 09:00:00'); // ISO week 23

        self::assertSame('weekly_digest:team-1:2026-W22', AiInsightCacheKey::weeklyDigest('team-1', $midWeek));
        self::assertSame(
            AiInsightCacheKey::weeklyDigest('team-1', $midWeek),
            AiInsightCacheKey::weeklyDigest('team-1', $sameWeek),
        );
        self::assertNotSame(
            AiInsightCacheKey::weeklyDigest('team-1', $midWeek),
            AiInsightCacheKey::weeklyDigest('team-1', $nextWeek),
        );
    }

    #[Test]
    public function remediationKeyIsStablePerDomainAndRecordType(): void
    {
        self::assertSame('remediation:d-1:SPF', AiInsightCacheKey::remediation('d-1', 'spf'));
        // Case-insensitive on the record type so detector/controller agree.
        self::assertSame(
            AiInsightCacheKey::remediation('d-1', 'SPF'),
            AiInsightCacheKey::remediation('d-1', 'spf'),
        );
    }

    #[Test]
    public function senderLabelKeyIsCaseInsensitiveOnTheDomain(): void
    {
        self::assertSame(
            AiInsightCacheKey::senderLabel('192.0.2.1', 'Acme.Example'),
            AiInsightCacheKey::senderLabel('192.0.2.1', 'acme.example'),
        );
    }
}
