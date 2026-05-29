<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Ai;

use App\Entity\Team;
use App\Exceptions\AiNotEnabledForPlan;
use App\Exceptions\AiQuotaExceeded;
use App\Exceptions\ReportNotAnalyzable;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\CachingAiInsightsService;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\PlanGatedAiInsightsService;
use App\Services\Stripe\PlanEnforcement;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PlanGatedAiInsightsServiceTest extends IntegrationTestCase
{
    public function testInterfaceResolvesToTheCachingDecoratorSoCacheHitsBypassTheGate(): void
    {
        $service = $this->getService(AiInsightsService::class);

        // Caching is the outermost decorator: a cache hit returns before the
        // plan/quota gate is entered, so re-views cost nothing and burn no quota.
        self::assertInstanceOf(CachingAiInsightsService::class, $service);
    }

    public function testExplainReportFailsWhenPlanHasNoAi(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::Personal);

        $this->expectException(AiNotEnabledForPlan::class);

        $gated->explainReport(Uuid::uuid7(), $team->id);
    }

    public function testWeeklyDigestFailsWhenPlanHasNoAi(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::Free);

        $this->expectException(AiNotEnabledForPlan::class);

        $gated->generateWeeklyDigest($team->id);
    }

    public function testExplainAnomalyFailsWhenPlanHasNoAi(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::Pro);

        $this->expectException(AiNotEnabledForPlan::class);

        $gated->explainAnomaly(Uuid::uuid7(), $team->id);
    }

    public function testExplainReportDoesNotChargeQuotaWhenTheReportCannotBeAnalyzed(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::PersonalAi);
        $teamId = $team->id->toString();

        self::assertSame(0, $enforcement->getOnDemandAiUsage($teamId));

        try {
            $gated->explainReport(Uuid::uuid7(), $team->id);
            self::fail('Expected ReportNotAnalyzable.');
        } catch (ReportNotAnalyzable) {
            // expected — the report doesn't exist for this team.
        }

        // Quota is charged only on a real generation, never when analysis fails.
        self::assertSame(0, $enforcement->getOnDemandAiUsage($teamId));
    }

    public function testExplainReportThrowsQuotaExceededAtLimit(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::PersonalAi);
        $teamId = $team->id->toString();

        // PersonalAi quota is 50/month. Burn the quota.
        for ($i = 0; $i < 50; ++$i) {
            $enforcement->incrementOnDemandAiUsage($teamId);
        }

        try {
            $gated->explainReport(Uuid::uuid7(), $team->id);
            self::fail('Expected AiQuotaExceeded to be thrown.');
        } catch (AiQuotaExceeded $exception) {
            self::assertSame(50, $exception->used);
            self::assertSame(50, $exception->limit);
        }
    }

    public function testWeeklyDigestSucceedsOnAiPlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::ProAi);

        $result = $gated->generateWeeklyDigest($team->id);

        self::assertNotSame('', $result->summaryMarkdown);
    }

    public function testExplainAnomalyPassesTheGateThenFailsAnalysisForAnUnknownReport(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::BusinessAi);

        // Plan gate passes (BusinessAi has AI); the inner orchestrator then can't
        // analyze a non-existent report and throws.
        $this->expectException(ReportNotAnalyzable::class);

        $gated->explainAnomaly(Uuid::uuid7(), $team->id);
    }

    public function testRemediationGuidancePassesThroughWithoutQuotaCheck(): void
    {
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $result = $gated->generateRemediationGuidance(
            Uuid::uuid7(),
            new DnsCheckFailure('SPF', 'example.com', 'too many lookups'),
        );

        self::assertNotSame('', $result->instructionsMarkdown);
    }

    public function testLabelSenderPassesThroughWithoutQuotaCheck(): void
    {
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $result = $gated->labelSender('192.0.2.1', 'example.com');

        self::assertNotSame('', $result->label);
    }

    private function createTeam(EntityManagerInterface $em, SubscriptionPlan $plan): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'AI Test Team',
            slug: 'ai-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan->value,
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }
}
