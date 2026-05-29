<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai;

use App\Services\Ai\AiModelPolicy;
use App\Value\AiInsightType;
use App\Value\AiModel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiModelPolicyTest extends TestCase
{
    #[Test]
    public function balancedDefaultsRouteNarrativesToSonnetAndSimpleTasksToHaiku(): void
    {
        $policy = new AiModelPolicy(
            explainReport: 'claude-sonnet-4-6',
            explainAnomaly: 'claude-sonnet-4-6',
            weeklyDigest: 'claude-sonnet-4-6',
            remediation: 'claude-haiku-4-5',
            senderLabel: 'claude-haiku-4-5',
        );

        self::assertSame(AiModel::Sonnet, $policy->forTask(AiInsightType::ReportExplanation));
        self::assertSame(AiModel::Sonnet, $policy->forTask(AiInsightType::AnomalyExplanation));
        self::assertSame(AiModel::Sonnet, $policy->forTask(AiInsightType::WeeklyDigest));
        self::assertSame(AiModel::Haiku, $policy->forTask(AiInsightType::Remediation));
        self::assertSame(AiModel::Haiku, $policy->forTask(AiInsightType::SenderLabel));
    }

    #[Test]
    public function anyTaskCanBeOverriddenToOpusViaEnv(): void
    {
        $policy = new AiModelPolicy(
            explainReport: 'claude-opus-4-8',
            explainAnomaly: 'claude-haiku-4-5',
            weeklyDigest: 'claude-haiku-4-5',
            remediation: 'claude-haiku-4-5',
            senderLabel: 'claude-haiku-4-5',
        );

        self::assertSame(AiModel::Opus, $policy->forTask(AiInsightType::ReportExplanation));
    }

    #[Test]
    public function aTypoInAModelEnvFailsFast(): void
    {
        $policy = new AiModelPolicy('not-a-model', 'claude-sonnet-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5', 'claude-haiku-4-5');

        $this->expectException(\ValueError::class);

        $policy->forTask(AiInsightType::ReportExplanation);
    }
}
