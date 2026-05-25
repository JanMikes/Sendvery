<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

/**
 * Component-level coverage for `<twig:NavBadge />`:
 * - hidden when count <= 0
 * - visible with the right tone + count when count > 0
 * - 99+ cap kicks in at counts above 99
 *
 * Plus a regression test that the three sidebar call-sites in
 * `templates/dashboard/layout.html.twig` each render exactly one badge with
 * the right color and aria-label when their respective count is non-zero.
 */
final class NavBadgeTest extends WebTestCase
{
    #[Test]
    public function rendersNothingForZeroCount(): void
    {
        self::createClient();
        $renderer = $this->getService(ComponentRendererInterface::class);

        $html = trim($renderer->createAndRender('NavBadge', [
            'count' => 0,
            'color' => 'warning',
        ]));

        self::assertSame('', $html, 'zero count must render nothing');
    }

    #[Test]
    public function rendersNothingForNegativeCount(): void
    {
        self::createClient();
        $renderer = $this->getService(ComponentRendererInterface::class);

        $html = trim($renderer->createAndRender('NavBadge', [
            'count' => -5,
            'color' => 'warning',
        ]));

        self::assertSame('', $html, 'negative count must render nothing');
    }

    #[Test]
    public function rendersBadgeWithTonalClassAndCount(): void
    {
        self::createClient();
        $renderer = $this->getService(ComponentRendererInterface::class);

        $html = $renderer->createAndRender('NavBadge', [
            'count' => 3,
            'color' => 'error',
            'label' => '3 critical alerts',
        ]);

        self::assertStringContainsString('badge-xs', $html);
        self::assertStringContainsString('badge-error', $html);
        self::assertStringContainsString('ml-auto', $html);
        self::assertStringContainsString('>3<', $html, 'count must render inside the span');
        self::assertStringContainsString('aria-label="3 critical alerts"', $html);
    }

    #[Test]
    public function capsCountAboveNinetyNine(): void
    {
        self::createClient();
        $renderer = $this->getService(ComponentRendererInterface::class);

        $html = $renderer->createAndRender('NavBadge', [
            'count' => 100,
            'color' => 'warning',
        ]);

        self::assertStringContainsString('99+', $html);
        self::assertStringNotContainsString('>100<', $html);
    }

    #[Test]
    public function rendersExactlyNinetyNineWithoutCap(): void
    {
        self::createClient();
        $renderer = $this->getService(ComponentRendererInterface::class);

        $html = $renderer->createAndRender('NavBadge', [
            'count' => 99,
            'color' => 'warning',
        ]);

        self::assertStringContainsString('>99<', $html);
        self::assertStringNotContainsString('99+', $html);
    }

    #[Test]
    public function defaultsToWarningWhenColorOmitted(): void
    {
        self::createClient();
        $renderer = $this->getService(ComponentRendererInterface::class);

        $html = $renderer->createAndRender('NavBadge', [
            'count' => 1,
        ]);

        self::assertStringContainsString('badge-warning', $html);
    }

    #[Test]
    public function sidebarRendersAllThreeBadgesWithCorrectTonesWhenCountsAreNonZero(): void
    {
        // Regression: the three layout.html.twig call sites must each emit
        // exactly one badge with the right color class when their count is > 0.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        $persona = $fixtures->persona()->withoutDomain()->build();

        // 1 critical alert (→ red Alerts badge), 1 unverified domain
        // (→ red Domains badge), 1 quarantined report (→ yellow Quarantine
        // badge). Verified domain so the quarantine count materialises.
        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $verifiedDomain = $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable());
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $this->persistQuarantined($em, $verifiedDomain->domain);
        $em->flush();
        $em->clear();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', '/app');

        self::assertResponseIsSuccessful();

        // The sidebar is rendered by the dashboard layout. Find each nav link
        // by its visible label and assert its descendant badge has the right
        // class + aria-label.
        $domainsLink = $crawler->filter('a[href="/app/domains"]')->first();
        self::assertGreaterThan(0, $domainsLink->count(), 'Domains link must exist');
        $domainsBadge = $domainsLink->filter('span.badge.badge-xs.badge-error');
        self::assertCount(1, $domainsBadge, 'Domains link must have one badge-error span');
        self::assertSame('1 domains awaiting DMARC verification', $domainsBadge->attr('aria-label'));
        self::assertSame('1', trim($domainsBadge->text()));

        $quarantineLink = $crawler->filter('a[href="/app/quarantine"]')->first();
        self::assertGreaterThan(0, $quarantineLink->count(), 'Quarantine link must exist');
        $quarantineBadge = $quarantineLink->filter('span.badge.badge-xs.badge-warning');
        self::assertCount(1, $quarantineBadge, 'Quarantine link must have one badge-warning span');
        self::assertSame('1 reports waiting in quarantine', $quarantineBadge->attr('aria-label'));
        self::assertSame('1', trim($quarantineBadge->text()));

        $alertsLink = $crawler->filter('a[href="/app/alerts"]')->first();
        self::assertGreaterThan(0, $alertsLink->count(), 'Alerts link must exist');
        // Critical count > 0 → red badge wins (not the warning fallback).
        $alertsBadge = $alertsLink->filter('span.badge.badge-xs.badge-error');
        self::assertCount(1, $alertsBadge, 'Alerts link must have one badge-error span when critical count > 0');
        self::assertSame('1 critical unread alerts', $alertsBadge->attr('aria-label'));
        self::assertSame('1', trim($alertsBadge->text()));
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
        $envelope = new \App\Entity\ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: \App\Value\Reports\ReportSource::CentralInbox,
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

        $quarantine = new \App\Entity\QuarantinedDmarcReport(
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
            reason: \App\Value\Reports\QuarantineReason::UnknownDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);
    }
}
