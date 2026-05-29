<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Services\Stripe\PlanEnforcement;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class ExplainReportControllerTest extends WebTestCase
{
    public function testAiPlanUserCanExplainANonRoutineReportAndTheResultIsCachedForFree(): void
    {
        $client = self::createClient();
        [$persona, $report] = $this->seedReport('explain-ai', SubscriptionPlan::PersonalAi, routine: false);
        $client->loginUser($persona->user);

        $client->request('GET', '/app/reports/'.$report->id->toString());
        self::assertSelectorTextContains('body', 'Explain this report');

        $client->submitForm('Explain this report');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'test AI explanation');
        self::assertSelectorTextContains('body', 'of 50');
        self::assertSame(1, $this->countInsights());
        self::assertSame(1, $this->getService(PlanEnforcement::class)->getOnDemandAiUsage($persona->team->id->toString()));

        // Second view is served from cache: explanation shown, no button, still one row.
        $client->request('GET', '/app/reports/'.$report->id->toString());
        self::assertSelectorTextContains('body', 'test AI explanation');
        self::assertStringNotContainsString('Explain this report', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->countInsights());
    }

    public function testRoutineReportsShowAFreeTemplatedExplanationWithNoButton(): void
    {
        $client = self::createClient();
        [$persona, $report] = $this->seedReport('explain-routine', SubscriptionPlan::PersonalAi, routine: true);
        $client->loginUser($persona->user);

        $client->request('GET', '/app/reports/'.$report->id->toString());

        self::assertSelectorTextContains('body', 'routine report');
        self::assertStringNotContainsString('Explain this report', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->countInsights());
    }

    public function testNonAiPlanSeesNoAiExplanationSurface(): void
    {
        $client = self::createClient();
        [$persona, $report] = $this->seedReport('explain-free', SubscriptionPlan::Personal, routine: false);
        $client->loginUser($persona->user);

        $client->request('GET', '/app/reports/'.$report->id->toString());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('AI explanation', (string) $client->getResponse()->getContent());
    }

    public function testQuotaExhaustedShowsAFriendlyStateAndCreatesNoInsight(): void
    {
        $client = self::createClient();
        [$persona, $report] = $this->seedReport('explain-quota', SubscriptionPlan::PersonalAi, routine: false);
        $enforcement = $this->getService(PlanEnforcement::class);
        for ($i = 0; $i < 50; ++$i) {
            $enforcement->incrementOnDemandAiUsage($persona->team->id->toString());
        }
        $client->loginUser($persona->user);

        $client->request('GET', '/app/reports/'.$report->id->toString());
        $client->submitForm('Explain this report');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '50 of 50');
        self::assertSame(0, $this->countInsights());
    }

    public function testPostingFromATeamThatLostAiAccessShowsAnUpgradeUpsell(): void
    {
        $client = self::createClient();
        [$persona, $report] = $this->seedReport('explain-upsell', SubscriptionPlan::PersonalAi, routine: false);
        $client->loginUser($persona->user);

        // Grab a valid CSRF token while the team still has AI, then downgrade it.
        $crawler = $client->request('GET', '/app/reports/'.$report->id->toString());
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $em = $this->getService(EntityManagerInterface::class);
        $team = $em->find(\App\Entity\Team::class, $persona->team->id);
        assert($team instanceof \App\Entity\Team);
        $team->plan = SubscriptionPlan::Personal->value;
        $em->flush();

        $client->request('POST', '/app/reports/'.$report->id->toString().'/explain', ['_csrf_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Upgrade to add AI Insights');
        self::assertSame(0, $this->countInsights());
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $client = self::createClient();
        [$persona, $report] = $this->seedReport('explain-csrf', SubscriptionPlan::PersonalAi, routine: false);
        $client->loginUser($persona->user);

        $client->request('POST', '/app/reports/'.$report->id->toString().'/explain');

        self::assertResponseStatusCodeSame(403);
    }

    public function testExplainingAReportFromAnotherTeamIs404(): void
    {
        $client = self::createClient();
        [$mine, $myReport] = $this->seedReport('explain-mine', SubscriptionPlan::PersonalAi, routine: false);
        [, $otherReport] = $this->seedReport('explain-other', SubscriptionPlan::PersonalAi, routine: false);
        $client->loginUser($mine->user);

        $crawler = $client->request('GET', '/app/reports/'.$myReport->id->toString());
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/app/reports/'.$otherReport->id->toString().'/explain', ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    private function countInsights(): int
    {
        return (int) $this->getService(EntityManagerInterface::class)->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM ai_insight')
            ->fetchOne();
    }

    /**
     * @return array{Persona, DmarcReport}
     */
    private function seedReport(string $prefix, SubscriptionPlan $plan, bool $routine): array
    {
        $em = $this->getService(EntityManagerInterface::class);
        $persona = TestFixtures::fromContainer(self::getContainer())
            ->persona()->emailPrefix($prefix)->teamName('AI '.$prefix)
            ->plan($plan->value)->withDomain($prefix.'.example')->build();
        assert($persona->domain instanceof MonitoredDomain);
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

        if ($routine) {
            $em->persist(new KnownSender(Uuid::uuid7(), $domain, '9.9.9.9', new \DateTimeImmutable(), new \DateTimeImmutable(), 0, 0.0, isAuthorized: true));
            $em->persist(new DmarcRecord(Uuid::uuid7(), $report, '9.9.9.9', 100, Disposition::None, AuthResult::Pass, AuthResult::Pass, $domain->domain));
        } else {
            $em->persist(new DmarcRecord(Uuid::uuid7(), $report, '203.0.113.9', 40, Disposition::None, AuthResult::Fail, AuthResult::Fail, $domain->domain));
        }

        $em->flush();

        return [$persona, $report];
    }
}
