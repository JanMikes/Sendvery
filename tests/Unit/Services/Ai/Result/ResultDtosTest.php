<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai\Result;

use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\KeyMetric;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\SuggestedDnsRecord;
use App\Services\Ai\Result\WeeklyDigestResult;
use PHPUnit\Framework\TestCase;

final class ResultDtosTest extends TestCase
{
    public function testKeyMetricExposesLabelAndValue(): void
    {
        $metric = new KeyMetric(label: 'Pass rate', value: '94%');

        self::assertSame('Pass rate', $metric->label);
        self::assertSame('94%', $metric->value);
    }

    public function testSuggestedDnsRecordExposesAllFields(): void
    {
        $record = new SuggestedDnsRecord(type: 'TXT', host: '_dmarc.example.com', value: 'v=DMARC1; p=reject');

        self::assertSame('TXT', $record->type);
        self::assertSame('_dmarc.example.com', $record->host);
        self::assertSame('v=DMARC1; p=reject', $record->value);
    }

    public function testWeeklyDigestResultStructure(): void
    {
        $result = new WeeklyDigestResult(
            summaryMarkdown: '## Summary',
            keyMetrics: [new KeyMetric('reports', '128')],
            recommendations: ['Tighten DMARC policy'],
        );

        self::assertSame('## Summary', $result->summaryMarkdown);
        self::assertCount(1, $result->keyMetrics);
        self::assertSame(['Tighten DMARC policy'], $result->recommendations);
    }

    public function testAnomalyExplanationResultStructure(): void
    {
        $result = new AnomalyExplanationResult(
            explanation: 'Failure rate spiked',
            severity: 'warning',
            recommendedAction: 'Investigate new sender 198.51.100.1',
        );

        self::assertSame('Failure rate spiked', $result->explanation);
        self::assertSame('warning', $result->severity);
        self::assertSame('Investigate new sender 198.51.100.1', $result->recommendedAction);
    }

    public function testOnDemandExplanationResultStructure(): void
    {
        $result = new OnDemandExplanationResult(explanation: 'All authenticated sources passed.');

        self::assertSame('All authenticated sources passed.', $result->explanation);
    }

    public function testRemediationResultStructure(): void
    {
        $record = new SuggestedDnsRecord('TXT', '_dmarc.example.com', 'v=DMARC1; p=quarantine');
        $result = new RemediationResult(
            instructionsMarkdown: '1. Update your DMARC record',
            suggestedDnsRecords: [$record],
        );

        self::assertSame('1. Update your DMARC record', $result->instructionsMarkdown);
        self::assertSame([$record], $result->suggestedDnsRecords);
    }

    public function testSenderLabelResultStructure(): void
    {
        $result = new SenderLabelResult(label: 'Mailgun', confidence: 0.92);

        self::assertSame('Mailgun', $result->label);
        self::assertSame(0.92, $result->confidence);
    }

    public function testDnsCheckFailureStructure(): void
    {
        $failure = new DnsCheckFailure('DKIM', 'example.com', 'No selector found');

        self::assertSame('DKIM', $failure->recordType);
        self::assertSame('example.com', $failure->domain);
        self::assertSame('No selector found', $failure->details);
    }
}
