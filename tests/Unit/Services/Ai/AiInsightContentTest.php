<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai;

use App\Services\Ai\AiInsightContent;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\KeyMetric;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\SuggestedDnsRecord;
use App\Services\Ai\Result\WeeklyDigestResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiInsightContentTest extends TestCase
{
    #[Test]
    public function reportExplanationRoundTrips(): void
    {
        $original = new OnDemandExplanationResult('All good.');
        $decoded = AiInsightContent::reportExplanation(AiInsightContent::encode($original));

        self::assertSame('All good.', $decoded->explanation);
    }

    #[Test]
    public function anomalyRoundTrips(): void
    {
        $original = new AnomalyExplanationResult('Spike.', 'warning', 'Investigate.');
        $decoded = AiInsightContent::anomaly(AiInsightContent::encode($original));

        self::assertSame('Spike.', $decoded->explanation);
        self::assertSame('warning', $decoded->severity);
        self::assertSame('Investigate.', $decoded->recommendedAction);
    }

    #[Test]
    public function weeklyDigestRoundTripsIncludingNestedMetrics(): void
    {
        $original = new WeeklyDigestResult(
            summaryMarkdown: 'A calm week.',
            keyMetrics: [new KeyMetric('Messages', '1,000'), new KeyMetric('Pass rate', '99%')],
            recommendations: ['Move to quarantine.'],
        );

        $decoded = AiInsightContent::weeklyDigest(AiInsightContent::encode($original));

        self::assertSame('A calm week.', $decoded->summaryMarkdown);
        self::assertCount(2, $decoded->keyMetrics);
        self::assertSame('Messages', $decoded->keyMetrics[0]->label);
        self::assertSame('1,000', $decoded->keyMetrics[0]->value);
        self::assertSame(['Move to quarantine.'], $decoded->recommendations);
    }

    #[Test]
    public function remediationRoundTripsIncludingDnsRecords(): void
    {
        $original = new RemediationResult(
            instructionsMarkdown: 'Publish this.',
            suggestedDnsRecords: [new SuggestedDnsRecord('TXT', '_dmarc.acme.example', 'v=DMARC1; p=none;')],
        );

        $decoded = AiInsightContent::remediation(AiInsightContent::encode($original));

        self::assertSame('Publish this.', $decoded->instructionsMarkdown);
        self::assertCount(1, $decoded->suggestedDnsRecords);
        self::assertSame('_dmarc.acme.example', $decoded->suggestedDnsRecords[0]->host);
        self::assertSame('v=DMARC1; p=none;', $decoded->suggestedDnsRecords[0]->value);
    }

    #[Test]
    public function senderLabelRoundTrips(): void
    {
        $decoded = AiInsightContent::senderLabel(AiInsightContent::encode(new SenderLabelResult('SendGrid', 0.92)));

        self::assertSame('SendGrid', $decoded->label);
        self::assertSame(0.92, $decoded->confidence);
    }

    #[Test]
    public function encodingAnUnknownResultTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AiInsightContent::encode(new \stdClass());
    }
}
