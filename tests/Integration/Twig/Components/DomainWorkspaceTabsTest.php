<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * One test method per workspace surface: confirms the DomainWorkspaceTabs
 * component renders on the page, marks the correct anchor `tab-active`, and
 * lists every sibling tab so navigation between the six surfaces is reachable.
 *
 * Rendering through the real controllers (not isolated component fixtures)
 * keeps the contract honest — if `Senders` ever silently switches its
 * `{domainId}` param back to `{id}`, this fails immediately.
 */
final class DomainWorkspaceTabsTest extends WebTestCase
{
    private const array TAB_LABELS = ['Overview', 'Reports', 'Senders', 'DNS', 'Blacklist', 'History'];

    #[Test]
    public function overviewSurfaceActivatesOverviewTab(): void
    {
        $this->assertActiveTabOnSurface('/app/domains/%s', 'Overview');
    }

    #[Test]
    public function reportsSurfaceActivatesReportsTab(): void
    {
        $this->assertActiveTabOnSurface('/app/domains/%s/reports', 'Reports');
    }

    #[Test]
    public function sendersSurfaceActivatesSendersTab(): void
    {
        $this->assertActiveTabOnSurface('/app/domains/%s/senders', 'Senders');
    }

    #[Test]
    public function dnsSurfaceActivatesDnsTab(): void
    {
        $this->assertActiveTabOnSurface('/app/domains/%s/health', 'DNS');
    }

    #[Test]
    public function blacklistSurfaceActivatesBlacklistTab(): void
    {
        $this->assertActiveTabOnSurface('/app/domains/%s/blacklist', 'Blacklist');
    }

    #[Test]
    public function historySurfaceActivatesHistoryTab(): void
    {
        $this->assertActiveTabOnSurface('/app/domains/%s/dns-history', 'History');
    }

    private function assertActiveTabOnSurface(string $pathTemplate, string $expectedActiveLabel): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $crawler = $client->request('GET', sprintf($pathTemplate, $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $tablist = $crawler->filter('[role="tablist"]');
        self::assertGreaterThan(0, $tablist->count(), 'Surface must render the DomainWorkspaceTabs role="tablist".');

        $activeTab = $tablist->filter('a.tab.tab-active');
        self::assertGreaterThan(0, $activeTab->count(), 'The active tab must be rendered with `tab-active`.');
        self::assertSame(
            $expectedActiveLabel,
            trim($activeTab->first()->text()),
            sprintf('Expected active tab "%s" on %s.', $expectedActiveLabel, $pathTemplate),
        );

        foreach (self::TAB_LABELS as $label) {
            self::assertStringContainsString(
                $label,
                $tablist->text(),
                sprintf('Workspace tab row must render the "%s" sibling label on every surface.', $label),
            );
        }
    }
}
