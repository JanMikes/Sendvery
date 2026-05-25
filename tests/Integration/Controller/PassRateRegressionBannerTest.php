<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * End-to-end coverage for TASK-093's pass-rate regression banner on
 * `/app/reports`. Each branch persists enough report shape to cross (or fail
 * to cross) the eligibility thresholds, then asserts the banner surfaces (or
 * doesn't) with the right severity.
 *
 * The healthy-state assertion is the silent regression test: it confirms
 * the page renders nothing in the Stable state so a future refactor can't
 * silently introduce noise on healthy teams.
 */
final class PassRateRegressionBannerTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, user: User, team: Team, domain: MonitoredDomain}
     */
    private function bootClient(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'regression-banner-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Regression Banner Team',
            slug: 'regression-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'regression-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->flush();
        $client->loginUser($user);

        return ['client' => $client, 'em' => $em, 'user' => $user, 'team' => $team, 'domain' => $domain];
    }

    /**
     * Persist a single DmarcReport with one matching DmarcRecord (pass or
     * fail) so the team-wide aggregate query has predictable input. The
     * controller's `ClockInterface` is the real PSR clock here, so `daysAgo`
     * is interpreted against wall-clock — fine because the windows are >> the
     * test's run time.
     */
    private function persistReport(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        int $daysAgo,
        string $sourceIp,
        int $count,
        bool $isPass,
    ): void {
        $when = new \DateTimeImmutable("-{$daysAgo} days");

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc@google.com',
            externalReportId: 'r-'.Uuid::uuid7()->toString(),
            dateRangeBegin: $when->modify('-1 day'),
            dateRangeEnd: $when,
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: $when,
        );
        $em->persist($report);

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: Disposition::None,
            dkimResult: $isPass ? AuthResult::Pass : AuthResult::Fail,
            spfResult: $isPass ? AuthResult::Pass : AuthResult::Fail,
            headerFrom: $domain->domain,
        ));
        $em->flush();
    }

    #[Test]
    public function regressionBannerRendersWhenRecentPassRateDroppedTenPercentagePoints(): void
    {
        $boot = $this->bootClient();
        // Older history (8-29 days ago): 88 reports (22 days × 4 calls/day).
        // Together with the recent window below this puts the 30-day baseline
        // well above the TASK-109 MIN_SAMPLE_SIZE = 50 floor AND keeps the
        // 30-day baseline pass rate above the 70% REGRESSION_MIN_BASELINE
        // even after the recent heavy-failure traffic is folded in (the
        // baseline query is a true rolling 30d, not "30d excluding 7d").
        for ($d = 8; $d <= 29; ++$d) {
            // Two records per day to push enough passing volume in.
            $this->persistReport($boot['em'], $boot['domain'], $d, '192.0.2.1', 950, true);
            $this->persistReport($boot['em'], $boot['domain'], $d, '192.0.2.1', 50, false);
            $this->persistReport($boot['em'], $boot['domain'], $d, '192.0.2.2', 950, true);
            $this->persistReport($boot['em'], $boot['domain'], $d, '192.0.2.2', 50, false);
        }
        // Last 6 days: 72 reports (12 per day × 6 days = 3 inner copies of
        // the 4-row scheme). The 7-day window ends at the current second
        // when the controller queries; keeping the latest fixture day at
        // d=0 and the oldest at d=5 gives a full second of slack against
        // wall-clock drift (test runtime typically << 1s) before the floor
        // query trims a row. Volume is intentionally above the TASK-109
        // 50-report floor on the current 7-day window.
        for ($d = 0; $d <= 5; ++$d) {
            for ($copy = 0; $copy < 3; ++$copy) {
                $this->persistReport($boot['em'], $boot['domain'], $d, '198.51.100.50', 20, true);
                $this->persistReport($boot['em'], $boot['domain'], $d, '198.51.100.50', 80, false);
                $this->persistReport($boot['em'], $boot['domain'], $d, '203.0.113.50', 1, true);
                $this->persistReport($boot['em'], $boot['domain'], $d, '203.0.113.51', 1, false);
            }
        }

        $crawler = $boot['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-testid="pass-rate-regression-banner"]');
        self::assertGreaterThan(0, $banner->count(), 'Regression banner must render.');
        self::assertSame('regression', (string) $banner->attr('data-severity'));
        self::assertStringContainsString(
            'Pass rate dropped',
            (string) $crawler->filter('[data-testid="pass-rate-regression-banner-headline"]')->text(),
        );
    }

    #[Test]
    public function noBannerForBrandNewTeamWithNoReports(): void
    {
        $boot = $this->bootClient();

        $crawler = $boot['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="pass-rate-regression-banner"]'));
    }

    #[Test]
    public function noBannerForHealthyTeamWithSteadyPassRate(): void
    {
        $boot = $this->bootClient();
        // 4 reports per day for the last 30 days = 120 total, 28 in the
        // last 7 days... bump to 8/day so both windows clear the
        // TASK-109 MIN_SAMPLE_SIZE=50 floor and the test exercises the
        // healthy → Stable branch rather than the small-sample suppression.
        for ($d = 0; $d <= 29; ++$d) {
            for ($i = 0; $i < 8; ++$i) {
                $this->persistReport($boot['em'], $boot['domain'], $d, sprintf('192.0.2.%d', ($i % 250) + 1), 100, true);
            }
        }

        $crawler = $boot['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        // Healthy → Stable severity → no banner rendered.
        self::assertCount(0, $crawler->filter('[data-testid="pass-rate-regression-banner"]'));
    }
}
