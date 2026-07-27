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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The bulk-action panel on /app/alerts is always visible. A panel that only
 * appeared after the first checkbox click hid the fact that bulk actions exist
 * at all — but a visible panel whose buttons act on an empty selection would be
 * a trap, so the selection-scoped buttons start disabled while the team-wide
 * "Mark all as read" is always available.
 */
final class AlertsBulkToolbarTest extends WebTestCase
{
    private KernelBrowser $client;

    private Persona $persona;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->persona = TestFixtures::fromContainer(self::getContainer())
            ->persona()
            ->emailPrefix('toolbar')
            ->build();

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $this->persona->team,
            monitoredDomain: $this->persona->domain,
            type: AlertType::DnsRecordInvalid,
            severity: AlertSeverity::Critical,
            title: 'MX is broken',
            message: 'msg',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();

        $this->client->loginUser($this->persona->user);
    }

    private function loadAlerts(): Crawler
    {
        $crawler = $this->client->request('GET', '/app/alerts');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[Test]
    public function thePanelIsVisibleBeforeAnythingIsSelected(): void
    {
        $crawler = $this->loadAlerts();

        $toolbar = $crawler->filter('[data-alert-selection-target="toolbar"]');
        self::assertCount(1, $toolbar);

        $classes = preg_split('/\s+/', trim((string) $toolbar->attr('class'))) ?: [];
        self::assertNotContains(
            'hidden',
            $classes,
            'The bulk panel must be on screen from the first render, not revealed by the first checkbox click.',
        );
    }

    #[Test]
    public function thePanelStartsByReportingAnEmptySelection(): void
    {
        $crawler = $this->loadAlerts();

        self::assertSame('0 selected', trim($crawler->filter('[data-alert-selection-target="count"]')->text()));
    }

    #[Test]
    public function theSelectionScopedButtonsAreDisabledWhileNothingIsSelected(): void
    {
        $crawler = $this->loadAlerts();

        self::assertCount(
            2,
            $crawler->filter('[data-alert-selection-target="scoped"]'),
            'Both "mark selected" and "snooze selected" act on the selection.',
        );
        self::assertCount(
            2,
            $crawler->filter('[data-alert-selection-target="scoped"][disabled]'),
            'A visible button that would submit an empty selection is a trap — it must render disabled.',
        );
    }

    #[Test]
    public function markAllAsReadNeedsNoSelectionAndIsNeverDisabled(): void
    {
        $crawler = $this->loadAlerts();

        self::assertCount(1, $crawler->filter('button[name="action"][value="mark_all_read"]'));
        self::assertCount(
            0,
            $crawler->filter('button[name="action"][value="mark_all_read"][disabled]'),
            '"Mark all as read" is team-wide, so it must stay clickable with nothing selected.',
        );
    }

    #[Test]
    public function aSelectAllCheckboxIsOfferedInThePanel(): void
    {
        $crawler = $this->loadAlerts();

        self::assertCount(1, $crawler->filter('[data-alert-selection-target="selectAll"]'));
    }
}
