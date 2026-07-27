<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\SenderIdentity;
use App\Repository\SenderIdentityRepository;
use App\Services\Dns\FakeAsnResolver;
use App\Services\Dns\FakeDnswlResolver;
use App\Services\Dns\FakeReverseDnsResolver;
use App\Services\Dns\ForwardConfirmedReverseDns;
use App\Services\IdentityProvider;
use App\Services\OrganizationMapper;
use App\Services\RegistrableDomainExtractor;
use App\Services\SenderIdentityResolver;
use App\Services\SenderRoleClassifier;
use App\Tests\IntegrationTestCase;
use App\Tests\ScriptsDnsRecords;
use App\Value\Dns\DnswlListing;
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

    public function testIdentifiesTheNetworkBehindAnAddressThatPublishesNoReverseRecord(): void
    {
        // The case ASN exists for: no PTR, so no hostname, no registrable
        // domain and no organisation. Without it the reader gets a bare address.
        $this->scriptAsn()->withAsn('203.0.113.9', 16509, 'AMAZON-02');

        $resolved = $this->resolver()->resolve('203.0.113.9');

        self::assertSame('203.0.113.9 (AS16509 AMAZON-02)', $resolved->displayLabel());
        self::assertFalse($resolved->isResolved(), 'Knowing whose network announces an address is not knowing the host.');
    }

    public function testAKnownNetworkNeverExcusesASenderTheEvidenceDoesNot(): void
    {
        // AS8075 Microsoft is not something a VPS renter can claim — which makes
        // ASN good evidence of *who* and no evidence at all of *whether*. The
        // gateways this product recognises announce from their cloud provider's
        // AS anyway, so an ASN that could grant a role would be granting it on
        // the strength of a rented machine.
        $this->scriptAsn()->withAsn('198.51.100.7', 8075, 'MICROSOFT-CORP-MSN-AS-BLOCK');

        $resolved = $this->resolver()->resolve(
            '198.51.100.7',
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 500),
        );

        self::assertSame(SenderRole::Suspicious, $resolved->role);
        self::assertTrue($resolved->role->warrantsAlert());
    }

    public function testDoesNotCallAWhitelistedRelayAnAttackerButStillSurfacesIt(): void
    {
        $this->scriptReverseDns()->withHostname('198.51.100.7', 'mail.stranger.example');
        $this->scriptDnswl()->withListing('198.51.100.7', DnswlListing::TRUST_HIGH);

        $resolved = $this->resolver()->resolve(
            '198.51.100.7',
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 500),
        );

        self::assertSame(SenderRole::Unknown, $resolved->role);
        self::assertTrue(
            $resolved->role->warrantsAlert(),
            'A whitelist describes the operator, not this message: a listed relay forwards a spoofed message as willingly as a genuine one.',
        );
    }

    public function testAsksAWhitelistAboutAnAddressOnceForTheWholeSystem(): void
    {
        $this->scriptReverseDns()->withHostname('40.93.13.60', 'mail.outbound.protection.outlook.com');
        $dnswl = $this->scriptDnswl()->withListing('40.93.13.60', DnswlListing::TRUST_HIGH);

        $resolver = $this->resolver();
        $resolver->resolve('40.93.13.60');
        $this->getService(EntityManagerInterface::class)->flush();

        $resolver->resolve('40.93.13.60');

        self::assertSame(1, $dnswl->lookupCount(), 'The whitelist lookup shares the identity cache, so a warm address costs nothing.');
    }

    public function testAsksTheNetworkOfAnAddressOnceForTheWholeSystem(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');
        $asn = $this->scriptAsn()->withAsn('77.75.76.89', 43037, 'SEZNAM-AS');

        $resolver = $this->resolver();
        $resolver->resolve('77.75.76.89');
        $this->getService(EntityManagerInterface::class)->flush();

        $resolved = $resolver->resolve('77.75.76.89');

        self::assertSame(1, $asn->lookupCount(), 'The AS lookup shares the identity cache, so a warm address costs nothing.');
        self::assertSame(43037, $resolved->asn?->number);
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

    public function testDoesNotHandForwarderTrustToAReverseRecordItsOwnerWrote(): void
    {
        // The exploit: a PTR record is set by whoever holds the IP block, so a
        // spoofer names their box after a famous gateway, is filed as a harmless
        // forwarder, and the new-sender alert — the one signal that would have
        // surfaced them — never fires. The forward RRset of mimecast.com is
        // published by Mimecast and does not list the attacker's address.
        $this->scriptReverseDns()
            ->withForgedHostname('203.0.113.240', 'eu-smtp-delivery-1.mimecast.com')
            ->withForwardAddresses('eu-smtp-delivery-1.mimecast.com', '195.130.217.1');

        $resolved = $this->resolver()->resolve(
            '203.0.113.240',
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 400),
        );

        self::assertNotSame(SenderRole::Forwarder, $resolved->role);
        self::assertTrue($resolved->role->warrantsAlert(), 'The user has to hear about this sender.');

        $this->getService(EntityManagerInterface::class)->flush();
        $cached = $this->getService(SenderIdentityRepository::class)->findByIp('203.0.113.240');
        self::assertNotNull($cached);
        self::assertFalse($cached->isForwardConfirmed());
        self::assertNotSame(
            SenderRole::Forwarder,
            $cached->role,
            'The cache is shared, so an unearned role would silence this sender for every other team too.',
        );
        self::assertSame(
            'eu-smtp-delivery-1.mimecast.com',
            $cached->hostname,
            'The name is still the best label we have; only the trust in it is withheld.',
        );
    }

    public function testStillRecognisesAForwarderThatOnlyItsDkimSignatureCanProve(): void
    {
        // No reverse record at all, so no hostname to confirm — but a DKIM
        // signature that still verifies after the hop is cryptographic proof
        // that the message was relayed intact, and no DNS check can add to it.
        $resolved = $this->resolver()->resolve(
            '198.51.100.77',
            new SenderAuthSignals(dkimPassRate: 100.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 2),
        );

        self::assertFalse($resolved->isResolved());
        self::assertSame(SenderRole::Forwarder, $resolved->role);
        self::assertFalse($resolved->role->warrantsAlert());
    }

    public function testMakesAnAddressCachedBeforeConfirmationExistedEarnItsTrustAgain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(SenderIdentityRepository::class);

        // A row written before forward confirmation existed: hostname trusted,
        // question never asked. Migrating it in as "confirmed" would leave the
        // hole open for every address already in the cache.
        $repository->add(new SenderIdentity(
            id: $this->getService(IdentityProvider::class)->nextIdentity(),
            sourceIp: '203.0.113.241',
            resolvedAt: new \DateTimeImmutable('2026-07-20 10:00:00'),
            hostname: 'eu-smtp-delivery-1.mimecast.com',
            registrableDomain: 'mimecast.com',
            role: SenderRole::Forwarder,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('2026-07-20 10:00:00'),
        ));
        $em->flush();
        $em->clear();

        $this->scriptReverseDns()
            ->withForgedHostname('203.0.113.241', 'eu-smtp-delivery-1.mimecast.com')
            ->withForwardAddresses('eu-smtp-delivery-1.mimecast.com', '195.130.217.1');

        $resolved = $this->resolver()->resolve('203.0.113.241');

        self::assertNotSame(
            SenderRole::Forwarder,
            $resolved->role,
            'A role granted before the check existed is not evidence that the check would have passed.',
        );

        $em->flush();
        $cached = $repository->findByIp('203.0.113.241');
        self::assertNotNull($cached);
        self::assertFalse($cached->isForwardConfirmed());
        self::assertSame(2, $cached->resolutionAttempts, 'One re-resolution settles the question for good.');
        self::assertFalse(
            $cached->isDueForRetry(new \DateTimeImmutable('2027-07-27 10:00:00')),
            'Once answered, the host is never queried again.',
        );
    }

    public function testConfirmsARelayThatPublishesOnlyAnIpv6Address(): void
    {
        // mxb-2-904.seznam.cz is real, and it has no A record at all.
        $this->scriptReverseDns()
            ->withHostname('2a02:598:64:8a00::1000:904', 'mxb-2-904.seznam.cz')
            ->withForwardAddresses('mxb-2-904.seznam.cz', '2a02:0598:0064:8a00:0000:0000:1000:0904');

        $this->resolver()->resolve('2a02:598:64:8a00::1000:904');
        $this->getService(EntityManagerInterface::class)->flush();

        $cached = $this->getService(SenderIdentityRepository::class)->findByIp('2a02:598:64:8a00::1000:904');
        self::assertNotNull($cached);
        self::assertTrue(
            $cached->isForwardConfirmed(),
            'An A-only check, or a textual comparison, would strip a legitimate relay of its identity.',
        );
    }

    public function testConfirmsAGatewayThatSendsFromAnyNodeOfItsPool(): void
    {
        $this->scriptReverseDns()
            ->withHostname('34.210.15.192', 'ipw-outbound.inkyphishfence.com')
            ->withForwardAddresses(
                'ipw-outbound.inkyphishfence.com',
                '3.132.108.44',
                '18.208.14.99',
                '34.210.15.192',
                '35.171.24.11',
                '44.192.8.7',
                '52.6.90.201',
                '54.86.3.19',
                '107.20.44.62',
            );

        $resolved = $this->resolver()->resolve(
            '34.210.15.192',
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 60),
        );

        self::assertSame(
            SenderRole::Forwarder,
            $resolved->role,
            'Reading only the first answer of an eight-address pool would reject seven eighths of a real gateway.',
        );
    }

    public function testConfirmsAGatewayThatAnswersWithAnIpv4MappedAddress(): void
    {
        $this->scriptReverseDns()
            ->withHostname('40.93.13.100', 'mail-dm2pr04cu00304.outbound.protection.outlook.com')
            ->withForwardAddresses('mail-dm2pr04cu00304.outbound.protection.outlook.com', '::ffff:40.93.13.100');

        $resolved = $this->resolver()->resolve(
            '40.93.13.100',
            new SenderAuthSignals(dkimPassRate: 0.0, spfPassRate: 0.0, isAuthorized: false, totalMessages: 60),
        );

        self::assertSame(
            SenderRole::Forwarder,
            $resolved->role,
            'Microsoft answers AAAA queries with IPv4-mapped addresses; comparing them as text would reject a legitimate host.',
        );
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
            SenderIdentityResolver::MAX_IDENTIFICATIONS_PER_BATCH,
            $reverseDns->lookupCount(),
            'A pathological report must not be able to chain an unbounded number of DNS lookups inside a worker.',
        );
        self::assertLessThanOrEqual(
            25,
            $reverseDns->lookupCount() + $reverseDns->forwardLookupCount(),
            'Confirming a hostname has to fit inside the budget the reverse lookups already had, not double it.',
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

        for ($i = 1; $i <= SenderIdentityResolver::MAX_IDENTIFICATIONS_PER_BATCH; ++$i) {
            $ip = '198.51.100.'.$i;
            $ips[] = $ip;
            $reverseDns->withHostname($ip, sprintf('host%d.example.com', $i));
        }

        $ips[] = '198.51.100.99';
        $reverseDns->withHostname('198.51.100.99', 'late.example.com');

        $resolved = $this->resolver()->resolveMany($ips);

        self::assertFalse($resolved['198.51.100.99']->isResolved());
        self::assertSame(SenderIdentityResolver::MAX_IDENTIFICATIONS_PER_BATCH, $reverseDns->lookupCount());

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
            forwardConfirmed: true,
            asnResolvedAt: new \DateTimeImmutable(self::NOW),
            dnswlCheckedAt: new \DateTimeImmutable(self::NOW),
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
        $reverseDns = $this->getService(FakeReverseDnsResolver::class);

        return new SenderIdentityResolver(
            repository: $this->getService(SenderIdentityRepository::class),
            reverseDns: $reverseDns,
            forwardConfirmation: new ForwardConfirmedReverseDns($reverseDns),
            asnResolver: $this->getService(FakeAsnResolver::class),
            dnswlResolver: $this->getService(FakeDnswlResolver::class),
            registrableDomainExtractor: $this->getService(RegistrableDomainExtractor::class),
            organizationMapper: $this->getService(OrganizationMapper::class),
            classifier: $this->getService(SenderRoleClassifier::class),
            identityProvider: $this->getService(IdentityProvider::class),
            clock: $clock ?? new MockClock(new \DateTimeImmutable(self::NOW)),
        );
    }
}
