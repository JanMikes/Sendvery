<?php

declare(strict_types=1);

namespace App\Tests\TestSupport;

use App\Services\Ai\AiInsightsService;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\WeeklyDigestResult;
use Ramsey\Uuid\UuidInterface;

/**
 * A weekly-digest AI summary that is always the same sentence and never leaves
 * the process.
 *
 * Tests must not reach a real provider, and the digest tests additionally need
 * the narration to be byte-identical between runs — the golden-file test diffs
 * the rendered email, so a model that phrased itself differently each time
 * would make the golden meaningless.
 */
final class FixedWeeklyDigestAiInsightsService implements AiInsightsService
{
    public const string SUMMARY = 'Mail volume held steady and the two new senders both look like ordinary traffic.';
    public const string RECOMMENDATION = 'Publish a DMARC record so the missing-record alert can clear.';

    /**
     * How many times a digest narration was asked for. In production each of
     * these is a paid provider call, so "was it called at all?" is the thing
     * worth asserting — a preview that renders no AI section may still have
     * spent the money and thrown the answer away.
     */
    public int $weeklyDigestCalls = 0;

    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult
    {
        ++$this->weeklyDigestCalls;

        return new WeeklyDigestResult(
            summaryMarkdown: self::SUMMARY,
            keyMetrics: [],
            recommendations: [self::RECOMMENDATION],
        );
    }

    public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult
    {
        throw new \LogicException('Not exercised by the digest tests.');
    }

    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult
    {
        throw new \LogicException('Not exercised by the digest tests.');
    }

    public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult
    {
        throw new \LogicException('Not exercised by the digest tests.');
    }

    public function labelSender(string $ip, string $domain): SenderLabelResult
    {
        throw new \LogicException('Not exercised by the digest tests.');
    }
}
