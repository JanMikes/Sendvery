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

final class Stage14PagesTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domainId: \Ramsey\Uuid\UuidInterface}
     */
    private function createAuthenticatedClientWithData(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 's14-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Stage14 Test',
            slug: 'stage14-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: 'personal',
        );
        $team->popEvents();
        $em->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'stage14-test.com',
            createdAt: new \DateTimeImmutable(),
            dmarcPolicy: DmarcPolicy::Reject,
        );
        $domain->popEvents();
        $em->persist($domain);

        $reportId = Uuid::uuid7();
        $report = new DmarcReport(
            id: $reportId,
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-s14-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: 'stage14-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $record = new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'stage14-test.com',
        );
        $em->persist($record);

        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '1.2.3.4',
            firstSeenAt: new \DateTimeImmutable('-7 days'),
            lastSeenAt: new \DateTimeImmutable(),
            totalMessages: 500,
            passRate: 98.0,
            hostname: 'mail.google.com',
            organization: 'Google',
            isAuthorized: true,
        );
        $em->persist($sender);

        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'domainId' => $domainId,
        ];
    }

    #[Test]
    public function senderInventoryPageReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sender Inventory');
        self::assertSelectorTextContains('body', '1.2.3.4');
        self::assertSelectorTextContains('body', 'Google');
    }

    #[Test]
    public function senderInventoryFilterWorks(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/senders?filter=authorized');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '1.2.3.4');
    }

    #[Test]
    public function blacklistStatusPageReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/blacklist');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Blacklist Status');
    }

    #[Test]
    public function domainHealthPageReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/health');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Domain Health');
    }

    #[Test]
    public function senderInventoryReturns404ForNonexistentDomain(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.Uuid::uuid7().'/senders');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function blacklistStatusReturns404ForNonexistentDomain(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.Uuid::uuid7().'/blacklist');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function domainHealthReturns404ForNonexistentDomain(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.Uuid::uuid7().'/health');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function publicDomainHealthReturns404ForInvalidHash(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health/nonexistent-hash-123');

        self::assertResponseStatusCodeSame(404);
    }
}
