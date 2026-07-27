<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * A domain whose MX records are fine must not be told they are missing just
 * because the nightly health-snapshot cron has not run yet.
 *
 * Reported against a live domain with three resolving MX records: the setup
 * surface said "MX records not detected" all day, because MX state was read off
 * the newest `domain_health_snapshot` (written only by the 03:00 sweep) and MX —
 * unlike SPF, DKIM and DMARC — has no verified-at column to fall back on.
 */
final class MxStateWithoutHealthSnapshotTest extends WebTestCase
{
    #[Test]
    public function aPassingMxCheckReadsAsConfiguredOnTheDomainPageWithNoSnapshotYet(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Deliberately no DomainHealthSnapshot: this is the state of every
        // domain between being added and the next nightly sweep.
        $this->persistCheck($em, $persona, DnsCheckType::Mx, '10 mx1.example.net, 20 mx2.example.net', true);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString(
            'MX records not detected',
            $body,
            'MX records that resolve must never be reported as missing.',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="guided-setup-summary-row-mx"]')->count(),
            'MX belongs in the finished tier, which is rendered as a summary row.',
        );
        self::assertStringContainsString(
            'MX records resolve to your mail provider',
            $body,
            'The row states the good news it actually observed.',
        );
    }

    #[Test]
    public function theSameVerdictShowsOnTheHealthPage(): void
    {
        // The two per-domain DNS surfaces read one model, so a fix on one cannot
        // leave the other lying.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->persistCheck($em, $persona, DnsCheckType::Mx, '10 mx1.example.net', true);

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s/health', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('MX records not detected', $body);
        self::assertStringContainsString('MX records resolve to your mail provider', $body);
    }

    #[Test]
    public function anMxCheckThatFoundNothingStillReportsMxAsMissing(): void
    {
        // The fix must not turn into "always say MX is fine": a check that came
        // back empty is exactly when the user does need to be told.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->persistCheck($em, $persona, DnsCheckType::Mx, null, false);

        $client->loginUser($persona->user);
        $client->request('GET', sprintf('/app/domains/%s/health', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'MX records not detected',
            (string) $client->getResponse()->getContent(),
        );
    }

    private function persistCheck(
        EntityManagerInterface $em,
        Persona $persona,
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
    ): void {
        assert(null !== $persona->domain);

        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: $type,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();
    }
}
