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
 * TASK-125 + TASK-126.
 *
 * - TASK-125: the very first `dns_check_result` row per (domain, type)
 *   renders as an `INITIAL CHECK` baseline badge instead of `CHANGED`.
 *   A baseline is not a change, so promoting it as one erodes trust the
 *   first day a domain is added.
 * - TASK-126: the per-row record-type label (SPF / DKIM / DMARC / MX)
 *   renders in a single neutral tone (`badge-neutral badge-outline`)
 *   with a protocol icon prefix, so it does not collide with the
 *   semantic validity-state palette (`badge-success` / `badge-error`).
 */
final class DnsHistoryInitialAndLabelsTest extends WebTestCase
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
        string $rawRecord,
        bool $isValid,
        bool $hasChanged,
        ?string $previousRawRecord = null,
    ): void {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: $checkedAt,
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: $previousRawRecord,
            hasChanged: $hasChanged,
            isFirstCheck: null === $previousRawRecord,
        );
        $check->popEvents();
        $em->persist($check);
    }

    #[Test]
    public function dayZeroRendersInitialCheckAndDayOneRendersChanged(): void
    {
        $data = $this->bootClientWithDomain('initial-vs-changed');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Day 0 (older): the very first DMARC observation for this domain.
        // The DnsMonitor pipeline persists this with `hasChanged=true` because
        // the previous raw record is NULL and `null !== 'v=DMARC1; p=none'`
        // — that's the exact trust-eroding path TASK-125 fixes.
        $this->seedCheck(
            $em,
            $data['domain'],
            DnsCheckType::Dmarc,
            new \DateTimeImmutable('-2 days 09:00:00'),
            'v=DMARC1; p=none; rua=mailto:reports@example.com',
            isValid: true,
            hasChanged: true,
            previousRawRecord: null,
        );
        // Day 1 (newer): a real DMARC `p=` flip.
        $this->seedCheck(
            $em,
            $data['domain'],
            DnsCheckType::Dmarc,
            new \DateTimeImmutable('-1 day 09:00:00'),
            'v=DMARC1; p=quarantine; rua=mailto:reports@example.com',
            isValid: true,
            hasChanged: true,
            previousRawRecord: 'v=DMARC1; p=none; rua=mailto:reports@example.com',
        );
        $em->flush();

        $data['client']->request(
            'GET',
            '/app/domains/'.$data['domain']->id->toString().'/dns-history',
        );

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Day 0 row must render the INITIAL CHECK badge — distinct, non-warning tone.
        self::assertStringContainsString('>Initial check<', $body);
        self::assertSame(
            1,
            substr_count($body, '>Initial check<'),
            'Exactly one INITIAL CHECK badge: the oldest row per (domain, type).',
        );

        // Day 1 row keeps the CHANGED badge.
        self::assertStringContainsString('>Changed<', $body);
        self::assertSame(
            1,
            substr_count($body, '>Changed<'),
            'Only the second observation is a real change.',
        );
    }

    #[Test]
    public function initialCheckBadgeUsesInfoToneNotWarning(): void
    {
        $data = $this->bootClientWithDomain('initial-tone');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->seedCheck(
            $em,
            $data['domain'],
            DnsCheckType::Spf,
            new \DateTimeImmutable('-1 day'),
            'v=spf1 include:_spf.example.com ~all',
            isValid: true,
            hasChanged: true,
            previousRawRecord: null,
        );
        $em->flush();

        $data['client']->request(
            'GET',
            '/app/domains/'.$data['domain']->id->toString().'/dns-history',
        );

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // The Initial check badge must use an info tone (badge-info), not the
        // warning palette CHANGED uses — that's the whole point of the split.
        self::assertMatchesRegularExpression(
            '/<span class="[^"]*badge-info[^"]*">Initial check<\/span>/',
            $body,
            'INITIAL CHECK badge must carry the info tone, distinct from CHANGED.',
        );
        // And we must NOT render the CHANGED badge for the baseline row.
        self::assertStringNotContainsString('>Changed<', $body);
    }

    #[Test]
    public function initialCheckRowStillShowsBaselineRecord(): void
    {
        $data = $this->bootClientWithDomain('initial-baseline');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $baseline = 'v=DMARC1; p=none; rua=mailto:reports@example.com';
        $this->seedCheck(
            $em,
            $data['domain'],
            DnsCheckType::Dmarc,
            new \DateTimeImmutable('-1 day'),
            $baseline,
            isValid: true,
            hasChanged: true,
            previousRawRecord: null,
        );
        $em->flush();

        $data['client']->request(
            'GET',
            '/app/domains/'.$data['domain']->id->toString().'/dns-history',
        );

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // The baseline state is still shown so the user sees what's there.
        self::assertStringContainsString($baseline, $body);
    }

    #[Test]
    public function recordTypeLabelsAreNeutralToneAndValidityKeepsSemantic(): void
    {
        $data = $this->bootClientWithDomain('neutral-labels');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // One row per protocol, mixed validity states. checked_at staggered
        // by hour so they all group into one day and we can scan a single
        // rendered region.
        $base = new \DateTimeImmutable('-1 day 09:00:00');
        $this->seedCheck($em, $data['domain'], DnsCheckType::Spf, $base, 'v=spf1 -all', isValid: true, hasChanged: false);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Dkim, $base->modify('+1 hour'), 'v=DKIM1; k=rsa; p=AAAA', isValid: false, hasChanged: false);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Dmarc, $base->modify('+2 hours'), 'v=DMARC1; p=reject', isValid: true, hasChanged: false);
        $this->seedCheck($em, $data['domain'], DnsCheckType::Mx, $base->modify('+3 hours'), 'aspmx.l.google.com', isValid: false, hasChanged: false);
        $em->flush();

        $data['client']->request(
            'GET',
            '/app/domains/'.$data['domain']->id->toString().'/dns-history',
        );

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Slice off the filter chips region — those buttons render protocol
        // names too but in `btn` chrome, not row badges.
        $detailsStart = strpos($body, '<details class="card');
        self::assertNotFalse($detailsStart);
        $rows = substr($body, $detailsStart);

        // Each protocol label is in the unified neutral palette with the same
        // class set — no `badge-primary` / `badge-secondary` / `badge-accent`
        // record-type labels anywhere in the results region.
        foreach (['SPF', 'DKIM', 'DMARC', 'MX'] as $protocol) {
            self::assertMatchesRegularExpression(
                '/<span class="[^"]*badge-neutral[^"]*badge-outline[^"]*">\s*<svg[^>]*>.*?<\/svg>\s*'.preg_quote($protocol, '/').'\s*<\/span>/s',
                $rows,
                sprintf('Protocol label %s must render in the neutral palette with an icon prefix.', $protocol),
            );
        }

        // Validity badges keep semantic tones — 2 valid (SPF, DMARC) and 2 invalid (DKIM, MX).
        self::assertSame(2, substr_count($rows, 'badge-success badge-outline'));
        self::assertSame(2, substr_count($rows, 'badge-error badge-outline'));

        // Regression guard: the old semantic record-type tones must be gone.
        self::assertStringNotContainsString('badge-primary">', $rows);
        self::assertStringNotContainsString('badge-secondary">', $rows);
        self::assertStringNotContainsString('badge-accent">', $rows);
    }

    #[Test]
    public function changesOnlyFilterHidesInitialCheckRows(): void
    {
        $data = $this->bootClientWithDomain('changes-only-no-initial');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // A lone DMARC row — baseline only, no real changes ever.
        $this->seedCheck(
            $em,
            $data['domain'],
            DnsCheckType::Dmarc,
            new \DateTimeImmutable('-1 day'),
            'v=DMARC1; p=none',
            isValid: true,
            hasChanged: true,
            previousRawRecord: null,
        );
        $em->flush();

        $data['client']->request(
            'GET',
            '/app/domains/'.$data['domain']->id->toString().'/dns-history?changes_only=1',
        );

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // No real changes ever happened — the empty-state must render and
        // the INITIAL CHECK badge must NOT appear in the changes-only view.
        self::assertStringContainsString('No DNS checks match the current filter', $body);
        self::assertStringNotContainsString('>Initial check<', $body);
        self::assertStringNotContainsString('>Changed<', $body);
        // Counter in the chip itself reads "Show only changes (0)" — the
        // baseline isn't counted as a change.
        self::assertStringContainsString('Show only changes (0)', $body);
    }
}
