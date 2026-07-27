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
 * The other half of {@see BlacklistNeverCheckedHonestyTest}.
 *
 * That test stops the product publishing a clean blacklist verdict it never
 * measured. This one stops the opposite failure, which shipping the checker
 * introduced: the health surfaces hard-coded the words "Not checked" as literal
 * text, so once `sendvery:blacklist:check-all` began producing real results the
 * pages would have gone on reporting "not checked" forever — a paid feature
 * running nightly and telling every customer it had never run.
 *
 * Honest reporting has to move in both directions or it is not reporting.
 */
final class MeasuredBlacklistIsShownTest extends WebTestCase
{
    #[Test]
    public function aCleanMeasuredResultIsPublishedAsAMeasurement(): void
    {
        [$client, $domain, $em] = $this->personaWithDomain();
        $shareHash = $this->snapshot($em, $domain, blacklistScore: 100);

        $crawler = $client->request('GET', '/health/'.$shareHash);

        self::assertResponseIsSuccessful();

        self::assertCount(
            5,
            $crawler->filter('progress'),
            'Once a blacklist check has actually run, its result is a measurement like any other and earns a scored bar.',
        );
        self::assertStringNotContainsString(
            'Not checked',
            (string) $client->getResponse()->getContent(),
            'Saying "not checked" about a check that ran is the same class of untruth as claiming a check that did not.',
        );
    }

    #[Test]
    public function aListingIsPublishedRatherThanHidden(): void
    {
        [$client, $domain, $em] = $this->personaWithDomain();
        $shareHash = $this->snapshot($em, $domain, blacklistScore: 0);

        $crawler = $client->request('GET', '/health/'.$shareHash);

        self::assertResponseIsSuccessful();

        // Asserted through the scored bars rather than the word "Blacklist",
        // which appears on the page either way and would pass this test even
        // if the listing were dropped entirely.
        $values = $crawler->filter('progress')->each(
            static fn ($node): string => (string) $node->attr('value'),
        );

        self::assertContains(
            '0',
            $values,
            'A domain that IS listed must see the zero. Suppressing a bad measured result would make the feature worthless in exactly the case it exists for.',
        );
    }

    #[Test]
    public function theDashboardHealthPageAlsoStopsSayingNotCheckedOnceItHas(): void
    {
        [$client, $domain, $em] = $this->personaWithDomain();
        $this->snapshot($em, $domain, blacklistScore: 100);

        $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'Not checked yet',
            (string) $client->getResponse()->getContent(),
            'The signed-in health page must agree with the public one about whether a check has run.',
        );
    }

    /**
     * @return array{0: KernelBrowser, 1: MonitoredDomain, 2: EntityManagerInterface}
     */
    private function personaWithDomain(): array
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $domain = $persona->domain;
        assert($domain instanceof MonitoredDomain);

        return [$client, $domain, $this->getService(EntityManagerInterface::class)];
    }

    private function snapshot(EntityManagerInterface $em, MonitoredDomain $domain, ?int $blacklistScore): string
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
            blacklistScore: $blacklistScore,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: $shareHash,
        ));
        $em->flush();

        return $shareHash;
    }
}
