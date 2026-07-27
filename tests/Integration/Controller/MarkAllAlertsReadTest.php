<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Mark all as read" clears the whole unread backlog of the active team, not
 * just the (at most 50) rows the list can display — and it must work without
 * anything being selected.
 */
final class MarkAllAlertsReadTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Persona $persona;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->persona = TestFixtures::fromContainer(self::getContainer())
            ->persona()
            ->emailPrefix('mark-all')
            ->build();

        $this->client->loginUser($this->persona->user);
    }

    /**
     * Bootstrap a CSRF token inside the authenticated session — same trick as
     * {@see AlertActionsTest::csrfToken()}.
     */
    private function csrfToken(string $id): string
    {
        $this->client->request('GET', '/app/alerts');

        $cookie = $this->client->getCookieJar()->get('MOCKSESSID') ?? $this->client->getCookieJar()->get('PHPSESSID');
        self::assertNotNull($cookie, 'Session cookie not set after warm-up GET.');

        $token = bin2hex(random_bytes(16));

        $factory = self::getContainer()->get('session.factory');
        assert($factory instanceof \Symfony\Component\HttpFoundation\Session\SessionFactoryInterface);

        $session = $factory->createSession();
        $session->setId($cookie->getValue());
        $session->start();
        $session->set('_csrf/'.$id, $token);
        $session->save();

        return $token;
    }

    private function persistAlert(
        Team $team,
        ?MonitoredDomain $domain,
        string $title,
        bool $isRead = false,
        ?\DateTimeImmutable $resolvedAt = null,
        ?\DateTimeImmutable $snoozedUntil = null,
    ): UuidInterface {
        $id = Uuid::uuid7();
        $alert = new Alert(
            id: $id,
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::DnsRecordInvalid,
            severity: AlertSeverity::Warning,
            title: $title,
            message: 'msg',
            data: [],
            createdAt: new \DateTimeImmutable(),
            isRead: $isRead,
            snoozedUntil: $snoozedUntil,
            resolvedAt: $resolvedAt,
        );
        $alert->popEvents();
        $this->em->persist($alert);
        $this->em->flush();

        return $id;
    }

    private function reload(UuidInterface $id): Alert
    {
        $alert = $this->em->find(Alert::class, $id);
        self::assertNotNull($alert);

        return $alert;
    }

    #[Test]
    public function marksEveryUnreadAlertOfTheTeamWithoutAnySelection(): void
    {
        $first = $this->persistAlert($this->persona->team, $this->persona->domain, 'First');
        $second = $this->persistAlert($this->persona->team, $this->persona->domain, 'Second');
        $snoozed = $this->persistAlert(
            $this->persona->team,
            $this->persona->domain,
            'Snoozed',
            snoozedUntil: new \DateTimeImmutable('+7 days'),
        );

        $token = $this->csrfToken('bulk_alert_action');

        $this->client->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_all_read',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/alerts');

        $this->em->clear();
        self::assertTrue($this->reload($first)->isRead);
        self::assertTrue($this->reload($second)->isRead);
        self::assertTrue($this->reload($snoozed)->isRead, 'Snooze is an independent axis — a deferred alert still gets its read flag flipped.');
    }

    #[Test]
    public function reachesAlertsBeyondTheFiftyRowListCap(): void
    {
        // The list is capped at LIMIT 50, so a per-row bulk action can never
        // reach the tail of a busy backlog. This is the whole point of the
        // team-wide action.
        $ids = [];
        for ($i = 0; $i < 55; ++$i) {
            $ids[] = $this->persistAlert($this->persona->team, $this->persona->domain, 'Backlog '.$i);
        }

        $token = $this->csrfToken('bulk_alert_action');

        $this->client->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_all_read',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/alerts');

        $this->em->clear();
        $stillUnread = array_filter($ids, fn (UuidInterface $id): bool => !$this->reload($id)->isRead);
        self::assertSame([], $stillUnread, 'Every alert of the team must be marked read, not just the first page.');
    }

    #[Test]
    public function theFlashReportsHowManyAlertsWereActuallyAffected(): void
    {
        // Two unread + one already-read + one resolved: only the two unread
        // ones change, so that is the number the user must be told.
        $this->persistAlert($this->persona->team, $this->persona->domain, 'Unread one');
        $this->persistAlert($this->persona->team, $this->persona->domain, 'Unread two');
        $this->persistAlert($this->persona->team, $this->persona->domain, 'Already read', isRead: true);
        $this->persistAlert(
            $this->persona->team,
            $this->persona->domain,
            'Resolved',
            resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'),
        );

        $token = $this->csrfToken('bulk_alert_action');

        $this->client->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_all_read',
            '_csrf_token' => $token,
        ]);

        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Marked 2 alerts as read.');
    }

    #[Test]
    public function theFlashUsesTheSingularFormForASingleAlert(): void
    {
        $this->persistAlert($this->persona->team, $this->persona->domain, 'Only one');

        $token = $this->csrfToken('bulk_alert_action');

        $this->client->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_all_read',
            '_csrf_token' => $token,
        ]);

        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Marked 1 alert as read.');
    }

    #[Test]
    public function anEmptyBacklogSaysSoInsteadOfClaimingZeroAlertsWereMarked(): void
    {
        $this->persistAlert($this->persona->team, $this->persona->domain, 'Already read', isRead: true);

        $token = $this->csrfToken('bulk_alert_action');

        $this->client->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_all_read',
            '_csrf_token' => $token,
        ]);

        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'No unread alerts to mark.');
    }

    #[Test]
    public function anAlreadyResolvedAlertIsLeftUnread(): void
    {
        // Resolved alerts are already out of every attention count, so there is
        // nothing to clear — touching them would rewrite history for no gain.
        $resolved = $this->persistAlert(
            $this->persona->team,
            $this->persona->domain,
            'Resolved',
            resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'),
        );

        $token = $this->csrfToken('bulk_alert_action');

        $this->client->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_all_read',
            '_csrf_token' => $token,
        ]);

        $this->em->clear();
        self::assertFalse($this->reload($resolved)->isRead);
    }

    #[Test]
    public function anotherTeamsBacklogIsNeverTouched(): void
    {
        $foreign = TestFixtures::fromContainer(self::getContainer())
            ->persona()
            ->emailPrefix('mark-all-foreign')
            ->build();

        $ours = $this->persistAlert($this->persona->team, $this->persona->domain, 'Ours');
        $theirs = $this->persistAlert($foreign->team, $foreign->domain, 'Theirs');

        $token = $this->csrfToken('bulk_alert_action');

        $this->client->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_all_read',
            '_csrf_token' => $token,
        ]);

        $this->em->clear();
        self::assertTrue($this->reload($ours)->isRead);
        self::assertFalse($this->reload($theirs)->isRead, 'Mark-all must stay scoped to the active team.');
    }

    #[Test]
    public function requiresAValidCsrfToken(): void
    {
        $unread = $this->persistAlert($this->persona->team, $this->persona->domain, 'Unread');

        $this->client->request('POST', '/app/alerts/bulk', [
            'action' => 'mark_all_read',
        ]);

        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertFalse($this->reload($unread)->isRead);
    }
}
