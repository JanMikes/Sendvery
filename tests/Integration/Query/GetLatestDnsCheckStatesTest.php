<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DnsCheckResult;
use App\Query\GetLatestDnsCheckStates;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use App\Value\ProtocolState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * The authoritative read behind every per-protocol DNS verdict: the newest
 * stored check per protocol for one domain, team-scoped.
 */
final class GetLatestDnsCheckStatesTest extends WebTestCase
{
    #[Test]
    public function itReturnsTheNewestCheckPerProtocol(): void
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // An older failing MX check followed by a newer passing one — the fresh
        // verdict has to win, otherwise a user who fixed their DNS keeps being
        // told it is broken.
        $this->persist($em, $persona, DnsCheckType::Mx, null, false, '2026-07-20 03:00:00');
        $this->persist($em, $persona, DnsCheckType::Mx, '10 mx1.example.net', true, '2026-07-27 10:15:00');
        $this->persist($em, $persona, DnsCheckType::Spf, 'v=spf1 -all', true, '2026-07-27 10:15:00');

        $states = $this->query()->forDomain(
            $persona->domain->id->toString(),
            [$persona->team->id->toString()],
        );

        self::assertCount(2, $states, 'One entry per protocol that has ever been checked.');
        self::assertSame(ProtocolState::Configured, $states[DnsCheckType::Mx->value]->protocolState());
        self::assertSame('10 mx1.example.net', $states[DnsCheckType::Mx->value]->rawRecord);
        self::assertSame(ProtocolState::Configured, $states[DnsCheckType::Spf->value]->protocolState());
    }

    #[Test]
    public function aDomainWithNoChecksYetReturnsNothingRatherThanGuessing(): void
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        self::assertSame([], $this->query()->forDomain(
            $persona->domain->id->toString(),
            [$persona->team->id->toString()],
        ));
    }

    #[Test]
    public function anotherTeamsDomainIdLeaksNothing(): void
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $owner = $fixtures->onboardedOwner();
        $outsider = $fixtures->onboardedOwner();
        assert(null !== $owner->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $this->persist($em, $owner, DnsCheckType::Mx, '10 mx1.example.net', true, '2026-07-27 10:15:00');

        self::assertSame([], $this->query()->forDomain(
            $owner->domain->id->toString(),
            [$outsider->team->id->toString()],
        ), 'A known domain UUID from another tenant must reveal no DNS state.');
    }

    #[Test]
    public function aCallerWithNoTeamsIsAnsweredWithoutTouchingTheDatabase(): void
    {
        self::createClient();

        self::assertSame([], $this->query()->forDomain(Uuid::uuid7()->toString(), []));
    }

    private function query(): GetLatestDnsCheckStates
    {
        $query = self::getContainer()->get(GetLatestDnsCheckStates::class);
        assert($query instanceof GetLatestDnsCheckStates);

        return $query;
    }

    private function persist(
        EntityManagerInterface $em,
        Persona $persona,
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
        string $checkedAt,
    ): void {
        assert(null !== $persona->domain);

        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: $type,
            checkedAt: new \DateTimeImmutable($checkedAt),
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
