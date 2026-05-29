<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Value\AiInsightType;
use App\Value\AiModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maps each AI task to the model that runs it. Balanced defaults (see .env):
 * Sonnet for the customer-facing narratives (report/anomaly/digest), Haiku for
 * the high-volume/simple tasks (remediation narration, sender labels). Every
 * task is overridable via env so ops can retune the cost/quality tradeoff — or
 * flip a task to Opus — without a code change.
 *
 * `AiModel::from()` throws on an unknown id, so a typo in an env value fails
 * fast at container build rather than mid-request.
 */
final readonly class AiModelPolicy
{
    public function __construct(
        #[Autowire(env: 'ANTHROPIC_MODEL_EXPLAIN_REPORT')]
        private string $explainReport,
        #[Autowire(env: 'ANTHROPIC_MODEL_EXPLAIN_ANOMALY')]
        private string $explainAnomaly,
        #[Autowire(env: 'ANTHROPIC_MODEL_WEEKLY_DIGEST')]
        private string $weeklyDigest,
        #[Autowire(env: 'ANTHROPIC_MODEL_REMEDIATION')]
        private string $remediation,
        #[Autowire(env: 'ANTHROPIC_MODEL_SENDER_LABEL')]
        private string $senderLabel,
    ) {
    }

    public function forTask(AiInsightType $task): AiModel
    {
        return match ($task) {
            AiInsightType::ReportExplanation => AiModel::from($this->explainReport),
            AiInsightType::AnomalyExplanation => AiModel::from($this->explainAnomaly),
            AiInsightType::WeeklyDigest => AiModel::from($this->weeklyDigest),
            AiInsightType::Remediation => AiModel::from($this->remediation),
            AiInsightType::SenderLabel => AiModel::from($this->senderLabel),
        };
    }
}
