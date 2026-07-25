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
 * Before the FIRST DNS check has completed, the health page and DNS history
 * page must not present "you have no SPF record" / "no record exists yet"
 * claims — we simply haven't looked yet. They render a transparent
 * "DNS check pending" state with a manual check action instead.
 */
final class FirstDnsCheckPendingStateTest extends WebTestCase
{
    #[Test]
    public function healthPageShowsPendingBannerInsteadOfMissingRecordClaimsBeforeFirstCheck(): void
    {
        [$client, $domain] = $this->personaWithDomain();

        $crawler = $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-testid="dns-check-pending-banner"]'),
            'Before the first DNS check the health page must say the check is pending.',
        );
        self::assertCount(
            0,
            $crawler->filter('[data-testid="dns-record-recommendations"]'),
            'Record recommendations must not claim records are missing before any check has run.',
        );
        self::assertStringNotContainsString(
            'Forward DMARC reports to Sendvery',
            (string) $client->getResponse()->getContent(),
            'The DMARC instruction card must not claim "no record exists" before any check has run.',
        );
        self::assertStringContainsString(
            '/reverify',
            (string) $crawler->filter('[data-testid="dns-check-pending-banner"] form')->attr('action'),
            'The pending banner must offer a manual check action.',
        );
    }

    #[Test]
    public function healthPageDropsPendingBannerOnceACheckHasRun(): void
    {
        [$client, $domain, $em] = $this->personaWithDomain();

        $this->persistCheck($em, $domain, DnsCheckType::Spf, null, false);

        $crawler = $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('[data-testid="dns-check-pending-banner"]'),
            'Once a DNS check exists the pending banner must disappear.',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="dns-record-recommendations"]')->count(),
            'With real check data the record recommendations render again.',
        );
    }

    #[Test]
    public function dnsHistoryPageHidesInstructionCardBeforeFirstCheck(): void
    {
        [$client, $domain] = $this->personaWithDomain();

        $client->request('GET', '/app/domains/'.$domain->id->toString().'/dns-history');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(
            'Send DMARC reports to Sendvery',
            $content,
            'The instruction card must not claim "no record exists" before any check has run.',
        );
        self::assertStringContainsString('No DNS checks yet', $content);
        self::assertStringContainsString('Re-check now', $content);
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
