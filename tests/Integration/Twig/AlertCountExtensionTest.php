<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig;

use App\Entity\Alert;
use App\Entity\Team;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Twig\AlertCountExtension;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Drives the {@see AlertCountExtension} through realistic scenarios so the
 * sidebar's two-tier "N waiting" badge always reflects the right team scope
 * and the right severity tier.
 *
 * Tests assert on `getGlobals()` directly (not rendered HTML) — the template
 * branching is trivial and the high-value contract is "which counts come out
 * of the extension for which fixtures."
 */
final class AlertCountExtensionTest extends WebTestCase
{
    #[Test]
    public function noUserReturnsZeroCounts(): void
    {
        self::createClient();

        $globals = $this->getService(AlertCountExtension::class)->getGlobals();

        self::assertSame(0, $globals['unread_alert_count']);
        self::assertSame(0, $globals['critical_alert_count']);
    }

    #[Test]
    public function noTeamMembershipReturnsZeroCounts(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->notOnboarded()->build();
        $em = $this->getService(EntityManagerInterface::class);
        $em->remove($persona->membership);
        $em->flush();
        $client->loginUser($persona->user);

        $globals = $this->getService(AlertCountExtension::class)->getGlobals();

        self::assertSame(0, $globals['unread_alert_count']);
        self::assertSame(0, $globals['critical_alert_count']);
    }

    #[Test]
    public function zeroAlertsReturnsBothCountsAsZero(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $globals = $this->getService(AlertCountExtension::class)->getGlobals();

        self::assertSame(0, $globals['unread_alert_count']);
        self::assertSame(0, $globals['critical_alert_count']);
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

        $globals = $this->getService(AlertCountExtension::class)->getGlobals();

        self::assertSame(3, $globals['unread_alert_count']);
        self::assertSame(0, $globals['critical_alert_count']);
    }

    #[Test]
    public function criticalAlertSetsBothCounts(): void
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

        $globals = $this->getService(AlertCountExtension::class)->getGlobals();

        self::assertSame(3, $globals['unread_alert_count']);
        self::assertSame(1, $globals['critical_alert_count']);
    }

    private function persistAlert(
        EntityManagerInterface $em,
        Team $team,
        AlertSeverity $severity,
        bool $isRead = false,
        ?\DateTimeImmutable $snoozedUntil = null,
    ): Alert {
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
            isRead: $isRead,
            snoozedUntil: $snoozedUntil,
        );
        $alert->popEvents();
        $em->persist($alert);

        return $alert;
    }
}
