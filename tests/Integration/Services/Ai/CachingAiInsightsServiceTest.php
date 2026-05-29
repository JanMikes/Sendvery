<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Ai;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Services\Ai\AiInsightCacheKey;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Stripe\PlanEnforcement;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Tests\TestSupport\AnthropicMockHttpClient;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class CachingAiInsightsServiceTest extends IntegrationTestCase
{
    public function testASecondViewOfAReportCostsNothingAndBurnsNoQuota(): void
    {
        [$persona, $report] = $this->seedNonRoutineReport('cache');
        $ai = $this->getService(AiInsightsService::class);
        $enforcement = $this->getService(PlanEnforcement::class);
        $teamId = $persona->team->id->toString();
        $requestsBefore = $this->mock()->getRequestsCount();

        $first = $ai->explainReport($report->id, $persona->team->id);

        self::assertSame(1, $this->countInsights());
        self::assertSame(1, $enforcement->getOnDemandAiUsage($teamId));
        self::assertSame($requestsBefore + 1, $this->mock()->getRequestsCount());

        $second = $ai->explainReport($report->id, $persona->team->id);

        self::assertSame($first->explanation, $second->explanation);
        self::assertSame(1, $this->countInsights(), 'no second cache row');
        self::assertSame(1, $enforcement->getOnDemandAiUsage($teamId), 'quota must not be re-burned on a cache hit');
        self::assertSame($requestsBefore + 1, $this->mock()->getRequestsCount(), 'no second HTTP call on a cache hit');
    }

    public function testWeeklyDigestIsCachedWithinTheSameWeek(): void
    {
        // The per-ISO-week rollover is unit-tested deterministically in
        // AiInsightCacheKeyTest; here we prove same-week reuse: a second run
        // hits the cache (no new row, no new HTTP call).
        $persona = $this->aiTeamWithDomain('digest-cache');
        $ai = $this->getService(AiInsightsService::class);
        $requestsBefore = $this->mock()->getRequestsCount();

        $ai->generateWeeklyDigest($persona->team->id);
        self::assertSame(1, $this->countInsights());
        self::assertSame($requestsBefore + 1, $this->mock()->getRequestsCount());

        $ai->generateWeeklyDigest($persona->team->id);
        self::assertSame(1, $this->countInsights());
        self::assertSame($requestsBefore + 1, $this->mock()->getRequestsCount());
    }

    public function testAnomalyRemediationAndSenderLabelAreAlsoServedFromCacheOnRepeat(): void
    {
        [$persona, $report] = $this->seedNonRoutineReport('cache-misc');
        self::assertNotNull($persona->domain);
        $ai = $this->getService(AiInsightsService::class);

        $ai->explainAnomaly($report->id, $persona->team->id);
        $ai->explainAnomaly($report->id, $persona->team->id);
        self::assertSame(1, $this->countByType('anomaly_explanation'));

        $failure = new DnsCheckFailure('DMARC', $persona->domain->domain, 'missing DMARC record');
        $ai->generateRemediationGuidance($persona->domain->id, $failure);
        $ai->generateRemediationGuidance($persona->domain->id, $failure);
        self::assertSame(1, $this->countByType('remediation'));

        $ai->labelSender('198.51.100.7', 'unknown-host.example');
        $ai->labelSender('198.51.100.7', 'unknown-host.example');
        self::assertSame(1, $this->countByType('sender_label'));
    }

    public function testSenderLabelIsCachedAsAGlobalRowWithNoTeam(): void
    {
        $this->aiTeamWithDomain('label-cache'); // some AI team exists; labelSender itself is team-agnostic
        $ai = $this->getService(AiInsightsService::class);

        $ai->labelSender('198.51.100.7', 'unrecognized-host.example');

        $em = $this->getService(EntityManagerInterface::class);
        $teamId = $em->getConnection()->executeQuery(
            'SELECT team_id FROM ai_insight WHERE cache_key = :key',
            ['key' => AiInsightCacheKey::senderLabel('198.51.100.7', 'unrecognized-host.example')],
        )->fetchOne();

        self::assertNull($teamId);
    }

    private function countInsights(): int
    {
        return (int) $this->getService(EntityManagerInterface::class)
            ->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM ai_insight')
            ->fetchOne();
    }

    private function countByType(string $type): int
    {
        return (int) $this->getService(EntityManagerInterface::class)
            ->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM ai_insight WHERE type = :type', ['type' => $type])
            ->fetchOne();
    }

    private function mock(): AnthropicMockHttpClient
    {
        return $this->getService(AnthropicMockHttpClient::class);
    }

    private function aiTeamWithDomain(string $prefix): Persona
    {
        return TestFixtures::fromContainer(self::getContainer())
            ->persona()
            ->emailPrefix($prefix)
            ->teamName('AI '.$prefix)
            ->plan(SubscriptionPlan::PersonalAi->value)
            ->withDomain($prefix.'.example')
            ->build();
    }

    /**
     * @return array{Persona, DmarcReport}
     */
    private function seedNonRoutineReport(string $prefix): array
    {
        $em = $this->getService(EntityManagerInterface::class);
        $persona = $this->aiTeamWithDomain($prefix);
        self::assertNotNull($persona->domain);
        $domain = $persona->domain;

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('2026-05-01 00:00:00'),
            dateRangeEnd: new \DateTimeImmutable('2026-05-02 00:00:00'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '203.0.113.9',
            count: 40,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $domain->domain,
        ));
        $em->flush();

        return [$persona, $report];
    }
}
