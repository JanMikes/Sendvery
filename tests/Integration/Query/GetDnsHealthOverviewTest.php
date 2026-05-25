<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Query\GetDnsHealthOverview;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * TASK-130: extracted from the deleted `DnsHealthOverviewTest` — the
 * `forDomain()` security-guard assertions remain load-bearing for the
 * per-domain detail-header badge row that still calls this query directly.
 */
final class GetDnsHealthOverviewTest extends WebTestCase
{
    #[Test]
    public function forDomainReturnsNullForUnknownDomain(): void
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $query = self::getContainer()->get(GetDnsHealthOverview::class);
        assert($query instanceof GetDnsHealthOverview);

        $result = $query->forDomain(Uuid::uuid7()->toString(), [$persona->team->id->toString()]);

        self::assertNull($result);
    }

    #[Test]
    public function forDomainReturnsResultForKnownDomain(): void
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $query = self::getContainer()->get(GetDnsHealthOverview::class);
        assert($query instanceof GetDnsHealthOverview);

        $result = $query->forDomain($persona->domain->id->toString(), [$persona->team->id->toString()]);

        self::assertNotNull($result);
        self::assertSame($persona->domain->id->toString(), $result->domainId);
        self::assertSame($persona->domain->domain, $result->domainName);
    }

    #[Test]
    public function forDomainReturnsNullForDomainBelongingToDifferentTeam(): void
    {
        // Security guard: a known domain ID scoped against a foreign team's
        // IDs must return null, never the row. Cross-tenant exposure here
        // would leak SPF/DKIM/DMARC verification state across teams.
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $personaA = $fixtures->onboardedOwner();
        $personaB = $fixtures->onboardedOwner();
        assert(null !== $personaA->domain);

        $query = self::getContainer()->get(GetDnsHealthOverview::class);
        assert($query instanceof GetDnsHealthOverview);

        $result = $query->forDomain(
            $personaA->domain->id->toString(),
            [$personaB->team->id->toString()],
        );

        self::assertNull($result);
    }

    #[Test]
    public function forDomainReturnsNullWhenTeamIdsListIsEmpty(): void
    {
        // Early-return guard for the empty-tenant case — the caller could
        // hand us no teams (e.g. a freshly-created user with no team yet)
        // and we must not generate a `WHERE team_id IN ()` SQL fragment.
        self::createClient();

        $query = self::getContainer()->get(GetDnsHealthOverview::class);
        assert($query instanceof GetDnsHealthOverview);

        $result = $query->forDomain(Uuid::uuid7()->toString(), []);

        self::assertNull($result);
    }

    #[Test]
    public function forTeamsReturnsEmptyListForNoTeams(): void
    {
        self::createClient();

        $query = self::getContainer()->get(GetDnsHealthOverview::class);
        assert($query instanceof GetDnsHealthOverview);

        self::assertSame([], $query->forTeams([]));
    }

    #[Test]
    public function forTeamsReturnsDomainsForGivenTeam(): void
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $query = self::getContainer()->get(GetDnsHealthOverview::class);
        assert($query instanceof GetDnsHealthOverview);

        $results = $query->forTeams([$persona->team->id->toString()]);

        self::assertCount(1, $results);
        self::assertSame($persona->domain->id->toString(), $results[0]->domainId);
    }
}
