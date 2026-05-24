<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Integration coverage for TASK-070: unread alert rows on /app/alerts gain
 * a `bg-{tone}/5` tinted background AND a leading 8px unread dot. Read
 * rows keep `bg-base-100`. The redundant `badge-xs badge-primary` "New"
 * badge is removed — the dot + tinted bg + bold title already convey
 * unread.
 */
final class AlertsListRowTintingTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, team: Team}
     */
    private function bootClientWithSixAlerts(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'tint-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Tint Test',
            slug: 'tint-'.Uuid::uuid7()->toString(),
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
            domain: 'tint-test-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        // Six alerts: 3 severities × {unread, read}.
        $this->persistAlert($em, $team, $domain, AlertSeverity::Critical, 'Critical Unread Alert', isRead: false);
        $this->persistAlert($em, $team, $domain, AlertSeverity::Critical, 'Critical Read Alert', isRead: true);
        $this->persistAlert($em, $team, $domain, AlertSeverity::Warning, 'Warning Unread Alert', isRead: false);
        $this->persistAlert($em, $team, $domain, AlertSeverity::Warning, 'Warning Read Alert', isRead: true);
        $this->persistAlert($em, $team, $domain, AlertSeverity::Info, 'Info Unread Alert', isRead: false);
        $this->persistAlert($em, $team, $domain, AlertSeverity::Info, 'Info Read Alert', isRead: true);

        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'team' => $team,
        ];
    }

    private function persistAlert(
        EntityManagerInterface $em,
        Team $team,
        MonitoredDomain $domain,
        AlertSeverity $severity,
        string $title,
        bool $isRead,
    ): Alert {
        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::DnsRecordChanged,
            severity: $severity,
            title: $title,
            message: 'Body for '.$title,
            data: [],
            createdAt: new \DateTimeImmutable(),
            isRead: $isRead,
        );
        $alert->popEvents();
        $em->persist($alert);

        return $alert;
    }

    #[Test]
    public function criticalUnreadAlertCardCarriesErrorTintedBackground(): void
    {
        $data = $this->bootClientWithSixAlerts();

        $data['client']->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Critical Unread Alert', $body);
        self::assertStringContainsString('bg-error/5', $body);
    }

    #[Test]
    public function warningUnreadAlertCardCarriesWarningTintedBackground(): void
    {
        $data = $this->bootClientWithSixAlerts();

        $data['client']->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Warning Unread Alert', $body);
        self::assertStringContainsString('bg-warning/5', $body);
    }

    #[Test]
    public function infoUnreadAlertCardCarriesInfoTintedBackground(): void
    {
        $data = $this->bootClientWithSixAlerts();

        $data['client']->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Info Unread Alert', $body);
        self::assertStringContainsString('bg-info/5', $body);
    }

    #[Test]
    public function readAlertCardsStayOnPlainBaseBackground(): void
    {
        // Seed only read alerts so we can assert no tinted background slips in.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'read-only-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Read Only',
            slug: 'read-only-'.Uuid::uuid7()->toString(),
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
            domain: 'read-only-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        foreach ([AlertSeverity::Critical, AlertSeverity::Warning, AlertSeverity::Info] as $severity) {
            $this->persistAlert($em, $team, $domain, $severity, $severity->value.' read-only', isRead: true);
        }
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('read-only', $body);
        // No tinted unread backgrounds anywhere when every row is read.
        self::assertStringNotContainsString('bg-error/5', $body);
        self::assertStringNotContainsString('bg-warning/5', $body);
        self::assertStringNotContainsString('bg-info/5', $body);
        // Read rows fall back to the plain card background.
        self::assertStringContainsString('bg-base-100', $body);
    }

    #[Test]
    public function newBadgeNoLongerRendersOnUnreadRows(): void
    {
        // Regression guard: the redundant `badge-xs badge-primary` "New" badge
        // was removed (TASK-070) — the dot + tinted bg + bold title already
        // convey unread, and the badge crowded the title row.
        $data = $this->bootClientWithSixAlerts();

        $data['client']->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // The exact dropped HTML — if anyone re-adds the badge, this trips.
        self::assertStringNotContainsString('<span class="badge badge-xs badge-primary">New</span>', $body);
    }

    #[Test]
    public function unreadRowsRenderTheLeadingUnreadDot(): void
    {
        // The dot is the in-title-row replacement for the dropped "New" badge.
        // `aria-label="Unread"` is a contract for assistive tech.
        $data = $this->bootClientWithSixAlerts();

        $data['client']->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // 3 unread rows × 1 dot each.
        self::assertSame(
            3,
            substr_count($body, 'aria-label="Unread"'),
            'Each unread alert row must render exactly one leading unread dot.',
        );
    }
}
