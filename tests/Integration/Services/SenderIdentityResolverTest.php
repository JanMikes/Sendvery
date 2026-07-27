<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\SenderIdentity;
use App\Repository\SenderIdentityRepository;
use App\Services\Dns\FakeReverseDnsResolver;
use App\Services\IdentityProvider;
use App\Services\OrganizationMapper;
use App\Services\RegistrableDomainExtractor;
use App\Services\SenderIdentityResolver;
use App\Services\SenderRoleClassifier;
use App\Tests\IntegrationTestCase;
use App\Tests\ScriptsDnsRecords;
use App\Value\ResolvedSender;
use App\Value\SenderAuthSignals;
use App\Value\SenderRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;

/**
 * @see docs/16-sender-identity-and-digest-truthfulness.md (DEC-059 §3.2–§3.4)
 */
final class SenderIdentityResolverTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

    private const string NOW = '2026-07-27 10:00:00';

    public function testIdentifiesASenderFromItsReverseRecordAndRemembersIt(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $resolved = $this->resolver()->resolve('77.75.76.89');

        self::assertSame('mxb.seznam.cz', $resolved->hostname);
        self::assertSame('seznam.cz', $resolved->registrableDomain);
        self::assertSame('Seznam', $resolved->organization);
        self::assertSame(SenderRole::Esp, $resolved->role);

        $this->getService(EntityManagerInterface::class)->flush();
        $cached = $this->getService(SenderIdentityRepository::class)->findByIp('77.75.76.89');
        self::assertNotNull($cached, 'The facts must be cached so the next report costs nothing.');
        self::assertSame('seznam.cz', $cached->registrableDomain);
        self::assertSame(1, $cached->resolutionAttempts);
    }

    public function testLooksAnAddressUpOnceForTheWholeSystem(): void
    {
        $reverseDns = $this->scriptReverseDns();
        $reverseDns->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $resolver = $this->resolver();
        $resolver->resolve('77.75.76.89');
        $this->getService(EntityManagerInterface::class)->flush();

        $resolver->resolve('77.75.76.89');

        self::assertSame(
            1,
            $reverseDns->lookupCount(),
            'Reverse DNS on the ingest path is what risks stalling a worker; a cached address must never be queried again.',
        );
    }

    public function testGivesTheWholeRotatingRelayPoolOneIdentity(): void
    {
        $this->scriptReverseDns()
            ->withHostname('2a02:598:1::1', 'mxb-1-a01.seznam.cz')
            ->withHostname('2a02:598:2::9', 'mxb-2-904.seznam.cz')
            ->withHostname('2a02:598:3::f', 'mxb-3-f13.seznam.cz');

        $resolved = $this->resolver()->resolveMany(['2a02:598:1::1', '2a02:598:2::9', '2a02:598:3::f']);

        $keys = array_unique(array_map(static fn (ResolvedSender $sender): string => $sender->identityKey(), $resolved));

        self::assertSame(
            ['seznam.cz'],
            array_values($keys),
            'Grouping by IP is what turned one relay into an endless stream of new senders.',
        );
    }

    public function testRecognisesABodyRewritingGatewayInsteadOfCallingItSpoofing(): void
    {
        $this->scriptReverseDns()->withHostname('15.222.110.90', 'ca.cloud-sec-av.com');

        $resolved = $this->resolver()->resolve(
            '15.222.110.90',
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 200),
        );

        self::assertSame(
            SenderRole::Forwarder,
            $resolved->role,
            'This gateway rewrites links, so it fails both checks — only the hostname distinguishes it from an attacker.',
        );
        self::assertFalse($resolved->role->warrantsAlert());
        self::assertSame('cloud-sec-av.com', $resolved->displayLabel());
    }

    public function testShowsATeamItsOwnRelayWithoutRewritingTheSharedCache(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.78.89', 'mxb.seznam.cz');

        $resolved = $this->resolver()->resolve(
            '77.75.78.89',
            new SenderAuthSignals(dkimPassRate: 100.0, spfPassRate: 100.0, isAuthorized: true, totalMessages: 40),
        );

        self::assertSame(SenderRole::OwnRelay, $resolved->role);

        $this->getService(EntityManagerInterface::class)->flush();
        $cached = $this->getService(SenderIdentityRepository::class)->findByIp('77.75.78.89');
        self::assertNotNull($cached);
        self::assertSame(
            SenderRole::Esp,
            $cached->role,
            'One team authorising an address must not make it "own relay" for every other team.',
        );
    }

    public function testRemembersThatAnAddressHasNoReverseRecord(): void
    {
        $resolver = $this->resolver();
        $reverseDns = $this->scriptReverseDns();

        $resolved = $resolver->resolve('198.51.100.5');

        self::assertFalse($resolved->isResolved());
        self::assertSame(SenderRole::Unknown, $resolved->role);
        self::assertSame('198.51.100.5', $resolved->displayLabel());

        $this->getService(EntityManagerInterface::class)->flush();
        $resolver->resolve('198.51.100.5');

        self::assertSame(
            1,
            $reverseDns->lookupCount(),
            'A failed lookup is an answer too — re-querying it on every ingest is the cost this cache exists to avoid.',
        );
    }

    public function testTriesAgainOnceTheBackoffHasElapsed(): void
    {
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $resolver = $this->resolver($clock);
        $reverseDns = $this->scriptReverseDns();

        $resolver->resolve('198.51.100.6');
        $this->getService(EntityManagerInterface::class)->flush();

        $clock->modify('+2 hours');
        $reverseDns->withHostname('198.51.100.6', 'relay.example.com');
        $resolved = $resolver->resolve('198.51.100.6');

        self::assertSame(2, $reverseDns->lookupCount());
        self::assertSame('example.com', $resolved->registrableDomain, 'An operator who publishes a PTR later still gets identified.');

        $cached = $this->getService(SenderIdentityRepository::class)->findByIp('198.51.100.6');
        self::assertNotNull($cached);
        self::assertSame(2, $cached->resolutionAttempts);
    }

    public function testStopsMakingLookupsOnceAReportBecomesUnreasonable(): void
    {
        $reverseDns = $this->scriptReverseDns();
        $ips = [];

        for ($i = 1; $i <= 40; ++$i) {
            $ip = '198.51.100.'.$i;
            $ips[] = $ip;
            $reverseDns->withHostname($ip, sprintf('host%d.example.com', $i));
        }

        $resolved = $this->resolver()->resolveMany($ips);

        self::assertCount(40, $resolved, 'Every address still gets an answer.');
        self::assertSame(
            25,
            $reverseDns->lookupCount(),
            'A pathological report must not be able to chain an unbounded number of DNS lookups inside a worker.',
        );
        self::assertTrue($resolved['198.51.100.1']->isResolved());
        self::assertFalse(
            $resolved['198.51.100.40']->isResolved(),
            'Deferred addresses come back unresolved and are picked up by the next report.',
        );
    }

    public function testDoesNotBurnTheRetryBudgetOfADeferredAddress(): void
    {
        $reverseDns = $this->scriptReverseDns();
        $ips = [];

        for ($i = 1; $i <= 30; ++$i) {
            $ip = '198.51.100.'.$i;
            $ips[] = $ip;
            $reverseDns->withHostname($ip, sprintf('host%d.example.com', $i));
        }

        $this->resolver()->resolveMany($ips);
        $this->getService(EntityManagerInterface::class)->flush();

        self::assertNull(
            $this->getService(SenderIdentityRepository::class)->findByIp('198.51.100.30'),
            'An address we never got to must not be recorded as a failed attempt.',
        );
    }

    public function testFallsBackToWhatItAlreadyKnowsAboutADeferredAddress(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(SenderIdentityRepository::class);
        $reverseDns = $this->scriptReverseDns();

        $repository->add(new SenderIdentity(
            id: $this->getService(IdentityProvider::class)->nextIdentity(),
            sourceIp: '198.51.100.99',
            resolvedAt: new \DateTimeImmutable('2026-07-27 08:00:00'),
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('2026-07-27 08:00:00'),
        ));
        $em->flush();

        $ips = [];

        for ($i = 1; $i <= 25; ++$i) {
            $ip = '198.51.100.'.$i;
            $ips[] = $ip;
            $reverseDns->withHostname($ip, sprintf('host%d.example.com', $i));
        }

        $ips[] = '198.51.100.99';
        $reverseDns->withHostname('198.51.100.99', 'late.example.com');

        $resolved = $this->resolver()->resolveMany($ips);

        self::assertFalse($resolved['198.51.100.99']->isResolved());
        self::assertSame(25, $reverseDns->lookupCount());

        $em->flush();
        $cached = $repository->findByIp('198.51.100.99');
        self::assertNotNull($cached);
        self::assertSame(
            1,
            $cached->resolutionAttempts,
            'An address the batch never got to must keep its place in the retry schedule.',
        );
    }

    public function testResolvesEachDistinctAddressOnceWithinABatch(): void
    {
        $reverseDns = $this->scriptReverseDns();
        $reverseDns->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $resolved = $this->resolver()->resolveMany(['77.75.76.89', '77.75.76.89', '77.75.76.89']);

        self::assertCount(1, $resolved);
        self::assertSame(1, $reverseDns->lookupCount());
    }

    public function testHasNothingToSayAboutAnEmptyReport(): void
    {
        self::assertSame([], $this->resolver()->resolveMany([]));
    }

    public function testAppliesEachDomainsOwnEvidenceToItsOwnAddress(): void
    {
        $this->scriptReverseDns()
            ->withHostname('77.75.76.89', 'mxb.seznam.cz')
            ->withHostname('198.51.100.7', 'mail.stranger.example');

        $resolved = $this->resolver()->resolveMany(
            ['77.75.76.89', '198.51.100.7'],
            [
                '77.75.76.89' => new SenderAuthSignals(100.0, 100.0, true, 40),
                '198.51.100.7' => new SenderAuthSignals(0.0, 0.0, false, 500),
            ],
        );

        self::assertSame(SenderRole::OwnRelay, $resolved['77.75.76.89']->role);
        self::assertSame(SenderRole::Suspicious, $resolved['198.51.100.7']->role);
        self::assertTrue($resolved['198.51.100.7']->role->warrantsAlert());
    }

    public function testReadsBackAnIdentityAnotherTeamAlreadyCached(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(SenderIdentityRepository::class);
        $reverseDns = $this->scriptReverseDns();

        $repository->add(new SenderIdentity(
            id: $this->getService(IdentityProvider::class)->nextIdentity(),
            sourceIp: '40.93.13.60',
            resolvedAt: new \DateTimeImmutable(self::NOW),
            hostname: 'mail-dm2pr04cu00304.outbound.protection.outlook.com',
            registrableDomain: 'outlook.com',
            organization: 'Microsoft',
            role: SenderRole::Forwarder,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable(self::NOW),
        ));
        $em->flush();
        $em->clear();

        $resolved = $this->resolver()->resolve('40.93.13.60');

        self::assertSame(SenderRole::Forwarder, $resolved->role);
        self::assertSame('Microsoft', $resolved->displayLabel());
        self::assertSame(0, $reverseDns->lookupCount(), 'The cache is global — one team\'s lookup serves everybody.');
    }

    public function testWiresUpThroughTheContainerWithTheFakeResolver(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $resolved = $this->getService(SenderIdentityResolver::class)->resolve('77.75.76.89');

        self::assertSame('seznam.cz', $resolved->registrableDomain);
    }

    private function resolver(?MockClock $clock = null): SenderIdentityResolver
    {
        return new SenderIdentityResolver(
            repository: $this->getService(SenderIdentityRepository::class),
            reverseDns: $this->getService(FakeReverseDnsResolver::class),
            registrableDomainExtractor: $this->getService(RegistrableDomainExtractor::class),
            organizationMapper: $this->getService(OrganizationMapper::class),
            classifier: $this->getService(SenderRoleClassifier::class),
            identityProvider: $this->getService(IdentityProvider::class),
            clock: $clock ?? new MockClock(new \DateTimeImmutable(self::NOW)),
        );
    }
}
