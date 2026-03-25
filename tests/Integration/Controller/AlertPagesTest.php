<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\DnsCheckType;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AlertPagesTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domainId: \Ramsey\Uuid\UuidInterface, alertId: \Ramsey\Uuid\UuidInterface, teamId: \Ramsey\Uuid\UuidInterface}
     */
    private function createAuthenticatedClientWithAlerts(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'alert-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Alert Test',
            slug: 'alert-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
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
            domain: 'alert-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $alertId = Uuid::uuid7();
        $alert = new Alert(
            id: $alertId,
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::DnsRecordChanged,
            severity: AlertSeverity::Warning,
            title: 'SPF record changed for alert-test.com',
            message: 'The SPF record has been modified.',
            data: ['dns_check_type' => 'spf', 'current_record' => 'v=spf1 ~all'],
            createdAt: new \DateTimeImmutable(),
        );
        $alert->popEvents();
        $em->persist($alert);

        $criticalAlert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::DnsRecordMissing,
            severity: AlertSeverity::Critical,
            title: 'DMARC record missing for alert-test.com',
            message: 'The DMARC record was removed.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );
        $criticalAlert->popEvents();
        $em->persist($criticalAlert);

        // DNS check results for history page
        $dnsCheck = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: 'v=spf1 ~all',
            isValid: true,
            issues: [],
            details: ['lookup_count' => 1],
            previousRawRecord: null,
            hasChanged: false,
        );
        $dnsCheck->popEvents();
        $em->persist($dnsCheck);

        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'domainId' => $domainId,
            'alertId' => $alertId,
            'teamId' => $teamId,
        ];
    }

    #[Test]
    public function alertsListReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Alerts');
        self::assertSelectorTextContains('body', 'SPF record changed');
    }

    #[Test]
    public function alertsListShowsSeverityFilters(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/alerts');

        self::assertSelectorTextContains('body', 'Critical');
        self::assertSelectorTextContains('body', 'Warning');
        self::assertSelectorTextContains('body', 'Info');
    }

    #[Test]
    public function alertsListFiltersBySeverity(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/alerts?severity=critical');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'DMARC record missing');
    }

    #[Test]
    public function alertDetailReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/alerts/'.$data['alertId']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'SPF record changed');
        self::assertSelectorTextContains('body', 'The SPF record has been modified.');
    }

    #[Test]
    public function alertDetailShowsContextData(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/alerts/'.$data['alertId']);

        self::assertSelectorTextContains('body', 'Details');
        self::assertSelectorTextContains('body', 'v=spf1 ~all');
    }

    #[Test]
    public function alertDetailReturns404ForNonexistent(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/alerts/'.Uuid::uuid7());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function markAlertAsReadRedirects(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('POST', '/app/alerts/'.$data['alertId'].'/read');

        self::assertResponseRedirects('/app/alerts');
    }

    #[Test]
    public function dnsHistoryReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/dns-history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'DNS History');
        self::assertSelectorTextContains('body', 'alert-test.com');
    }

    #[Test]
    public function dnsHistoryShowsCheckResults(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/dns-history');

        self::assertSelectorTextContains('body', 'SPF');
        self::assertSelectorTextContains('body', 'v=spf1 ~all');
    }

    #[Test]
    public function dnsHistoryReturns404ForNonexistentDomain(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app/domains/'.Uuid::uuid7().'/dns-history');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function dashboardOverviewShowsUnreadAlerts(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Unread Alerts');
    }

    #[Test]
    public function dashboardSidebarHasAlertsLink(): void
    {
        $data = $this->createAuthenticatedClientWithAlerts();

        $data['client']->request('GET', '/app');

        self::assertSelectorTextContains('aside', 'Alerts');
    }
}
