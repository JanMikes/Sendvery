<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
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
 * How the alerts surface presents an alert whose problem got fixed, and how it
 * presents good news (a record going live for the first time). Both wear the
 * green `success` token; neither may look like an open incident.
 */
final class AlertsResolvedRowsTest extends WebTestCase
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
            ->emailPrefix('resolved-row')
            ->build();

        $this->client->loginUser($this->persona->user);
    }

    private function persistAlert(
        string $title,
        AlertType $type = AlertType::DnsRecordInvalid,
        AlertSeverity $severity = AlertSeverity::Critical,
        ?\DateTimeImmutable $resolvedAt = null,
    ): UuidInterface {
        $id = Uuid::uuid7();
        $alert = new Alert(
            id: $id,
            team: $this->persona->team,
            monitoredDomain: $this->persona->domain,
            type: $type,
            severity: $severity,
            title: $title,
            message: 'msg',
            data: [],
            createdAt: new \DateTimeImmutable(),
            resolvedAt: $resolvedAt,
        );
        $alert->popEvents();
        $this->em->persist($alert);
        $this->em->flush();

        return $id;
    }

    #[Test]
    public function aResolvedRowShowsWhenTheFixLanded(): void
    {
        $this->persistAlert(
            'MX is broken for example.com',
            resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'),
        );

        $this->client->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Resolved automatically');
        self::assertSelectorTextContains('body', 'Mar 27, 2026 04:00');
    }

    #[Test]
    public function aResolvedRowIsMarkedWithTheGreenSuccessToken(): void
    {
        $this->persistAlert(
            'MX is broken for example.com',
            resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'),
        );

        $this->client->request('GET', '/app/alerts');

        $body = (string) $this->client->getResponse()->getContent();
        // Semantic token, not styling: "resolved" is green by business rule.
        self::assertStringContainsString('badge-success', $body);
    }

    #[Test]
    public function aResolvedRowDropsTheUnreadDotEvenWhenItWasNeverOpened(): void
    {
        // Resolved alerts are out of every attention count, so an unread dot
        // would ask the user to act on a problem that no longer exists.
        $this->persistAlert(
            'MX is broken for example.com',
            resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'),
        );

        $this->client->request('GET', '/app/alerts');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertSame(0, substr_count($body, 'aria-label="Unread"'));
    }

    #[Test]
    public function aResolvedRowLosesTheAttentionTint(): void
    {
        $this->persistAlert(
            'MX is broken for example.com',
            resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'),
        );

        $this->client->request('GET', '/app/alerts');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(
            'bg-error/5',
            $body,
            'A resolved critical alert must stop shouting through the unread tint.',
        );
    }

    #[Test]
    public function theResolvedFilterPillIsOffered(): void
    {
        $this->persistAlert('MX is broken for example.com');

        $this->client->request('GET', '/app/alerts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Resolved');
    }

    #[Test]
    public function theResolvedFilterNarrowsToJustTheFixedAlerts(): void
    {
        $this->persistAlert('Still broken');
        $this->persistAlert('Already fixed', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'));

        $this->client->request('GET', '/app/alerts?resolved=1');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Already fixed', $body);
        self::assertStringNotContainsString('Still broken', $body);
    }

    #[Test]
    public function theOpenOnlyFilterHidesTheFixedAlerts(): void
    {
        // `?resolved=0` is the honest inverse of the Resolved pill — the way to
        // read the list with the already-fixed noise stripped out.
        $this->persistAlert('Still broken');
        $this->persistAlert('Already fixed', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'));

        $this->client->request('GET', '/app/alerts?resolved=0');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Still broken', $body);
        self::assertStringNotContainsString('Already fixed', $body);
    }

    #[Test]
    public function goodNewsAlertsAreFindableThroughTheirOwnFilterPill(): void
    {
        $this->persistAlert(
            'DKIM record published for example.com',
            type: AlertType::DnsRecordPublished,
            severity: AlertSeverity::Success,
        );

        $this->client->request('GET', '/app/alerts?severity=success');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'DKIM record published for example.com');
        self::assertSelectorTextContains('body', 'Good news');
    }

    #[Test]
    public function aGoodNewsRowIsGreenNotYellow(): void
    {
        $this->persistAlert(
            'DKIM record published for example.com',
            type: AlertType::DnsRecordPublished,
            severity: AlertSeverity::Success,
        );

        $this->client->request('GET', '/app/alerts');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('bg-success/5', $body, 'An unread good-news row carries the green tint.');
        self::assertStringNotContainsString('bg-warning/5', $body, 'Good news must never wear the yellow "review this" tint.');
    }

    #[Test]
    public function theUnreadFilterHidesAlreadyOpenedAlerts(): void
    {
        $this->persistAlert('Unopened');
        $opened = $this->persistAlert('Opened');
        $alert = $this->em->find(Alert::class, $opened);
        self::assertNotNull($alert);
        $alert->markAsRead();
        $this->em->flush();

        $this->client->request('GET', '/app/alerts?read=false');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Unopened', $body);
        self::assertStringNotContainsString('>Opened<', $body);
    }

    #[Test]
    public function theReadFilterShowsOnlyAlreadyOpenedAlerts(): void
    {
        $this->persistAlert('Unopened');
        $opened = $this->persistAlert('Opened');
        $alert = $this->em->find(Alert::class, $opened);
        self::assertNotNull($alert);
        $alert->markAsRead();
        $this->em->flush();

        $this->client->request('GET', '/app/alerts?read=true');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Opened', $body);
        self::assertStringNotContainsString('Unopened', $body);
    }

    #[Test]
    public function anUnrecognisedSeverityFilterFallsBackToTheFullListInsteadOfErroring(): void
    {
        $this->persistAlert('MX is broken for example.com');

        $this->client->request('GET', '/app/alerts?severity=bogus');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'MX is broken for example.com');
    }

    #[Test]
    public function theAlertDetailPageSaysThereIsNothingLeftToDo(): void
    {
        $id = $this->persistAlert(
            'MX is broken for example.com',
            resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'),
        );

        $this->client->request('GET', '/app/alerts/'.$id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'resolved automatically');
        self::assertSelectorTextContains('body', 'Nothing left to do.');
    }

    #[Test]
    public function theAlertDetailPageLabelsAGoodNewsAlertAsSuch(): void
    {
        $id = $this->persistAlert(
            'DKIM record published for example.com',
            type: AlertType::DnsRecordPublished,
            severity: AlertSeverity::Success,
        );

        $this->client->request('GET', '/app/alerts/'.$id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Good news');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('badge-success', $body);
    }
}
