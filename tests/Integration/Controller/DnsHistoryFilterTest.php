<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * TASK-081: DNS History page lede + filter chips + per-day expander pattern.
 *
 * Covers the three filter axes (type, range, changes_only), the per-day
 * `<details>` grouping, the two distinct empty states (zero history vs
 * filtered to zero), and the "Clear filters" reset link.
 */
final class DnsHistoryFilterTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domain: MonitoredDomain}
     */
    private function bootClientWithDomain(string $prefix): array
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($prefix.'-'.substr(uniqid('', true), -6))
            ->teamName('DNS History '.$prefix)
            ->build();

        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        return ['client' => $client, 'domain' => $persona->domain];
    }

    private function seedCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        DnsCheckType $type,
        \DateTimeImmutable $checkedAt,
        bool $hasChanged,
    ): void {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: $checkedAt,
            rawRecord: 'v=spf1 include:example.com ~all',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: $hasChanged ? 'v=spf1 ~all' : null,
            hasChanged: $hasChanged,
            isFirstCheck: false,
        );
        $check->popEvents();
        $em->persist($check);
    }

    #[Test]
    public function ledeAndChipsRenderOnDefaultView(): void
    {
        $data = $this->bootClientWithDomain('lede');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Chips are only rendered once we have at least one historical row —
        // otherwise the "No DNS checks yet" empty-state owns the entire view.
        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, new \DateTimeImmutable('-2 hours'), hasChanged: false);
        $em->flush();

        $data['client']->request('GET', '/app/domains/'.$data['domain']->id->toString().'/dns-history');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString("Every DNS check we've run for", $body);
        self::assertStringContainsString($data['domain']->domain, $body);
        // Chip text renders with surrounding whitespace from template
        // indentation — assert on the labels themselves, not exact HTML.
        self::assertStringContainsString('All', $body);
        self::assertStringContainsString('Last 30 days', $body);
        self::assertStringContainsString('Show only changes', $body);
    }

    #[Test]
    public function changesOnlyFilterShowsOnlyChangedRows(): void
    {
        $data = $this->bootClientWithDomain('changes-only');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $now = new \DateTimeImmutable('-1 hour');
        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, $now, hasChanged: true);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Dkim, $now->modify('-1 hour'), hasChanged: true);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Dmarc, $now->modify('-2 hours'), hasChanged: false);
        $em->flush();

        $data['client']->request('GET', '/app/domains/'.$data['domain']->id->toString().'/dns-history?changes_only=1');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Each persisted changed row renders one "Changed" badge inside its
        // row block. Two seeded changed rows = two "Changed" badges.
        self::assertSame(2, substr_count($body, '>Changed<'));
    }

    #[Test]
    public function typeFilterScopesToSingleType(): void
    {
        $data = $this->bootClientWithDomain('type-filter');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $now = new \DateTimeImmutable('-1 hour');
        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, $now, hasChanged: false);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Dkim, $now->modify('-1 hour'), hasChanged: false);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Dmarc, $now->modify('-2 hours'), hasChanged: false);
        $em->flush();

        $data['client']->request('GET', '/app/domains/'.$data['domain']->id->toString().'/dns-history?type=spf');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Extract just the per-day details blocks (where check type badges live)
        // by slicing from the first per-day <details> onward — this excludes
        // chip buttons in the filter card which always render all type labels.
        $detailsStart = strpos($body, '<details class="card');
        self::assertNotFalse($detailsStart, 'Expected per-day <details> blocks for the SPF filter.');
        $resultsRegion = substr($body, $detailsStart);
        // Type badges render the label with surrounding whitespace from Twig
        // indentation; normalise whitespace before substring matching.
        $normalised = (string) preg_replace('/\s+/', ' ', $resultsRegion);
        self::assertStringContainsString('> SPF <', $normalised);
        self::assertStringNotContainsString('> DKIM <', $normalised);
        self::assertStringNotContainsString('> DMARC <', $normalised);
    }

    #[Test]
    public function perDayDetailsGroupsByDate(): void
    {
        $data = $this->bootClientWithDomain('per-day');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, new \DateTimeImmutable('-1 day 10:00:00'), hasChanged: false);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Dkim, new \DateTimeImmutable('-2 days 10:00:00'), hasChanged: false);
        $em->flush();

        $data['client']->request('GET', '/app/domains/'.$data['domain']->id->toString().'/dns-history');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // The page renders one `<details class="card …">` per calendar day.
        // The layout-level dropdown uses a different class shape, so we match
        // the per-day wrapper specifically rather than all `<details>` tags.
        self::assertSame(2, substr_count($body, '<details class="card'));
    }

    #[Test]
    public function filteredEmptyStateRenders(): void
    {
        $data = $this->bootClientWithDomain('filtered-empty');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $now = new \DateTimeImmutable('-1 hour');
        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, $now, hasChanged: false);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, $now->modify('-1 hour'), hasChanged: false);
        $em->flush();

        $data['client']->request('GET', '/app/domains/'.$data['domain']->id->toString().'/dns-history?type=mx');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('No DNS checks match the current filter', $body);
        self::assertStringContainsString('Clear filters', $body);
    }

    #[Test]
    public function zeroHistoryEmptyStateRenders(): void
    {
        $data = $this->bootClientWithDomain('zero-history');

        $data['client']->request('GET', '/app/domains/'.$data['domain']->id->toString().'/dns-history');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('No DNS checks yet', $body);
        self::assertStringContainsString('Re-check now', $body);
    }

    #[Test]
    public function rangeFilterScopesToDateWindow(): void
    {
        $data = $this->bootClientWithDomain('range-filter');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, new \DateTimeImmutable('-5 days'), hasChanged: false);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Dkim, new \DateTimeImmutable('-40 days'), hasChanged: false);
        $em->flush();

        $data['client']->request('GET', '/app/domains/'.$data['domain']->id->toString().'/dns-history?range=7');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Look for type badges inside the per-day result region only.
        $detailsStart = strpos($body, '<details class="card');
        self::assertNotFalse($detailsStart, 'Expected at least one per-day <details> block for the recent SPF row.');
        $resultsRegion = substr($body, $detailsStart);
        $normalised = (string) preg_replace('/\s+/', ' ', $resultsRegion);
        self::assertStringContainsString('> SPF <', $normalised);
        self::assertStringNotContainsString('> DKIM <', $normalised);
    }

    #[Test]
    public function clearFiltersLinkResetsToDefaultRoute(): void
    {
        $data = $this->bootClientWithDomain('clear-link');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Need at least one row so `hasAnyHistory === true` and we hit the
        // "filtered to zero" branch (which renders Clear filters), not the
        // "no history ever" branch.
        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, new \DateTimeImmutable('-2 days'), hasChanged: false);
        $em->flush();

        $domainId = $data['domain']->id->toString();
        $data['client']->request('GET', '/app/domains/'.$domainId.'/dns-history?type=spf&range=7&changes_only=1');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Clear filters', $body);
        self::assertStringContainsString('href="/app/domains/'.$domainId.'/dns-history"', $body);
    }
}
