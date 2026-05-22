<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\WeeklyDigestResult;
use Ramsey\Uuid\UuidInterface;

/**
 * Five AI operations the product surfaces (DEC-055). Implementations:
 * `StubAiInsightsService` returns canned copy at launch (DEC-057);
 * `PlanGatedAiInsightsService` wraps any implementation with plan +
 * quota gating; the real `AnthropicAiInsightsService` slots in later
 * by swapping a single binding in `config/services.php`.
 */
interface AiInsightsService
{
    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult;

    public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult;

    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult;

    public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult;

    public function labelSender(string $ip, string $domain): SenderLabelResult;
}
