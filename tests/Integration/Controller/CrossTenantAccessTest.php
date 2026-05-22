<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\DmarcReport;
use App\Entity\TeamInvitation;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Security-critical: a user logged in as a member of team A must never
 * be able to read or mutate resources owned by team B by guessing the
 * UUID. Each test creates two unrelated personas — owner A with their
 * own domain/report/alert/invitation, and an unrelated owner B — then
 * logs in as B and asserts the response is 404 (or, for the multi-team
 * complement, 200 once we add B to A's team).
 */
final class CrossTenantAccessTest extends WebTestCase
{
    #[Test]
    public function domainDetailDeniesCrossTenant(): void
    {
        [$victimDomainId, $attackerClient] = $this->setupVictimAndAttacker();
        $attackerClient->request('GET', '/app/domains/'.$victimDomainId);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function domainReportsDeniesCrossTenant(): void
    {
        [$victimDomainId, $attackerClient] = $this->setupVictimAndAttacker();
        $attackerClient->request('GET', '/app/domains/'.$victimDomainId.'/reports');

        // The reports list controller doesn't 404 on an unknown domain (the
        // SQL just returns an empty list) — confirm cross-tenant produces an
        // empty page, not the victim's reports.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'noreply@google.com');
    }

    #[Test]
    public function domainBlacklistDeniesCrossTenant(): void
    {
        [$victimDomainId, $attackerClient] = $this->setupVictimAndAttacker();
        $attackerClient->request('GET', '/app/domains/'.$victimDomainId.'/blacklist');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function domainDnsHistoryDeniesCrossTenant(): void
    {
        [$victimDomainId, $attackerClient] = $this->setupVictimAndAttacker();
        $attackerClient->request('GET', '/app/domains/'.$victimDomainId.'/dns-history');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function domainHealthDeniesCrossTenant(): void
    {
        [$victimDomainId, $attackerClient] = $this->setupVictimAndAttacker();
        $attackerClient->request('GET', '/app/domains/'.$victimDomainId.'/health');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function domainSendersDeniesCrossTenant(): void
    {
        [$victimDomainId, $attackerClient] = $this->setupVictimAndAttacker();
        $attackerClient->request('GET', '/app/domains/'.$victimDomainId.'/senders');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function pdfExportDeniesCrossTenant(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $victim = $fixtures->persona()->emailPrefix('victim')->plan('business')->build();
        $attacker = $fixtures->persona()->emailPrefix('attacker')->plan('business')->build();
        assert(null !== $victim->domain);

        $client->loginUser($attacker->user);
        $client->request('GET', '/app/domains/'.$victim->domain->id.'/export/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function reverifyDeniesCrossTenant(): void
    {
        [$victimDomainId, $attackerClient] = $this->setupVictimAndAttacker();
        $attackerClient->request('POST', '/app/domains/'.$victimDomainId.'/reverify');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function reportDetailDeniesCrossTenant(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $victim = $fixtures->onboardedOwner();
        $attacker = $fixtures->persona()->emailPrefix('attacker')->build();
        assert(null !== $victim->domain);

        $reportId = Uuid::uuid7();
        $em->persist(new DmarcReport(
            id: $reportId,
            monitoredDomain: $victim->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-cross-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $victim->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $client->loginUser($attacker->user);
        $client->request('GET', '/app/reports/'.$reportId);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function alertDetailDeniesCrossTenant(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $victim = $fixtures->onboardedOwner();
        $attacker = $fixtures->persona()->emailPrefix('attacker')->build();

        $alertId = Uuid::uuid7();
        $em->persist(new Alert(
            id: $alertId,
            team: $victim->team,
            monitoredDomain: $victim->domain,
            type: AlertType::DnsRecordChanged,
            severity: AlertSeverity::Info,
            title: 'Secret alert',
            message: 'For victim eyes only.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $client->loginUser($attacker->user);
        $client->request('GET', '/app/alerts/'.$alertId);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function markAlertReadDeniesCrossTenant(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $victim = $fixtures->onboardedOwner();
        $attacker = $fixtures->persona()->emailPrefix('attacker')->build();

        $alertId = Uuid::uuid7();
        $em->persist(new Alert(
            id: $alertId,
            team: $victim->team,
            monitoredDomain: $victim->domain,
            type: AlertType::DnsRecordChanged,
            severity: AlertSeverity::Info,
            title: 'Secret alert',
            message: 'For victim eyes only.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $client->loginUser($attacker->user);
        $client->request('POST', '/app/alerts/'.$alertId.'/read');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function removeMemberDeniesCrossTenant(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $victim = $fixtures->persona()->emailPrefix('victim')->build();
        $teammate = $fixtures->addExtraTeammate($victim->team);
        $attacker = $fixtures->persona()->emailPrefix('attacker')->build();

        // Find the teammate's membership id (in victim's team).
        $teammateMembership = $em->getRepository(\App\Entity\TeamMembership::class)
            ->findOneBy(['user' => $teammate->id->toString(), 'team' => $victim->team->id->toString()]);
        self::assertNotNull($teammateMembership);

        $client->loginUser($attacker->user);
        $client->request('POST', '/app/team/members/'.$teammateMembership->id.'/remove');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function revokeInvitationDeniesCrossTenant(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $victim = $fixtures->onboardedOwner();
        $attacker = $fixtures->persona()->emailPrefix('attacker')->build();

        $invitation = new TeamInvitation(
            id: Uuid::uuid7(),
            team: $victim->team,
            invitedEmail: 'pending-'.Uuid::uuid7()->toString().'@example.com',
            invitedBy: $victim->user,
            role: TeamRole::Member,
            invitationToken: bin2hex(random_bytes(32)),
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->loginUser($attacker->user);
        $client->request('POST', '/app/team/invitations/'.$invitation->id.'/revoke');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function resendInvitationDeniesCrossTenant(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $victim = $fixtures->onboardedOwner();
        $attacker = $fixtures->persona()->emailPrefix('attacker')->build();

        $invitation = new TeamInvitation(
            id: Uuid::uuid7(),
            team: $victim->team,
            invitedEmail: 'pending-'.Uuid::uuid7()->toString().'@example.com',
            invitedBy: $victim->user,
            role: TeamRole::Member,
            invitationToken: bin2hex(random_bytes(32)),
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->loginUser($attacker->user);
        $client->request('POST', '/app/team/invitations/'.$invitation->id.'/resend');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Forward-compatibility check: a user with memberships in BOTH teams can
     * still read either team's resources. The security model is "scope by
     * every joined team", not "scope by the active team".
     */
    #[Test]
    public function userInBothTeamsCanAccessEither(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $teamA = $fixtures->persona()->emailPrefix('team-a')->build();
        $teamB = $fixtures->persona()->emailPrefix('team-b')->build();
        assert(null !== $teamA->domain);
        assert(null !== $teamB->domain);

        // Add teamA's user as a member of teamB too (multi-team scenario).
        $em->persist(new \App\Entity\TeamMembership(
            id: Uuid::uuid7(),
            user: $teamA->user,
            team: $teamB->team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $client->loginUser($teamA->user);

        $client->request('GET', '/app/domains/'.$teamA->domain->id);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/app/domains/'.$teamB->domain->id);
        self::assertResponseIsSuccessful();
    }

    /**
     * Build a victim domain owned by team A, then log in as an unrelated
     * owner B and return the victim's domain id + B's client. Shared by all
     * "domain subpage / action denies cross-tenant" tests.
     *
     * @return array{0: string, 1: \Symfony\Bundle\FrameworkBundle\KernelBrowser}
     */
    private function setupVictimAndAttacker(): array
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $victim = $fixtures->persona()->emailPrefix('victim')->build();
        $attacker = $fixtures->persona()->emailPrefix('attacker')->build();
        assert(null !== $victim->domain);

        $client->loginUser($attacker->user);

        return [$victim->domain->id->toString(), $client];
    }
}
