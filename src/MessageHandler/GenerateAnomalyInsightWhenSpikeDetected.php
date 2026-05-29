<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateAnomalyInsight;
use App\Repository\TeamRepository;
use App\Services\Ai\AiInsightsService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Pre-computes the AI explanation for a failure-spike alert (async, off the
 * report-ingestion path). The result is cached by the decorator under the
 * anomaly key, so the alert detail page can show it without a per-view spend.
 *
 * Anomaly explanations are plan-gated (not quota-gated): skip non-AI teams
 * cleanly here rather than letting the gate throw, so a non-AI team isn't a
 * Messenger "failure".
 */
#[AsMessageHandler]
final readonly class GenerateAnomalyInsightWhenSpikeDetected
{
    public function __construct(
        private AiInsightsService $aiService,
        private TeamRepository $teams,
    ) {
    }

    public function __invoke(GenerateAnomalyInsight $message): void
    {
        if (!$this->teams->get($message->teamId)->getSubscriptionPlan()->hasAi()) {
            return;
        }

        $this->aiService->explainAnomaly($message->reportId, $message->teamId);
    }
}
