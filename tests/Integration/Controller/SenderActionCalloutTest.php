<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
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
 * End-to-end coverage for TASK-092's inline row callout on the Sender
 * Inventory page. Each branch persists the right 30-day shape, renders the
 * page, and asserts the row callout surfaces with the matching severity.
 *
 * The healthy-row regression test pins the absence of the callout so a
 * future refactor can't sneak it back into the always-on state and re-noise
 * every inventory page.
 */
final class SenderActionCalloutTest extends WebTestCase
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
            email: 'sender-callout-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Sender Callout Team',
            slug: 'sender-callout-'.Uuid::uuid7()->toString(),
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
            domain: 'callout-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->flush();
        $client->loginUser($user);

        return ['client' => $client, 'em' => $em, 'user' => $user, 'team' => $team, 'domain' => $domain];
    }

    private function persistSender(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        string $sourceIp,
        ?string $organization,
        bool $isAuthorized = false,
    ): KnownSender {
        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: $sourceIp,
            firstSeenAt: new \DateTimeImmutable('-90 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 9999,
            passRate: 99.0,
            organization: $organization,
            isAuthorized: $isAuthorized,
        );
        $em->persist($sender);
        $em->flush();

        return $sender;
    }

    /**
     * Persist a single DmarcReport + a single DmarcRecord whose `count` and
     * `dkimResult` produce a target 30-day volume + pass-rate for the IP. One
     * record per sender keeps fixture overhead minimal — the advisor only
     * reads aggregates, not per-row distribution.
     */
    private function persistActivity(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        string $sourceIp,
        int $totalMessages,
        int $dkimPasses,
    ): void {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc@google.com',
            externalReportId: 'report-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable('-1 day'),
        );
        $em->persist($report);

        $dkimFails = $totalMessages - $dkimPasses;

        // Two records (pass + fail) when both exist; otherwise one record.
        if ($dkimPasses > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: $sourceIp,
                count: $dkimPasses,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $domain->domain,
            ));
        }

        if ($dkimFails > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: $sourceIp,
                count: $dkimFails,
                disposition: Disposition::None,
                dkimResult: AuthResult::Fail,
                spfResult: AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }

        $em->flush();
    }

    #[Test]
    public function recommendAuthorizeCalloutRendersForKnownHighVolumeSender(): void
    {
        $boot = $this->bootClient();
        $sender = $this->persistSender($boot['em'], $boot['domain'], '203.0.113.10', 'Mailchimp');
        // 100 messages, 99 pass → 99% pass rate, well above 90% authorize floor.
        $this->persistActivity($boot['em'], $boot['domain'], '203.0.113.10', 100, 99);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/senders');

        self::assertResponseIsSuccessful();
        $callout = $crawler->filter('[data-testid="sender-action-callout"][data-sender-id="'.$sender->id->toString().'"]');
        self::assertGreaterThan(0, $callout->count(), 'recommend_authorize callout must render.');
        self::assertSame('recommend_authorize', (string) $callout->attr('data-severity'));
        self::assertStringContainsString(
            'Consider authorizing',
            (string) $crawler->filter('[data-testid="sender-action-callout-headline"]')->text(),
        );
        self::assertStringContainsString('Mailchimp', (string) $crawler->filter('[data-testid="sender-action-callout-reason"]')->text());
    }

    #[Test]
    public function recommendRevokeCalloutRendersForUnknownFailingSender(): void
    {
        $boot = $this->bootClient();
        $sender = $this->persistSender($boot['em'], $boot['domain'], '198.51.100.7', null);
        // 50 messages, 5 pass → 10% pass rate, well below 50% revoke ceiling.
        $this->persistActivity($boot['em'], $boot['domain'], '198.51.100.7', 50, 5);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/senders');

        self::assertResponseIsSuccessful();
        $callout = $crawler->filter('[data-testid="sender-action-callout"][data-sender-id="'.$sender->id->toString().'"]');
        self::assertGreaterThan(0, $callout->count(), 'recommend_revoke callout must render.');
        self::assertSame('recommend_revoke', (string) $callout->attr('data-severity'));
        self::assertStringContainsString(
            'Consider revoking',
            (string) $crawler->filter('[data-testid="sender-action-callout-headline"]')->text(),
        );
        self::assertStringContainsString('198.51.100.7', (string) $crawler->filter('[data-testid="sender-action-callout-reason"]')->text());
    }

    #[Test]
    public function noCalloutForMonitorSender(): void
    {
        $boot = $this->bootClient();
        $sender = $this->persistSender($boot['em'], $boot['domain'], '203.0.113.99', 'IntermediateCorp');
        // 25 messages, 20 pass → 80% pass rate. Above monitor floor, below the
        // authorize 90% threshold. Falls into Monitor — no row callout rendered.
        $this->persistActivity($boot['em'], $boot['domain'], '203.0.113.99', 25, 20);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/senders');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="sender-action-callout"][data-sender-id="'.$sender->id->toString().'"]'));
    }

    #[Test]
    public function noCalloutWhenSenderAuthorized(): void
    {
        $boot = $this->bootClient();
        $sender = $this->persistSender($boot['em'], $boot['domain'], '203.0.113.20', 'Mailchimp', isAuthorized: true);
        $this->persistActivity($boot['em'], $boot['domain'], '203.0.113.20', 100, 99);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/senders');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="sender-action-callout"][data-sender-id="'.$sender->id->toString().'"]'));
    }

    #[Test]
    public function needsDecisionStatRowRendersAndCountsBothBranches(): void
    {
        $boot = $this->bootClient();
        // One authorize, one revoke = 2 needs-decision rows.
        $this->persistSender($boot['em'], $boot['domain'], '203.0.113.30', 'Mailchimp');
        $this->persistActivity($boot['em'], $boot['domain'], '203.0.113.30', 100, 99);
        $this->persistSender($boot['em'], $boot['domain'], '198.51.100.8', null);
        $this->persistActivity($boot['em'], $boot['domain'], '198.51.100.8', 50, 5);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/senders');

        self::assertResponseIsSuccessful();
        $stat = $crawler->filter('[data-testid="sender-needs-decision-stat"]');
        self::assertGreaterThan(0, $stat->count());
        self::assertStringContainsString('2 senders need a decision', $stat->text());
    }

    #[Test]
    public function needsDecisionFilterNarrowsTableToBothBranches(): void
    {
        $boot = $this->bootClient();
        $this->persistSender($boot['em'], $boot['domain'], '203.0.113.40', 'Mailchimp'); // authorize
        $this->persistActivity($boot['em'], $boot['domain'], '203.0.113.40', 100, 99);
        $this->persistSender($boot['em'], $boot['domain'], '198.51.100.9', null); // revoke
        $this->persistActivity($boot['em'], $boot['domain'], '198.51.100.9', 50, 5);
        $watched = $this->persistSender($boot['em'], $boot['domain'], '203.0.113.50', 'WatchCorp'); // monitor
        $this->persistActivity($boot['em'], $boot['domain'], '203.0.113.50', 25, 20);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/senders?recommendation=needs_decision');

        self::assertResponseIsSuccessful();
        // The "watched" monitor row must NOT appear in the filtered table.
        self::assertCount(0, $crawler->filter('tr#sender-'.$watched->id->toString()));
        // The filter chip must render as active.
        self::assertGreaterThan(0, $crawler->filter('[data-testid="sender-needs-decision-active-tab"]')->count());
    }
}
