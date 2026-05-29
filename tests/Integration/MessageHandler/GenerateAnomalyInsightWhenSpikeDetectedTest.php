<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MutedAlert;
use App\Events\DmarcReportProcessed;
use App\Message\GenerateAnomalyInsight;
use App\MessageHandler\AlertOnFailureSpike;
use App\MessageHandler\GenerateAnomalyInsightWhenSpikeDetected;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Tests\TestSupport\AnthropicMockHttpClient;
use App\Value\AlertType;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class GenerateAnomalyInsightWhenSpikeDetectedTest extends IntegrationTestCase
{
    public function testAiTeamGetsItsAnomalyExplanationPrecomputed(): void
    {
        [$persona, $report] = $this->seedReport('anomaly-ai', SubscriptionPlan::PersonalAi);
        $handler = $this->getService(GenerateAnomalyInsightWhenSpikeDetected::class);
        $requestsBefore = $this->mock()->getRequestsCount();

        $handler(new GenerateAnomalyInsight($report->id, $persona->team->id, Uuid::uuid7()));

        self::assertSame(1, $this->countAnomalyInsights());
        self::assertSame($requestsBefore + 1, $this->mock()->getRequestsCount());
    }

    public function testNonAiTeamIsSkippedWithNoInsightAndNoApiCall(): void
    {
        [$persona, $report] = $this->seedReport('anomaly-free', SubscriptionPlan::Free);
        $handler = $this->getService(GenerateAnomalyInsightWhenSpikeDetected::class);
        $requestsBefore = $this->mock()->getRequestsCount();

        $handler(new GenerateAnomalyInsight($report->id, $persona->team->id, Uuid::uuid7()));

        self::assertSame(0, $this->countAnomalyInsights());
        self::assertSame($requestsBefore, $this->mock()->getRequestsCount());
    }

    public function testASpikeCreatesAnAlertAndDispatchesAnomalyInsightGeneration(): void
    {
        $persona = $this->seedDomainWithPriorCleanReport('spike');
        self::assertNotNull($persona->domain);

        $this->spikeHandler()(new DmarcReportProcessed(
            reportId: Uuid::uuid7(),
            domainId: $persona->domain->id,
            reporterOrg: 'google.com',
            totalRecords: 1,
            passCount: 10,
            failCount: 90,
        ));

        self::assertSame(1, $this->countSpikeAlerts());

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(GenerateAnomalyInsight::class, $sent[0]->getMessage());
    }

    public function testAReportWithNoMessagesIsIgnored(): void
    {
        $persona = $this->aiTeam('spike-zero', SubscriptionPlan::PersonalAi);
        self::assertNotNull($persona->domain);

        $this->spikeHandler()(new DmarcReportProcessed(Uuid::uuid7(), $persona->domain->id, 'google.com', 0, 0, 0));

        self::assertSame(0, $this->countSpikeAlerts());
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testWithoutHistoryThereIsNoBaselineToSpikeAgainst(): void
    {
        $persona = $this->aiTeam('spike-nohist', SubscriptionPlan::PersonalAi);
        self::assertNotNull($persona->domain);

        // No prior reports → average fail rate is null → no spike is computed.
        $this->spikeHandler()(new DmarcReportProcessed(Uuid::uuid7(), $persona->domain->id, 'google.com', 1, 10, 90));

        self::assertSame(0, $this->countSpikeAlerts());
    }

    public function testAMutedSpikeNeitherAlertsNorDispatches(): void
    {
        $persona = $this->seedDomainWithPriorCleanReport('spike-muted');
        $em = $this->getService(EntityManagerInterface::class);
        self::assertNotNull($persona->domain);
        $em->persist(new MutedAlert(Uuid::uuid7(), $persona->team, $persona->domain, AlertType::FailureSpike, new \DateTimeImmutable()));
        $em->flush();

        $this->spikeHandler()(new DmarcReportProcessed(
            reportId: Uuid::uuid7(),
            domainId: $persona->domain->id,
            reporterOrg: 'google.com',
            totalRecords: 1,
            passCount: 10,
            failCount: 90,
        ));

        self::assertSame(0, $this->countSpikeAlerts());
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function spikeHandler(): AlertOnFailureSpike
    {
        return $this->getService(AlertOnFailureSpike::class);
    }

    private function mock(): AnthropicMockHttpClient
    {
        return $this->getService(AnthropicMockHttpClient::class);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function countAnomalyInsights(): int
    {
        return (int) $this->getService(EntityManagerInterface::class)->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM ai_insight WHERE type = 'anomaly_explanation'")
            ->fetchOne();
    }

    private function countSpikeAlerts(): int
    {
        return (int) $this->getService(EntityManagerInterface::class)->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM alert WHERE type = 'failure_spike'")
            ->fetchOne();
    }

    private function aiTeam(string $prefix, SubscriptionPlan $plan): Persona
    {
        return TestFixtures::fromContainer(self::getContainer())
            ->persona()->emailPrefix($prefix)->teamName('AI '.$prefix)
            ->plan($plan->value)->withDomain($prefix.'.example')->build();
    }

    /**
     * @return array{Persona, DmarcReport}
     */
    private function seedReport(string $prefix, SubscriptionPlan $plan): array
    {
        $em = $this->getService(EntityManagerInterface::class);
        $persona = $this->aiTeam($prefix, $plan);
        self::assertNotNull($persona->domain);

        $report = $this->report($persona->domain, '2026-05-01 00:00:00');
        $em->persist($report);
        $em->persist(new DmarcRecord(Uuid::uuid7(), $report, '203.0.113.9', 40, Disposition::None, AuthResult::Fail, AuthResult::Fail, $persona->domain->domain));
        $em->flush();

        return [$persona, $report];
    }

    private function seedDomainWithPriorCleanReport(string $prefix): Persona
    {
        $em = $this->getService(EntityManagerInterface::class);
        $persona = $this->aiTeam($prefix, SubscriptionPlan::PersonalAi);
        self::assertNotNull($persona->domain);

        // A prior all-pass report makes the historical average fail-rate ~0%,
        // so the 90%-fail event reads as a spike.
        $prior = $this->report($persona->domain, '2026-04-20 00:00:00');
        $em->persist($prior);
        $em->persist(new DmarcRecord(Uuid::uuid7(), $prior, '9.9.9.9', 50, Disposition::None, AuthResult::Pass, AuthResult::Pass, $persona->domain->domain));
        $em->flush();

        return $persona;
    }

    private function report(\App\Entity\MonitoredDomain $domain, string $begin): DmarcReport
    {
        return new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable($begin),
            dateRangeEnd: new \DateTimeImmutable($begin),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable($begin),
        );
    }
}
