<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Query\GetLatestDnsCheckStatesForDomains;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use App\Value\ProtocolState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * The batch per-protocol state lookup behind the `/app` attention list. Two
 * things have to hold for it to be usable there: the newest row per
 * (domain, protocol) pair wins, and a domain from another tenant never appears
 * even when its UUID is asked for by name.
 */
final class GetLatestDnsCheckStatesForDomainsTest extends WebTestCase
{
    #[Test]
    public function itReturnsTheNewestRowPerProtocolForEveryRequestedDomain(): void
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        $persona = $fixtures->persona()->withoutDomain()->build();
        $first = $fixtures->addExtraDomain($persona->team, 'batch-first');
        $second = $fixtures->addExtraDomain($persona->team, 'batch-second');

        // Stale then fresh for the same protocol on the SAME domain: the fresh
        // verdict must be the one that survives, per domain independently.
        $this->check($em, $first, DnsCheckType::Spf, '-2 days', rawRecord: null, isValid: false);
        $this->check($em, $first, DnsCheckType::Spf, '-1 hour', rawRecord: 'v=spf1 -all', isValid: true);
        $this->check($em, $first, DnsCheckType::Dmarc, '-1 hour', rawRecord: null, isValid: false);
        $this->check($em, $second, DnsCheckType::Spf, '-1 hour', rawRecord: 'v=spf1 broken', isValid: false);
        $em->flush();

        $states = $this->getService(GetLatestDnsCheckStatesForDomains::class)->forDomains(
            [$first->id->toString(), $second->id->toString()],
            [$persona->team->id->toString()],
        );

        self::assertSame(
            ProtocolState::Configured,
            $states[$first->id->toString()][DnsCheckType::Spf->value]->protocolState(),
        );
        self::assertSame(
            ProtocolState::Missing,
            $states[$first->id->toString()][DnsCheckType::Dmarc->value]->protocolState(),
        );
        self::assertSame(
            ProtocolState::Invalid,
            $states[$second->id->toString()][DnsCheckType::Spf->value]->protocolState(),
            'A record that exists but fails validation is Invalid, not Missing — the user edits it rather than adding one.',
        );
        self::assertArrayNotHasKey(
            DnsCheckType::Dmarc->value,
            $states[$second->id->toString()],
            'A protocol with no stored check row is absent, which callers read as "not checked yet".',
        );
    }

    #[Test]
    public function aDomainBelongingToAnotherTeamIsNeverReturned(): void
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        $mine = $fixtures->persona()->withoutDomain()->build();
        $theirs = $fixtures->persona()->withoutDomain()->build();
        $foreignDomain = $fixtures->addExtraDomain($theirs->team, 'not-mine');
        $this->check($em, $foreignDomain, DnsCheckType::Spf, '-1 hour', rawRecord: null, isValid: false);
        $em->flush();

        $states = $this->getService(GetLatestDnsCheckStatesForDomains::class)->forDomains(
            [$foreignDomain->id->toString()],
            [$mine->team->id->toString()],
        );

        self::assertSame([], $states);
    }

    #[Test]
    public function emptyInputShortCircuitsWithoutQuerying(): void
    {
        self::createClient();
        $query = $this->getService(GetLatestDnsCheckStatesForDomains::class);

        self::assertSame([], $query->forDomains([], ['019fa000-0000-7000-8000-000000000001']));
        self::assertSame([], $query->forDomains(['019fa000-0000-7000-8000-000000000001'], []));
    }

    private function check(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        DnsCheckType $type,
        string $checkedAt,
        ?string $rawRecord,
        bool $isValid,
    ): void {
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable($checkedAt),
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: false,
        ));
    }
}
