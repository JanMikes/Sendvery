<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * No production code path ever runs a blacklist check — nothing dispatches
 * `CheckBlacklist`, and `HealthSnapshotComposer` hardcodes `blacklistScore` to
 * 100 on every snapshot it writes. Presenting that constant as a measured
 * category gave every domain a clean bill of health it has not earned, most
 * damagingly on the unauthenticated `/health/{hash}` share page where a green
 * "Blacklist 100%" bar sits next to real SPF/DKIM/DMARC/MX results.
 *
 * These surfaces must say "not checked" instead. (The 20% weight the constant
 * still carries in the overall score is a separate, deliberate decision — see
 * the audit notes; changing it would silently regrade every existing domain.)
 */
final class BlacklistNeverCheckedHonestyTest extends WebTestCase
{
    #[Test]
    public function publicSharePageDoesNotPublishAnUnearnedCleanBlacklistScore(): void
    {
        [$client, $domain, $em] = $this->personaWithDomain();
        $shareHash = $this->snapshot($em, $domain);

        $crawler = $client->request('GET', '/health/'.$shareHash);

        self::assertResponseIsSuccessful();

        self::assertCount(
            4,
            $crawler->filter('progress'),
            'Only the four genuinely measured categories (SPF, DKIM, DMARC, MX) may render a scored bar. A fifth bar means the hardcoded blacklist constant is being published as a measurement.',
        );
        self::assertStringContainsString(
            'Not checked',
            (string) $client->getResponse()->getContent(),
            'The public health report must state that blacklist status is unknown rather than implying a clean result.',
        );
    }

    #[Test]
    public function domainHealthPageLabelsBlacklistAsNotCheckedYet(): void
    {
        [$client, $domain, $em] = $this->personaWithDomain();
        $this->snapshot($em, $domain);

        $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            'Not checked yet',
            $content,
            'The category list must not show a blacklist percentage nobody measured.',
        );
    }

    #[Test]
    public function blacklistTabDoesNotPromiseChecksThatNeverRun(): void
    {
        [$client, $domain] = $this->personaWithDomain();

        $client->request('GET', '/app/domains/'.$domain->id->toString().'/blacklist');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString(
            'Checks will run automatically',
            $content,
            'Nothing schedules blacklist checks today, so the empty state must not promise that they will run.',
        );
        self::assertStringContainsString(
            'not switched on yet',
            $content,
            'An empty list must read as "not checked", not as "not listed".',
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

    private function snapshot(EntityManagerInterface $em, MonitoredDomain $domain): string
    {
        $shareHash = bin2hex(random_bytes(16));
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 100,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: $shareHash,
        ));
        $em->flush();

        return $shareHash;
    }
}
