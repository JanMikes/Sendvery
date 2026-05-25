<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Twig\NavCountsExtension;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Drives the consolidated {@see NavCountsExtension} through the same
 * scenarios the now-deleted AlertCountExtensionTest +
 * DomainHealthCountExtensionTest covered, plus a quarantine pile-up case
 * that the old setup did not have a dedicated test for.
 *
 * Tests assert on `getGlobals()` directly (not rendered HTML): the template
 * branching is trivial and the high-value contract is "which counts come out
 * of the extension for which fixtures."
 */
final class NavCountsExtensionTest extends WebTestCase
{
    #[Test]
    public function noUserReturnsZeroForEveryCount(): void
    {
        self::createClient();

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(0, $globals['quarantine_count']);
        self::assertSame(0, $globals['unread_alert_count']);
        self::assertSame(0, $globals['critical_alert_count']);
        self::assertSame(0, $globals['unverified_domain_count']);
    }

    #[Test]
    public function noTeamMembershipReturnsZeroForEveryCount(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->notOnboarded()->build();
        $em = $this->getService(EntityManagerInterface::class);
        $em->remove($persona->membership);
        $em->flush();
        $client->loginUser($persona->user);

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(0, $globals['quarantine_count']);
        self::assertSame(0, $globals['unread_alert_count']);
        self::assertSame(0, $globals['critical_alert_count']);
        self::assertSame(0, $globals['unverified_domain_count']);
    }

    #[Test]
    public function unreadNonCriticalAlertSetsUnreadCountOnly(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistAlert($em, $persona->team, AlertSeverity::Warning);
        $this->persistAlert($em, $persona->team, AlertSeverity::Warning);
        $this->persistAlert($em, $persona->team, AlertSeverity::Warning);
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(3, $globals['unread_alert_count']);
        self::assertSame(0, $globals['critical_alert_count']);
    }

    #[Test]
    public function criticalAlertSetsBothAlertCounts(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistAlert($em, $persona->team, AlertSeverity::Warning);
        $this->persistAlert($em, $persona->team, AlertSeverity::Warning);
        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(3, $globals['unread_alert_count']);
        self::assertSame(1, $globals['critical_alert_count']);
    }

    #[Test]
    public function oneUnverifiedDomainReturnsOne(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(1, $globals['unverified_domain_count']);
    }

    #[Test]
    public function tenUnverifiedDomainsReturnsTen(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $em = $this->getService(EntityManagerInterface::class);

        for ($i = 0; $i < 10; ++$i) {
            $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        }
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(10, $globals['unverified_domain_count']);
    }

    #[Test]
    public function verifiedDomainsDoNotCountTowardUnverified(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $em = $this->getService(EntityManagerInterface::class);

        $verifiedAt = new \DateTimeImmutable();
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: $verifiedAt);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: $verifiedAt);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(1, $globals['unverified_domain_count']);
    }

    #[Test]
    public function quarantinedReportForOwnedDomainIsCounted(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $em = $this->getService(EntityManagerInterface::class);

        $domain = $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable());
        $this->persistQuarantined($em, $domain->domain);
        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(1, $globals['quarantine_count']);
    }

    #[Test]
    public function allFourCountsCoexistInOneRequest(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->withoutDomain()->build();
        $em = $this->getService(EntityManagerInterface::class);

        // 1 critical + 1 warning alert → unread=2, critical=1.
        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $this->persistAlert($em, $persona->team, AlertSeverity::Warning);

        // 1 verified + 2 unverified domains → unverified=2.
        $verifiedDomain = $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable());
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);

        // 1 quarantined report for the verified domain → quarantine=1.
        $this->persistQuarantined($em, $verifiedDomain->domain);

        $em->flush();

        $client->loginUser($persona->user);

        $globals = $this->getService(NavCountsExtension::class)->getGlobals();

        self::assertSame(1, $globals['quarantine_count']);
        self::assertSame(2, $globals['unread_alert_count']);
        self::assertSame(1, $globals['critical_alert_count']);
        self::assertSame(2, $globals['unverified_domain_count']);
    }

    private function persistAlert(EntityManagerInterface $em, Team $team, AlertSeverity $severity): void
    {
        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::FailureSpike,
            severity: $severity,
            title: 'Test alert',
            message: 'Test message',
            data: [],
            createdAt: new \DateTimeImmutable(),
            isRead: false,
            snoozedUntil: null,
        );
        $alert->popEvents();
        $em->persist($alert);
    }

    private function persistDomain(
        EntityManagerInterface $em,
        Team $team,
        ?\DateTimeImmutable $dmarcVerifiedAt,
    ): MonitoredDomain {
        $id = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'd-'.$id->toString().'.example',
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: $dmarcVerifiedAt,
        );
        $domain->popEvents();
        $em->persist($domain);

        return $domain;
    }

    private function persistQuarantined(EntityManagerInterface $em, string $domainName): void
    {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply-dmarc@google.com',
            subject: 'Report Domain: '.$domainName,
            receivedAt: new \DateTimeImmutable('-2 hours'),
            ingestedAt: new \DateTimeImmutable('-2 hours'),
            sizeBytes: 100,
            rawEml: 'fake',
            mailboxConnection: null,
        );
        $em->persist($envelope);

        $xml = '<feedback></feedback>';
        $compressed = gzencode($xml);
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: QuarantineReason::UnknownDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);
    }
}
