<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Query\GetDomainIngestionMatrix;
use App\Results\Dns\RuaScenarioResult;
use App\Results\DomainIngestionMatrixResult;
use App\Services\Dns\RuaScenarioResolver;
use App\Services\IngestionPathResolver;
use App\Value\Dns\RuaScenario;
use App\Value\IngestionPath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Unit-level coverage for the thin wrapper service. We don't re-validate the
 * query SQL here (that's the integration test's job) — we just prove the
 * resolver forwards correctly, handles each classification, and preserves
 * misconfiguration detection for the template layer.
 */
final class IngestionPathResolverTest extends TestCase
{
    #[Test]
    public function classifiesDnsOnlyDomain(): void
    {
        $result = $this->buildResult(IngestionPath::Dns);
        $resolver = $this->resolverReturning([$result]);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertCount(1, $resolved);
        self::assertSame(IngestionPath::Dns, $resolved[0]->path);
        self::assertFalse($resolved[0]->isMisconfigured());
    }

    #[Test]
    public function classifiesMailboxOnlyDomain(): void
    {
        $result = $this->buildResult(IngestionPath::Mailbox, mailboxHost: 'imap.example', mailboxPort: 993);
        $resolver = $this->resolverReturning([$result]);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertSame(IngestionPath::Mailbox, $resolved[0]->path);
        self::assertSame('imap.example', $resolved[0]->mailboxHost);
        self::assertSame(993, $resolved[0]->mailboxPort);
        self::assertFalse($resolved[0]->isMisconfigured());
    }

    #[Test]
    public function classifiesMixedDomainAsMisconfigured(): void
    {
        $result = $this->buildResult(IngestionPath::Mixed);
        $resolver = $this->resolverReturning([$result]);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertSame(IngestionPath::Mixed, $resolved[0]->path);
        self::assertTrue($resolved[0]->isMisconfigured());
    }

    #[Test]
    public function classifiesZeroReportDomainAsNone(): void
    {
        $result = $this->buildResult(IngestionPath::None);
        $resolver = $this->resolverReturning([$result]);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertSame(IngestionPath::None, $resolved[0]->path);
        self::assertNull($resolved[0]->lastReportAt);
        self::assertFalse($resolved[0]->isMisconfigured());
    }

    #[Test]
    public function returnsEmptyListForEmptyTeams(): void
    {
        // Mirror the query's defensive short-circuit: the resolver must not
        // call the query (which would throw on an empty IN clause) when the
        // caller has no team memberships.
        $query = $this->createMock(GetDomainIngestionMatrix::class);
        $query->expects(self::once())
            ->method('forTeams')
            ->with([])
            ->willReturn([]);

        $scenarioResolver = $this->createMock(RuaScenarioResolver::class);
        $scenarioResolver->expects(self::never())->method('resolveForDomainId');

        $resolver = new IngestionPathResolver($query, $scenarioResolver);

        self::assertSame([], $resolver->resolveForTeams([]));
    }

    #[Test]
    public function attachesRuaScenarioToEachRow(): void
    {
        // TASK-100: the resolver enriches every matrix row with the RUA
        // scenario for that domain. Mock the scenario resolver and assert
        // the wrapped result carries the scenario through.
        $result = $this->buildResult(IngestionPath::Dns);
        $scenario = new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com');

        $query = $this->createMock(GetDomainIngestionMatrix::class);
        $query->expects(self::once())
            ->method('forTeams')
            ->with(['team-1'])
            ->willReturn([$result]);

        $scenarioResolver = $this->createMock(RuaScenarioResolver::class);
        $scenarioResolver->expects(self::once())
            ->method('resolveForDomainId')
            ->willReturn($scenario);

        $resolver = new IngestionPathResolver($query, $scenarioResolver);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertCount(1, $resolved);
        self::assertNotNull($resolved[0]->ruaScenario);
        self::assertSame(RuaScenario::PointsAtSendvery, $resolved[0]->ruaScenario->scenario);
        self::assertSame('reports@sendvery.com', $resolved[0]->ruaScenario->ruaEmail);
    }

    private function buildResult(
        IngestionPath $path,
        ?string $mailboxHost = null,
        ?int $mailboxPort = null,
    ): DomainIngestionMatrixResult {
        return new DomainIngestionMatrixResult(
            domainId: Uuid::uuid7()->toString(),
            domainName: 'example.test',
            path: $path,
            lastReportAt: IngestionPath::None === $path ? null : new \DateTimeImmutable('2026-05-01 10:00:00'),
            mailboxId: null === $mailboxHost ? null : 'mb-1',
            mailboxHost: $mailboxHost,
            mailboxPort: $mailboxPort,
        );
    }

    /**
     * @param list<DomainIngestionMatrixResult> $results
     */
    private function resolverReturning(array $results): IngestionPathResolver
    {
        $query = $this->createMock(GetDomainIngestionMatrix::class);
        $query->expects(self::once())
            ->method('forTeams')
            ->with(['team-1'])
            ->willReturn($results);

        // Stub (no expectations) — the four classification tests don't
        // care about scenarios, they only care about path classification.
        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $scenarioResolver->method('resolveForDomainId')
            ->willReturn(new RuaScenarioResult(RuaScenario::NoRecord, null));

        return new IngestionPathResolver($query, $scenarioResolver);
    }
}
