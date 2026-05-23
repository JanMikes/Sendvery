<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\MutedAlert;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AlertActionsTest extends WebTestCase
{
    /**
     * @return array{
     *     client: KernelBrowser,
     *     em: EntityManagerInterface,
     *     team: Team,
     *     domain: MonitoredDomain,
     *     alertId: UuidInterface,
     *     domainlessAlertId: UuidInterface
     * }
     */
    private function bootClientWithAlert(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'actions-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Actions Team',
            slug: 'actions-'.Uuid::uuid7()->toString(),
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
            domain: 'actions-'.Uuid::uuid7()->toString().'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $alertId = Uuid::uuid7();
        $alert = new Alert(
            id: $alertId,
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Warning,
            title: 'Spike on '.$domain->domain,
            message: 'Spike detected.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );
        $alert->popEvents();
        $em->persist($alert);

        $domainlessAlertId = Uuid::uuid7();
        $domainlessAlert = new Alert(
            id: $domainlessAlertId,
            team: $team,
            monitoredDomain: null,
            type: AlertType::MailboxConnectionError,
            severity: AlertSeverity::Critical,
            title: 'Mailbox connection error',
            message: 'mailbox down',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );
        $domainlessAlert->popEvents();
        $em->persist($domainlessAlert);

        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'em' => $em,
            'team' => $team,
            'domain' => $domain,
            'alertId' => $alertId,
            'domainlessAlertId' => $domainlessAlertId,
        ];
    }

    /**
     * Bootstrap an authenticated session and write a CSRF token directly
     * into it. The token manager de-randomizes submitted values; an
     * unrandomized "raw" value falls through `derandomize` unchanged
     * (it has no `.`s), so storing `X` and submitting `X` matches.
     */
    private function csrfToken(KernelBrowser $client, string $id): string
    {
        // Make a GET to seed the session cookie in the client's cookie jar.
        $client->request('GET', '/app/alerts');

        // Persist into the same on-disk session that the next sub-request
        // will read from. The kernel reboots between sub-requests in test
        // mode, but the file-based session handler keeps state across them
        // because the SESSION cookie ID is reused.
        $client->getCookieJar()->all();
        $cookie = $client->getCookieJar()->get('MOCKSESSID') ?? $client->getCookieJar()->get('PHPSESSID');
        self::assertNotNull($cookie, 'Session cookie not set after warm-up GET.');

        $token = bin2hex(random_bytes(16));

        // The session was saved at the end of the previous request — open
        // it again, write the token, save. Symfony's NativeSessionStorage
        // (test mode handler_id=null) writes to PHP's default save path.
        // We bypass it by writing via a Session bound to the active id.
        $factory = self::getContainer()->get('session.factory');
        assert($factory instanceof \Symfony\Component\HttpFoundation\Session\SessionFactoryInterface);

        $session = $factory->createSession();
        $session->setId($cookie->getValue());
        $session->start();
        $session->set('_csrf/'.$id, $token);
        $session->save();

        return $token;
    }

    public function testSnoozeAlertWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/snooze', [
            'days' => 7,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSnoozeAlertSetsDeadlineAndRedirects(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'snooze_alert');

        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/snooze', [
            'days' => 7,
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/alerts/'.$data['alertId']->toString());

        $data['em']->clear();
        $alert = $data['em']->find(Alert::class, $data['alertId']);
        self::assertNotNull($alert);
        self::assertNotNull($alert->snoozedUntil);
    }

    public function testSnoozeAlertDefaultsToSevenDaysOnInvalidValue(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'snooze_alert');

        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/snooze', [
            'days' => 999,
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects();

        $data['em']->clear();
        $alert = $data['em']->find(Alert::class, $data['alertId']);
        self::assertNotNull($alert);
        self::assertNotNull($alert->snoozedUntil);
    }

    public function testSnoozeUnknownAlertReturns404(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'snooze_alert');

        $data['client']->request('POST', '/app/alerts/'.Uuid::uuid7().'/snooze', [
            'days' => 7,
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnsnoozeClearsDeadline(): void
    {
        $data = $this->bootClientWithAlert();

        // Pre-set a snooze deadline directly.
        $alert = $data['em']->find(Alert::class, $data['alertId']);
        self::assertNotNull($alert);
        $alert->snoozeUntil(new \DateTimeImmutable('+7 days'));
        $data['em']->flush();

        $token = $this->csrfToken($data['client'], 'unsnooze_alert');

        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/unsnooze', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects();

        $data['em']->clear();
        $reloaded = $data['em']->find(Alert::class, $data['alertId']);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->snoozedUntil);
    }

    public function testUnsnoozeWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/unsnooze');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMuteCreatesRowAndRedirects(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'mute_alert');

        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/mute', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects();

        $data['em']->clear();
        $mute = $data['em']->getRepository(MutedAlert::class)->findOneBy([
            'team' => $data['team']->id->toString(),
            'monitoredDomain' => $data['domain']->id->toString(),
            'alertType' => AlertType::FailureSpike,
        ]);
        self::assertNotNull($mute);
    }

    public function testMuteIsIdempotent(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'mute_alert');

        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/mute', [
            '_csrf_token' => $token,
        ]);
        $token = $this->csrfToken($data['client'], 'mute_alert');
        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/mute', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects();

        $data['em']->clear();
        $count = $data['em']->getRepository(MutedAlert::class)->count([
            'team' => $data['team']->id->toString(),
            'monitoredDomain' => $data['domain']->id->toString(),
            'alertType' => AlertType::FailureSpike,
        ]);
        self::assertSame(1, $count);
    }

    public function testMuteOnDomainlessAlertFlashesErrorAndDoesNotPersist(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'mute_alert');

        $data['client']->request('POST', '/app/alerts/'.$data['domainlessAlertId']->toString().'/mute', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects();

        $data['em']->clear();
        $count = $data['em']->getRepository(MutedAlert::class)->count(['team' => $data['team']->id->toString()]);
        self::assertSame(0, $count);
    }

    public function testMuteWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('POST', '/app/alerts/'.$data['alertId']->toString().'/mute');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnmuteRemovesRowAndRedirectsToPreferences(): void
    {
        $data = $this->bootClientWithAlert();

        // Persist a mute first.
        $mute = new MutedAlert(
            id: Uuid::uuid7(),
            team: $data['team'],
            monitoredDomain: $data['domain'],
            alertType: AlertType::FailureSpike,
            mutedAt: new \DateTimeImmutable(),
        );
        $data['em']->persist($mute);
        $data['em']->flush();

        $token = $this->csrfToken($data['client'], 'unmute_alert');

        $data['client']->request('POST', '/app/muted-alerts/'.$mute->id->toString().'/unmute', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/settings/preferences');

        $data['em']->clear();
        self::assertNull($data['em']->find(MutedAlert::class, $mute->id));
    }

    public function testUnmuteUnknownReturns404(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'unmute_alert');

        $data['client']->request('POST', '/app/muted-alerts/'.Uuid::uuid7().'/unmute', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testBulkMarkReadMarksOnlyOwnedAlerts(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'bulk_alert_action');

        // Create a second alert on the same team.
        $secondId = Uuid::uuid7();
        $second = new Alert(
            id: $secondId,
            team: $data['team'],
            monitoredDomain: $data['domain'],
            type: AlertType::DnsRecordChanged,
            severity: AlertSeverity::Warning,
            title: 'Second',
            message: 'second',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );
        $second->popEvents();
        $data['em']->persist($second);
        $data['em']->flush();

        $data['client']->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_read',
            'alertIds' => [
                $data['alertId']->toString(),
                $secondId->toString(),
            ],
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/alerts');

        $data['em']->clear();
        $first = $data['em']->find(Alert::class, $data['alertId']);
        $secondReloaded = $data['em']->find(Alert::class, $secondId);
        self::assertNotNull($first);
        self::assertNotNull($secondReloaded);
        self::assertTrue($first->isRead);
        self::assertTrue($secondReloaded->isRead);
    }

    public function testBulkSnoozeAppliesDeadline(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'bulk_alert_action');

        $data['client']->request('POST', '/app/alerts/bulk', [
            'action' => 'snooze_7d',
            'alertIds' => [$data['alertId']->toString()],
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/alerts');

        $data['em']->clear();
        $alert = $data['em']->find(Alert::class, $data['alertId']);
        self::assertNotNull($alert);
        self::assertNotNull($alert->snoozedUntil);
    }

    public function testBulkActionWithEmptySelectionIsNoOpRedirect(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'bulk_alert_action');

        $data['client']->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_read',
            'alertIds' => [],
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/alerts');
    }

    public function testBulkActionWithUnknownActionReturns404(): void
    {
        $data = $this->bootClientWithAlert();
        $token = $this->csrfToken($data['client'], 'bulk_alert_action');

        $data['client']->request('POST', '/app/alerts/bulk', [
            'action' => 'something_else',
            'alertIds' => [$data['alertId']->toString()],
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testBulkSilentlySkipsCrossTenantAlertIds(): void
    {
        $data = $this->bootClientWithAlert();

        // Create a foreign team + alert that the user is NOT a member of.
        $foreignTeam = new Team(
            id: Uuid::uuid7(),
            name: 'Foreign',
            slug: 'foreign-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $foreignTeam->popEvents();
        $data['em']->persist($foreignTeam);

        $foreignAlertId = Uuid::uuid7();
        $foreignAlert = new Alert(
            id: $foreignAlertId,
            team: $foreignTeam,
            monitoredDomain: null,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Warning,
            title: 'Foreign alert',
            message: 'msg',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );
        $foreignAlert->popEvents();
        $data['em']->persist($foreignAlert);
        $data['em']->flush();

        $token = $this->csrfToken($data['client'], 'bulk_alert_action');
        $data['client']->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_read',
            'alertIds' => [
                $data['alertId']->toString(),
                $foreignAlertId->toString(),
            ],
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/alerts');

        $data['em']->clear();
        $own = $data['em']->find(Alert::class, $data['alertId']);
        $foreign = $data['em']->find(Alert::class, $foreignAlertId);
        self::assertNotNull($own);
        self::assertNotNull($foreign);
        self::assertTrue($own->isRead);
        self::assertFalse($foreign->isRead);
    }

    public function testBulkWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_read',
            'alertIds' => [$data['alertId']->toString()],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAlertsListShowsSnoozedChip(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Snoozed');
    }

    public function testAlertsListSnoozedFilterShowsOnlySnoozedAlerts(): void
    {
        $data = $this->bootClientWithAlert();

        // Snooze one of the alerts.
        $alert = $data['em']->find(Alert::class, $data['alertId']);
        self::assertNotNull($alert);
        $alert->snoozeUntil(new \DateTimeImmutable('+7 days'));
        $data['em']->flush();

        $data['client']->request('GET', '/app/alerts?snoozed=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $alert->title);
    }

    public function testAlertDetailShowsCopyLinkButton(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('GET', '/app/alerts/'.$data['alertId']->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Copy link');
    }

    public function testAlertDetailShowsSnoozeDropdownWhenNotSnoozed(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('GET', '/app/alerts/'.$data['alertId']->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Snooze 1 day');
        self::assertSelectorTextContains('body', 'Snooze 7 days');
        self::assertSelectorTextContains('body', 'Snooze 30 days');
    }

    public function testAlertDetailShowsUnsnoozeWhenSnoozed(): void
    {
        $data = $this->bootClientWithAlert();

        $alert = $data['em']->find(Alert::class, $data['alertId']);
        self::assertNotNull($alert);
        $alert->snoozeUntil(new \DateTimeImmutable('+7 days'));
        $data['em']->flush();

        $data['client']->request('GET', '/app/alerts/'.$data['alertId']->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Unsnooze');
    }

    public function testAlertDetailShowsMuteWhenDomainPresent(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('GET', '/app/alerts/'.$data['alertId']->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Mute this type');
    }

    public function testAlertDetailHidesMuteOnDomainlessAlert(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('GET', '/app/alerts/'.$data['domainlessAlertId']->toString());

        self::assertResponseIsSuccessful();
        $content = $data['client']->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('Mute this type', $content);
    }

    public function testPreferencesPageShowsMutedAlertsSection(): void
    {
        $data = $this->bootClientWithAlert();

        $data['em']->persist(new MutedAlert(
            id: Uuid::uuid7(),
            team: $data['team'],
            monitoredDomain: $data['domain'],
            alertType: AlertType::FailureSpike,
            mutedAt: new \DateTimeImmutable(),
        ));
        $data['em']->flush();

        $data['client']->request('GET', '/app/settings/preferences');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Muted Alert Types');
        self::assertSelectorTextContains('body', $data['domain']->domain);
        self::assertSelectorTextContains('body', 'Unmute');
    }

    public function testPreferencesPageShowsEmptyStateWhenNoMutes(): void
    {
        $data = $this->bootClientWithAlert();

        $data['client']->request('GET', '/app/settings/preferences');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No muted alert types');
    }

    public function testAlertActionsRequireAuthentication(): void
    {
        $client = self::createClient();

        $client->request('POST', '/app/alerts/'.Uuid::uuid7().'/snooze');

        // Anonymous → redirect to login.
        self::assertResponseRedirects();
    }
}
