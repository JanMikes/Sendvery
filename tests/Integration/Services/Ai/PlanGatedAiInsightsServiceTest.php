<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Ai;

use App\Entity\Team;
use App\Exceptions\AiNotEnabledForPlan;
use App\Exceptions\AiQuotaExceeded;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\PlanGatedAiInsightsService;
use App\Services\Stripe\PlanEnforcement;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PlanGatedAiInsightsServiceTest extends IntegrationTestCase
{
    public function testInterfaceResolvesToGatedDecorator(): void
    {
        $service = $this->getService(AiInsightsService::class);

        self::assertInstanceOf(PlanGatedAiInsightsService::class, $service);
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

    public function testExplainReportSucceedsAndIncrementsQuotaOnAiPlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::PersonalAi);
        $teamId = $team->id->toString();

        self::assertSame(0, $enforcement->getOnDemandAiUsage($teamId));

        $result = $gated->explainReport(Uuid::uuid7(), $team->id);

        self::assertNotSame('', $result->explanation);
        self::assertSame(1, $enforcement->getOnDemandAiUsage($teamId));
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

    public function testExplainAnomalySucceedsOnAiPlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::BusinessAi);

        $result = $gated->explainAnomaly(Uuid::uuid7(), $team->id);

        self::assertNotSame('', $result->explanation);
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
