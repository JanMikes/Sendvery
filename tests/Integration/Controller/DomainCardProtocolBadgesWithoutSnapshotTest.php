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
 * The SPF/DKIM/DMARC/MX badges on each `/app/domains` card must speak from the
 * stored `dns_check_result` rows — the only source every check path writes.
 *
 * They used to read the `*_verified_at` columns, which lie in both directions.
 * Those columns are booleans-by-absence, so a domain whose first check had not
 * landed showed three red "not verified" badges about records nobody had looked
 * at; and `CheckDomainDnsHandler` only ever SETS them, never clears them, so a
 * domain whose SPF record was later deleted kept a green "SPF verified" badge
 * indefinitely.
 */
final class DomainCardProtocolBadgesWithoutSnapshotTest extends WebTestCase
{
    #[Test]
    public function noProtocolBadgeIsShownForADomainWhoseDnsHasNeverBeenChecked(): void
    {
        [$client] = $this->personaWithDomain();

        $crawler = $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('[aria-label="SPF no record published"], [aria-label="SPF present but failing checks"]'),
            'Before the first DNS check we have not looked at SPF, so the card must not claim the record is absent or broken.',
        );
        self::assertCount(
            0,
            $crawler->filter('[aria-label="DMARC no record published"], [aria-label="DMARC present but failing checks"]'),
            'Same for DMARC — "not checked yet" is not "not published".',
        );
    }

    #[Test]
    public function aVerifiedTimestampDoesNotKeepTheBadgeGreenAfterTheRecordBreaks(): void
    {
        [$client, $domain, $em] = $this->personaWithDomain();

        // The state a real regression leaves behind: the domain verified once,
        // so spf_verified_at is set and is never cleared, but the newest check
        // found no record at all.
        $domain->spfVerifiedAt = new \DateTimeImmutable('-30 days');
        $em->flush();
        $this->persistCheck($em, $domain, DnsCheckType::Spf, null, false);

        $crawler = $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('[aria-label="SPF verified"]'),
            'A domain whose SPF record has been removed must not still be badged as verified just because it verified once.',
        );
        self::assertCount(
            1,
            $crawler->filter('[aria-label="SPF no record published"]'),
            'The latest check found nothing, so the card must say the record needs publishing.',
        );
    }

    #[Test]
    public function aPassingCheckIsBadgedAsVerified(): void
    {
        [$client, $domain, $em] = $this->personaWithDomain();

        $this->persistCheck($em, $domain, DnsCheckType::Spf, 'v=spf1 -all', true);

        $crawler = $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[aria-label="SPF verified"]'),
            'A passing check must still render the green verified badge.',
        );
    }

    /**
     * @return array{0: KernelBrowser, 1: MonitoredDomain, 2: EntityManagerInterface}
     */
    private function personaWithDomain(): array
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->build();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        assert(null !== $domain);

        $client->loginUser($persona->user);

        return [$client, $domain, $em];
    }

    private function persistCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
    ): void {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();
    }
}
