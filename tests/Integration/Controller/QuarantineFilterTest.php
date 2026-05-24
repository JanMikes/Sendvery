<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Integration coverage for TASK-036: the new ?reason= filter chip row on
 * /app/quarantine and the clickable reason badges in each table row.
 *
 * The seed plants one quarantine row per reason (UnknownDomain,
 * UnverifiedDomain, PlanOverage), all attached to a monitored_domain the
 * team owns — so visibility is guaranteed via the domain-ownership rule and
 * the test focuses on filter behaviour rather than the union-of-rules
 * scoping (which {@see GetQuarantineListTest} already covers).
 */
final class QuarantineFilterTest extends WebTestCase
{
    /**
     * @return array{
     *     client: KernelBrowser,
     *     em: EntityManagerInterface,
     *     team: Team,
     *     unknownDomain: string,
     *     unverifiedDomain: string,
     *     planOverageDomain: string,
     * }
     */
    private function bootClientWithThreeReasons(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'filter-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Filter Test',
            slug: 'filter-'.Uuid::uuid7()->toString(),
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

        $unknownDomainName = 'unknown-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $unverifiedDomainName = 'unverified-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $planOverageDomainName = 'overage-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';

        // For each of the three reasons, also persist a monitored_domain so
        // the team-scoping rule lets us see the row via domain ownership.
        foreach ([$unknownDomainName, $unverifiedDomainName, $planOverageDomainName] as $name) {
            $domain = new MonitoredDomain(
                id: Uuid::uuid7(),
                team: $team,
                domain: $name,
                createdAt: new \DateTimeImmutable(),
            );
            $domain->popEvents();
            $em->persist($domain);
        }

        $this->persistQuarantine($em, $unknownDomainName, QuarantineReason::UnknownDomain);
        $this->persistQuarantine($em, $unverifiedDomainName, QuarantineReason::UnverifiedDomain);
        $this->persistQuarantine($em, $planOverageDomainName, QuarantineReason::PlanOverage);

        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'em' => $em,
            'team' => $team,
            'unknownDomain' => $unknownDomainName,
            'unverifiedDomain' => $unverifiedDomainName,
            'planOverageDomain' => $planOverageDomainName,
        ];
    }

    private function persistQuarantine(
        EntityManagerInterface $em,
        string $domainName,
        QuarantineReason $reason,
    ): QuarantinedDmarcReport {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Filter test',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1024,
            rawEml: 'x',
        );
        $em->persist($envelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: $reason,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);

        return $quarantine;
    }

    #[Test]
    public function quarantineListWithoutFilterShowsAllEntries(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['unknownDomain'], $body);
        self::assertStringContainsString($data['unverifiedDomain'], $body);
        self::assertStringContainsString($data['planOverageDomain'], $body);
    }

    #[Test]
    public function quarantineListFiltersByUnknownDomain(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=unknown_domain');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['unknownDomain'], $body);
        self::assertStringNotContainsString($data['unverifiedDomain'], $body);
        self::assertStringNotContainsString($data['planOverageDomain'], $body);
    }

    #[Test]
    public function quarantineListFiltersByUnverifiedDomain(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=unverified_domain');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['unverifiedDomain'], $body);
        self::assertStringNotContainsString($data['unknownDomain'], $body);
        self::assertStringNotContainsString($data['planOverageDomain'], $body);
    }

    #[Test]
    public function quarantineListFiltersByPlanOverage(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=plan_overage');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['planOverageDomain'], $body);
        self::assertStringNotContainsString($data['unknownDomain'], $body);
        self::assertStringNotContainsString($data['unverifiedDomain'], $body);
    }

    #[Test]
    public function quarantineListEmptyWithFilterShowsClearFilterLink(): void
    {
        // Persona has one UnverifiedDomain row → ?reason=plan_overage yields
        // zero results, but totalCount > 0 so we hit the "no matches" branch
        // with its Clear filter CTA rather than the empty-onboarding state.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'empty-filter-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Empty Filter',
            slug: 'empty-filter-'.Uuid::uuid7()->toString(),
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

        $domainName = 'only-unverified-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $domainName,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $this->persistQuarantine($em, $domainName, QuarantineReason::UnverifiedDomain);

        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/app/quarantine?reason=plan_overage');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('No reports match the current filter', $body);
        self::assertStringContainsString('Clear filter', $body);
        self::assertStringContainsString('href="/app/quarantine"', $body);
    }

    #[Test]
    public function quarantineListEmptyWithoutFilterShowsExistingEmptyState(): void
    {
        // No quarantine rows at all → totalCount == 0 → keep the celebratory
        // empty state. We still need a placeholder monitored_domain so the
        // onboarding-redirect listener doesn't bounce us off /app/quarantine.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'truly-empty-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Truly Empty',
            slug: 'truly-empty-'.Uuid::uuid7()->toString(),
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
            domain: 'empty-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('No reports in quarantine', $body);
        self::assertStringContainsString('Every report we received has been parsed', $body);
        // No "Clear filter" CTA on the truly-empty branch — that copy belongs
        // to the filter-masked branch.
        self::assertStringNotContainsString('No reports match the current filter', $body);
    }

    #[Test]
    public function invalidReasonQueryParamFallsBackToNoFilter(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=garbage');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // tryFrom() returns null on garbage input — same as no filter at all.
        self::assertStringContainsString($data['unknownDomain'], $body);
        self::assertStringContainsString($data['unverifiedDomain'], $body);
        self::assertStringContainsString($data['planOverageDomain'], $body);
    }

    #[Test]
    public function filterChipsRenderedOnQuarantinePage(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('href="/app/quarantine"', $body);
        self::assertStringContainsString('href="/app/quarantine?reason=unknown_domain"', $body);
        self::assertStringContainsString('href="/app/quarantine?reason=unverified_domain"', $body);
        self::assertStringContainsString('href="/app/quarantine?reason=plan_overage"', $body);

        // Per-reason chip count labels (one row each → (1)).
        self::assertStringContainsString('All (3)', $body);
        self::assertStringContainsString('Unknown domain (1)', $body);
        self::assertStringContainsString('Unverified domain (1)', $body);
        self::assertStringContainsString('Plan overage (1)', $body);
    }

    #[Test]
    public function reasonBadgesInRowAreAnchors(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        $crawler = $data['client']->getCrawler();

        // Each table row's reason cell is now an anchor pointing to the
        // filtered view. `relative z-20` keeps the badge click ahead of the
        // stretched-row link (TASK-018).
        $badgeAnchors = $crawler->filter('table tbody tr td a.badge');
        self::assertCount(3, $badgeAnchors, 'Each row should expose its reason as a clickable anchor.');

        /** @var list<string> $hrefs */
        $hrefs = $badgeAnchors->extract(['href']);
        sort($hrefs);
        self::assertSame([
            '/app/quarantine?reason=plan_overage',
            '/app/quarantine?reason=unknown_domain',
            '/app/quarantine?reason=unverified_domain',
        ], $hrefs);
    }

    #[Test]
    public function planOverageFilterRendersUpsellCard(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=plan_overage');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString("These reports were received after you hit this month's cap", $body);
        // Upgrade CTA points at the in-app billing page — re-uses TASK-006-era
        // billing route rather than the public pricing page.
        self::assertStringContainsString('href="/app/settings/billing"', $body);
        self::assertStringContainsString('Upgrade', $body);
    }

    #[Test]
    public function unknownDomainFilterRendersTipCard(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=unknown_domain');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString("These reports were sent for domains you haven't added yet", $body);
        self::assertStringContainsString('Add this domain', $body);
    }

    #[Test]
    public function unverifiedDomainFilterRendersTipCard(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=unverified_domain');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString("These reports are for domains you've added but not yet verified", $body);
    }

    #[Test]
    public function paginationLinksPreserveActiveFilter(): void
    {
        // Re-use the standard 3-reasons seed, then top up to 51 plan-overage
        // rows so the page-1 view of ?reason=plan_overage flags hasNextPage.
        $data = $this->bootClientWithThreeReasons();
        $em = $data['em'];

        // Existing plan_overage domain already has 1 row — add 50 more.
        for ($i = 0; $i < 50; ++$i) {
            $this->persistQuarantine(
                $em,
                $data['planOverageDomain'],
                QuarantineReason::PlanOverage,
            );
        }

        $em->flush();

        $data['client']->request('GET', '/app/quarantine?reason=plan_overage');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Page-2 link must carry the active filter forward — otherwise the
        // user lands on an unfiltered page 2 and loses context.
        self::assertStringContainsString('href="/app/quarantine?page=2&amp;reason=plan_overage"', $body);
    }
}
