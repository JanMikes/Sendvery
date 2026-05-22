<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai;

use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\StubAiInsightsService;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class StubAiInsightsServiceTest extends TestCase
{
    private StubAiInsightsService $stub;

    protected function setUp(): void
    {
        $this->stub = new StubAiInsightsService();
    }

    public function testWeeklyDigestReturnsPlaceholderSummaryAndEmptyData(): void
    {
        $result = $this->stub->generateWeeklyDigest(Uuid::uuid7());

        self::assertStringContainsString('AI Insights is being prepared', $result->summaryMarkdown);
        self::assertSame([], $result->keyMetrics);
        self::assertSame([], $result->recommendations);
    }

    public function testExplainAnomalyReturnsPlaceholderWithInfoSeverity(): void
    {
        $result = $this->stub->explainAnomaly(Uuid::uuid7(), Uuid::uuid7());

        self::assertStringContainsString('AI Insights', $result->explanation);
        self::assertSame('info', $result->severity);
        self::assertNotSame('', $result->recommendedAction);
    }

    public function testExplainReportReturnsPlaceholderExplanation(): void
    {
        $result = $this->stub->explainReport(Uuid::uuid7(), Uuid::uuid7());

        self::assertStringContainsString('AI Insights', $result->explanation);
    }

    public function testRemediationGuidanceReturnsPlaceholderWithNoSuggestedRecords(): void
    {
        $result = $this->stub->generateRemediationGuidance(
            Uuid::uuid7(),
            new DnsCheckFailure('SPF', 'example.com', 'SPF lookup limit exceeded'),
        );

        self::assertStringContainsString('AI Insights', $result->instructionsMarkdown);
        self::assertSame([], $result->suggestedDnsRecords);
    }

    public function testLabelSenderReturnsZeroConfidenceUnlabeled(): void
    {
        $result = $this->stub->labelSender('192.0.2.1', 'example.com');

        self::assertSame('Unlabeled sender', $result->label);
        self::assertSame(0.0, $result->confidence);
    }
}
