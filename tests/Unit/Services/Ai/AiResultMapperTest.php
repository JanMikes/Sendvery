<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai;

use App\Services\Ai\AiResultMapper;
use App\Services\Ai\Result\SuggestedDnsRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiResultMapperTest extends TestCase
{
    private AiResultMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AiResultMapper();
    }

    #[Test]
    public function itStripsHtmlAndLinksFromNarration(): void
    {
        $result = $this->mapper->toReportExplanation([
            'explanation' => 'Your domain is fine. <script>alert(1)</script> See https://evil.test/x for more.',
        ]);

        self::assertStringNotContainsString('<script>', $result->explanation);
        self::assertStringNotContainsString('https://evil.test', $result->explanation);
        self::assertStringContainsString('[link removed]', $result->explanation);
    }

    #[Test]
    public function emptyOrMissingNarrationFallsBackToSafeCopy(): void
    {
        self::assertNotSame('', $this->mapper->toReportExplanation([])->explanation);
        self::assertNotSame('', $this->mapper->toReportExplanation(['explanation' => '   '])->explanation);
    }

    #[Test]
    public function narrationIsLengthCapped(): void
    {
        $result = $this->mapper->toReportExplanation(['explanation' => str_repeat('x', 5000)]);

        self::assertLessThanOrEqual(2000, mb_strlen($result->explanation));
    }

    #[Test]
    public function anomalySeverityIsCoercedToTheAllowedSet(): void
    {
        self::assertSame('warning', $this->mapper->toAnomaly(['severity' => 'warning', 'explanation' => 'x', 'recommended_action' => 'y'])->severity);
        self::assertSame('info', $this->mapper->toAnomaly(['severity' => 'apocalyptic', 'explanation' => 'x', 'recommended_action' => 'y'])->severity);
        self::assertSame('info', $this->mapper->toAnomaly(['explanation' => 'x', 'recommended_action' => 'y'])->severity);
    }

    #[Test]
    public function senderConfidenceIsClampedToTheUnitInterval(): void
    {
        self::assertSame(1.0, $this->mapper->toSenderLabel(['label' => 'Google', 'confidence' => 5.0])->confidence);
        self::assertSame(0.0, $this->mapper->toSenderLabel(['label' => 'Google', 'confidence' => -2.0])->confidence);
        self::assertSame(0.0, $this->mapper->toSenderLabel(['label' => 'Google', 'confidence' => 'oops'])->confidence);
        self::assertSame('Unknown sender', $this->mapper->toSenderLabel([])->label);
    }

    #[Test]
    public function weeklyDigestDropsMalformedMetricsAndEmptyRecommendations(): void
    {
        $result = $this->mapper->toWeeklyDigest([
            'summary' => 'A calm week.',
            'key_metrics' => [
                ['label' => 'Messages', 'value' => '1,000'],
                ['label' => 'Broken'], // missing value → dropped
                'not-an-object',        // dropped
            ],
            'recommendations' => ['Move to quarantine.', '   ', ''],
        ]);

        self::assertSame('A calm week.', $result->summaryMarkdown);
        self::assertCount(1, $result->keyMetrics);
        self::assertSame('Messages', $result->keyMetrics[0]->label);
        self::assertSame(['Move to quarantine.'], $result->recommendations);
    }

    #[Test]
    public function remediationRecordsComeFromPhpNotTheModel(): void
    {
        $phpRecords = [new SuggestedDnsRecord('TXT', '_dmarc.acme.example', 'v=DMARC1; p=none;')];

        $result = $this->mapper->toRemediation(
            ['instructions' => 'Add the record below.', 'suggested_dns_records' => [['type' => 'TXT', 'host' => 'evil', 'value' => 'malicious']]],
            $phpRecords,
        );

        // The model's injected "suggested_dns_records" is ignored entirely.
        self::assertSame($phpRecords, $result->suggestedDnsRecords);
        self::assertSame('Add the record below.', $result->instructionsMarkdown);
    }
}
