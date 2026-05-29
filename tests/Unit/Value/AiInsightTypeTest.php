<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AiInsightType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiInsightTypeTest extends TestCase
{
    #[Test]
    public function caseValuesArePartOfTheDurableCacheKeySoTheyArePinned(): void
    {
        self::assertSame('report_explanation', AiInsightType::ReportExplanation->value);
        self::assertSame('weekly_digest', AiInsightType::WeeklyDigest->value);
        self::assertSame('anomaly_explanation', AiInsightType::AnomalyExplanation->value);
        self::assertSame('remediation', AiInsightType::Remediation->value);
        self::assertSame('sender_label', AiInsightType::SenderLabel->value);
        self::assertCount(5, AiInsightType::cases());
    }
}
