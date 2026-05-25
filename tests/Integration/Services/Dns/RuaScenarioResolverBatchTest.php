<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Services\Dns\RuaScenarioResolver;
use App\Tests\IntegrationTestCase;
use App\Tests\TestSupport\InMemoryQueryLogger;
use App\Value\Dns\RuaScenario;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * TASK-134 integration coverage for the batch RUA resolver. Asserts the
 * functional contract (one entry per input, cross-team isolation, missing
 * domains → `NoRecord`) AND the load-bearing performance invariant: the
 * batch call issues exactly ONE select against `dns_check_result`, regardless
 * of how many domains are passed in. The PSR-3 query logger registered in
 * test config is the regression net — if a future refactor accidentally
 * re-introduces a per-domain foreach, the count assertion fails immediately.
 */
final class RuaScenarioResolverBatchTest extends IntegrationTestCase
{
    public function testResolveForDomainIdsReturnsEmptyArrayForEmptyInput(): void
    {
        $resolver = $this->getService(RuaScenarioResolver::class);
        $logger = $this->getService(InMemoryQueryLogger::class);
        $logger->flush();

        self::assertSame([], $resolver->resolveForDomainIds([]));
        // Empty input short-circuits — no SQL must touch the database.
        self::assertCount(
            0,
            $logger->queriesContaining('dns_check_result'),
            'Empty input must not issue any select against dns_check_result.',
        );
    }

    public function testResolveForDomainIdsReturnsEntryPerRequestedDomainIncludingNoRecord(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $resolver = $this->getService(RuaScenarioResolver::class);

        $team = $this->persistTeam($em, 'batch-resolver-team');
        $sendveryDomain = $this->persistDomain($em, $team, 'batch-sendvery.example');
        $externalDomain = $this->persistDomain($em, $team, 'batch-external.example');
        $noRecordDomain = $this->persistDomain($em, $team, 'batch-norecord.example');

        $this->persistDmarcCheck($em, $sendveryDomain, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com');
        $this->persistDmarcCheck($em, $externalDomain, 'v=DMARC1; p=none; rua=mailto:reports@acme.example');
        // No dns_check_result for $noRecordDomain — covers the "missing row"
        // branch where the LEFT JOIN LATERAL yields a NULL raw_record.

        $em->flush();
        $em->clear();

        $result = $resolver->resolveForDomainIds([
            $sendveryDomain->id->toString(),
            $externalDomain->id->toString(),
            $noRecordDomain->id->toString(),
        ]);

        self::assertCount(3, $result);
        self::assertArrayHasKey($sendveryDomain->id->toString(), $result);
        self::assertArrayHasKey($externalDomain->id->toString(), $result);
        self::assertArrayHasKey($noRecordDomain->id->toString(), $result);

        self::assertSame(RuaScenario::PointsAtSendvery, $result[$sendveryDomain->id->toString()]->scenario);
        self::assertSame('reports@sendvery.com', $result[$sendveryDomain->id->toString()]->ruaEmail);

        self::assertSame(RuaScenario::PointsAtExternal, $result[$externalDomain->id->toString()]->scenario);
        self::assertSame('reports@acme.example', $result[$externalDomain->id->toString()]->ruaEmail);

        self::assertSame(RuaScenario::NoRecord, $result[$noRecordDomain->id->toString()]->scenario);
        self::assertNull($result[$noRecordDomain->id->toString()]->ruaEmail);
    }

    public function testResolveForDomainIdsBackfillsNoRecordForUnknownDomainIds(): void
    {
        // Defensive contract: callers (DashboardOverviewController) pass
        // domain IDs sourced from the team-scoped overview query, but a race
        // between the overview SELECT and the resolver call could surface an
        // ID that no longer maps to a `monitored_domain` row. The batch
        // resolver must still return a total map keyed by every requested ID.
        $resolver = $this->getService(RuaScenarioResolver::class);

        $unknownId = Uuid::uuid7()->toString();
        $result = $resolver->resolveForDomainIds([$unknownId]);

        self::assertCount(1, $result);
        self::assertArrayHasKey($unknownId, $result);
        self::assertSame(RuaScenario::NoRecord, $result[$unknownId]->scenario);
        self::assertNull($result[$unknownId]->ruaEmail);
    }

    public function testResolveForDomainIdsKeepsCrossTeamScenariosIsolated(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $resolver = $this->getService(RuaScenarioResolver::class);

        // Two teams with one domain each — each domain has a DIFFERENT
        // scenario. The batch resolver must not bleed one team's record into
        // the other team's lookup key.
        $teamA = $this->persistTeam($em, 'batch-team-a');
        $teamB = $this->persistTeam($em, 'batch-team-b');
        $domainA = $this->persistDomain($em, $teamA, 'team-a.example');
        $domainB = $this->persistDomain($em, $teamB, 'team-b.example');

        $this->persistDmarcCheck($em, $domainA, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com');
        $this->persistDmarcCheck($em, $domainB, 'v=DMARC1; p=none; rua=mailto:reports@example.com');

        $em->flush();
        $em->clear();

        $result = $resolver->resolveForDomainIds([
            $domainA->id->toString(),
            $domainB->id->toString(),
        ]);

        self::assertSame(RuaScenario::PointsAtSendvery, $result[$domainA->id->toString()]->scenario);
        self::assertSame(RuaScenario::PointsAtExternal, $result[$domainB->id->toString()]->scenario);
        self::assertSame('reports@example.com', $result[$domainB->id->toString()]->ruaEmail);
    }

    public function testResolveForDomainIdsIssuesExactlyOneDnsCheckQueryRegardlessOfInputSize(): void
    {
        // THE regression net — TASK-134's core promise. If a future refactor
        // re-introduces a foreach over `resolveForDomainId`, this assertion
        // fires immediately (the count climbs to N).
        $em = $this->getService(EntityManagerInterface::class);
        $resolver = $this->getService(RuaScenarioResolver::class);
        $logger = $this->getService(InMemoryQueryLogger::class);

        $team = $this->persistTeam($em, 'batch-query-count-team');
        $domains = [];
        for ($i = 0; $i < 5; ++$i) {
            $domain = $this->persistDomain($em, $team, sprintf('count-%d.example', $i));
            $this->persistDmarcCheck($em, $domain, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com');
            $domains[] = $domain->id->toString();
        }
        $em->flush();
        $em->clear();

        // Flush AFTER fixture writes so the count only covers the subject call.
        $logger->flush();

        $resolver->resolveForDomainIds($domains);

        $dnsCheckQueries = $logger->queriesContaining('dns_check_result');
        self::assertCount(
            1,
            $dnsCheckQueries,
            sprintf(
                'Batch resolver must issue exactly one SELECT against dns_check_result; got %d: [%s]',
                count($dnsCheckQueries),
                implode("\n---\n", $dnsCheckQueries),
            ),
        );
    }

    public function testPerDomainResolverStillWorksUnchanged(): void
    {
        // Smoke test: the single-row `resolveForDomainId()` keeps its
        // behaviour after the batch addition — the per-domain detail page
        // depends on it.
        $em = $this->getService(EntityManagerInterface::class);
        $resolver = $this->getService(RuaScenarioResolver::class);

        $team = $this->persistTeam($em, 'batch-single-row-team');
        $domain = $this->persistDomain($em, $team, 'single-row.example');
        $this->persistDmarcCheck($em, $domain, 'v=DMARC1; p=none; rua=mailto:reports@acme.example');
        $em->flush();
        $em->clear();

        $result = $resolver->resolveForDomainId($domain->id);

        self::assertSame(RuaScenario::PointsAtExternal, $result->scenario);
        self::assertSame('reports@acme.example', $result->ruaEmail);
    }

    private function persistTeam(EntityManagerInterface $em, string $slugPrefix): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: ucfirst($slugPrefix),
            slug: $slugPrefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        return $team;
    }

    private function persistDomain(EntityManagerInterface $em, Team $team, string $domainName): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            // Cross-team uniqueness is enforced by a case-insensitive functional
            // index; the per-test prefix keeps tests parallel-safe even when
            // the DAMA transaction wrapper is bypassed (e.g. local debug runs).
            domain: $domainName.'.'.substr(Uuid::uuid7()->toString(), 0, 8),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        return $domain;
    }

    private function persistDmarcCheck(EntityManagerInterface $em, MonitoredDomain $domain, ?string $rawRecord): void
    {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: null !== $rawRecord,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        // The constructor records a `DnsCheckCompleted` domain event; we don't
        // want the event subscriber to spawn extra writes during the test
        // (kicks off snapshot scheduling, etc.), so drain it before persist.
        $check->popEvents();
        $em->persist($check);
    }
}
