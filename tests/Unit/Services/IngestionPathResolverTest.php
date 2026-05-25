<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Entity\MailboxConnection;
use App\Entity\Team;
use App\Exceptions\MailboxConnectionNotFound;
use App\Query\GetDomainIngestionMatrix;
use App\Repository\MailboxConnectionRepository;
use App\Results\Dns\RuaScenarioResult;
use App\Results\DomainIngestionMatrixResult;
use App\Services\Dns\RuaMailboxMatcher;
use App\Services\Dns\RuaScenarioResolver;
use App\Services\IngestionPathResolver;
use App\Value\Dns\RuaScenario;
use App\Value\IngestionPath;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Unit-level coverage for the thin wrapper service. We don't re-validate the
 * query SQL here (that's the integration test's job) — we just prove the
 * resolver forwards correctly, handles each classification, preserves
 * misconfiguration detection for the template layer, and computes the
 * TASK-105 `allScenarioPointsAtSendvery` aggregator + TASK-106
 * `pathMatchesMailbox` per-row flag.
 *
 * TASK-114 extracted the email-matching rule into {@see RuaMailboxMatcher}; the
 * resolver now injects that matcher. The matching-rule edge cases (mailto:
 * strip, case-insensitive, non-email username, decrypt failure) are covered
 * exhaustively in {@see Dns\RuaMailboxMatcherTest}.
 * This file's `pathMatchesMailbox*` cases pin the resolver-side
 * preconditions + the delegation contract.
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
        // TASK-134: empty teams produces an empty matrix, so the batch call
        // (if it fires at all) receives `[]` and short-circuits inside the
        // resolver. The contract we care about: the per-domain method must
        // never fire on the matrix path. The batch call is harmless either
        // way — it's an O(1) PHP short-circuit on empty input.
        $scenarioResolver->expects(self::never())->method('resolveForDomainId');
        $scenarioResolver->method('resolveForDomainIds')->willReturn([]);

        $mailboxRepo = $this->createStub(MailboxConnectionRepository::class);
        $matcher = $this->createStub(RuaMailboxMatcher::class);

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        self::assertSame([], $resolver->resolveForTeams([]));
    }

    #[Test]
    public function attachesRuaScenarioToEachRow(): void
    {
        // TASK-100: the resolver enriches every matrix row with the RUA
        // scenario for that domain. TASK-134: scenarios resolve via a single
        // batch call now — assert exactly ONE invocation regardless of row
        // count, and that the keyed return map is read by domain ID.
        $result = $this->buildResult(IngestionPath::Dns);
        $scenario = new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com');

        $query = $this->createMock(GetDomainIngestionMatrix::class);
        $query->expects(self::once())
            ->method('forTeams')
            ->with(['team-1'])
            ->willReturn([$result]);

        $scenarioResolver = $this->createMock(RuaScenarioResolver::class);
        $scenarioResolver->expects(self::once())
            ->method('resolveForDomainIds')
            ->with([$result->domainId])
            ->willReturn([$result->domainId => $scenario]);

        $mailboxRepo = $this->createStub(MailboxConnectionRepository::class);
        $matcher = $this->createStub(RuaMailboxMatcher::class);

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertCount(1, $resolved);
        self::assertNotNull($resolved[0]->ruaScenario);
        self::assertSame(RuaScenario::PointsAtSendvery, $resolved[0]->ruaScenario->scenario);
        self::assertSame('reports@sendvery.com', $resolved[0]->ruaScenario->ruaEmail);
    }

    #[Test]
    public function allScenarioPointsAtSendveryFalseForEmptyMatrix(): void
    {
        // TASK-105: an empty matrix (brand-new team) is NOT "all scenario b"
        // — the educational two-card callout should still render because the
        // user has no scenario at all yet.
        $resolver = $this->newResolver();

        self::assertFalse($resolver->allScenarioPointsAtSendvery([]));
    }

    #[Test]
    public function allScenarioPointsAtSendveryTrueWhenEveryRowPointsAtSendvery(): void
    {
        $resolver = $this->newResolver();

        $rows = [
            $this->buildResult(IngestionPath::Dns)
                ->withScenario(new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com')),
            $this->buildResult(IngestionPath::Dns)
                ->withScenario(new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com')),
        ];

        self::assertTrue($resolver->allScenarioPointsAtSendvery($rows));
    }

    #[Test]
    public function allScenarioPointsAtSendveryFalseWhenAnyRowIsNotScenarioB(): void
    {
        $resolver = $this->newResolver();

        $rows = [
            $this->buildResult(IngestionPath::Dns)
                ->withScenario(new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com')),
            $this->buildResult(IngestionPath::Mailbox, mailboxHost: 'imap.x', mailboxPort: 993)
                ->withScenario(new RuaScenarioResult(RuaScenario::PointsAtExternal, 'dmarc@external.test')),
        ];

        self::assertFalse($resolver->allScenarioPointsAtSendvery($rows));
    }

    #[Test]
    public function allScenarioPointsAtSendveryFalseWhenAnyRowHasNoScenario(): void
    {
        $resolver = $this->newResolver();

        $rows = [
            $this->buildResult(IngestionPath::Dns)
                ->withScenario(new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com')),
            $this->buildResult(IngestionPath::None), // ruaScenario=null
        ];

        self::assertFalse($resolver->allScenarioPointsAtSendvery($rows));
    }

    #[Test]
    public function pathMatchesMailboxTrueWhenMatcherSaysSo(): void
    {
        // TASK-106 happy path: path=mailbox + scenario=PointsAtExternal +
        // mailbox login matches the rua email. The resolver delegates the
        // actual email comparison to RuaMailboxMatcher and surfaces its
        // verdict on the row.
        $mailboxId = Uuid::uuid7();
        $mailbox = $this->buildMailbox($mailboxId, encryptedUsername: 'enc:dmarc@team.com');

        $result = new DomainIngestionMatrixResult(
            domainId: Uuid::uuid7()->toString(),
            domainName: 'team.com',
            path: IngestionPath::Mailbox,
            lastReportAt: new \DateTimeImmutable('2026-05-01 10:00:00'),
            mailboxId: $mailboxId->toString(),
            mailboxHost: 'imap.team.com',
            mailboxPort: 993,
        );

        $query = $this->createStub(GetDomainIngestionMatrix::class);
        $query->method('forTeams')->willReturn([$result]);

        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $scenarioResolver->method('resolveForDomainIds')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, new RuaScenarioResult(RuaScenario::PointsAtExternal, 'DMARC@TEAM.COM')));

        $mailboxRepo = $this->createMock(MailboxConnectionRepository::class);
        $mailboxRepo->expects(self::once())
            ->method('get')
            ->with($mailboxId)
            ->willReturn($mailbox);

        $matcher = $this->createMock(RuaMailboxMatcher::class);
        $matcher->expects(self::once())
            ->method('matchesMailbox')
            ->with($mailbox, 'DMARC@TEAM.COM')
            ->willReturn(true);

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertCount(1, $resolved);
        self::assertTrue($resolved[0]->pathMatchesMailbox);
    }

    #[Test]
    public function pathMatchesMailboxFalseWhenMatcherSaysNo(): void
    {
        // Operator connected the wrong inbox — the matcher returns false.
        // Keep the scenario-aware warning so the operator notices.
        $mailboxId = Uuid::uuid7();
        $mailbox = $this->buildMailbox($mailboxId, encryptedUsername: 'enc:notifications@team.com');

        $result = new DomainIngestionMatrixResult(
            domainId: Uuid::uuid7()->toString(),
            domainName: 'team.com',
            path: IngestionPath::Mailbox,
            lastReportAt: new \DateTimeImmutable('2026-05-01 10:00:00'),
            mailboxId: $mailboxId->toString(),
            mailboxHost: 'imap.team.com',
            mailboxPort: 993,
        );

        $query = $this->createStub(GetDomainIngestionMatrix::class);
        $query->method('forTeams')->willReturn([$result]);

        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $scenarioResolver->method('resolveForDomainIds')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, new RuaScenarioResult(RuaScenario::PointsAtExternal, 'dmarc@team.com')));

        $mailboxRepo = $this->createStub(MailboxConnectionRepository::class);
        $mailboxRepo->method('get')->willReturn($mailbox);

        $matcher = $this->createStub(RuaMailboxMatcher::class);
        $matcher->method('matchesMailbox')->willReturn(false);

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertFalse($resolved[0]->pathMatchesMailbox);
    }

    #[Test]
    public function pathMatchesMailboxFalseWhenPathIsNotMailbox(): void
    {
        // path=DNS — no mailbox to compare. The resolver short-circuits
        // BEFORE touching the matcher or repo.
        $result = $this->buildResult(IngestionPath::Dns);

        $query = $this->createStub(GetDomainIngestionMatrix::class);
        $query->method('forTeams')->willReturn([$result]);

        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $scenarioResolver->method('resolveForDomainIds')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, new RuaScenarioResult(RuaScenario::PointsAtExternal, 'dmarc@team.com')));

        $mailboxRepo = $this->createMock(MailboxConnectionRepository::class);
        $mailboxRepo->expects(self::never())->method('get');

        $matcher = $this->createMock(RuaMailboxMatcher::class);
        $matcher->expects(self::never())->method('matchesMailbox');

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertFalse($resolved[0]->pathMatchesMailbox);
    }

    #[Test]
    public function pathMatchesMailboxFalseWhenScenarioIsNotPointsAtExternal(): void
    {
        // scenario=PointsAtSendvery — already handled by the existing
        // "Ingesting via DNS (Sendvery)" branch. Don't recompute.
        $result = $this->buildResult(IngestionPath::Mailbox, mailboxHost: 'imap.x', mailboxPort: 993);

        $query = $this->createStub(GetDomainIngestionMatrix::class);
        $query->method('forTeams')->willReturn([$result]);

        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $scenarioResolver->method('resolveForDomainIds')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com')));

        $mailboxRepo = $this->createMock(MailboxConnectionRepository::class);
        $mailboxRepo->expects(self::never())->method('get');

        $matcher = $this->createMock(RuaMailboxMatcher::class);
        $matcher->expects(self::never())->method('matchesMailbox');

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertFalse($resolved[0]->pathMatchesMailbox);
    }

    #[Test]
    public function pathMatchesMailboxFalseWhenLastReportAtIsNull(): void
    {
        // TASK-106 contract: the flag means "reports are physically arriving
        // AND credentials match". Without lastReportAt the path-vs-scenario
        // priority isn't load-bearing, so the resolver must short-circuit
        // before doing the expensive mailbox lookup + matcher call.
        $result = new DomainIngestionMatrixResult(
            domainId: Uuid::uuid7()->toString(),
            domainName: 'team.com',
            path: IngestionPath::Mailbox,
            lastReportAt: null,
            mailboxId: Uuid::uuid7()->toString(),
            mailboxHost: 'imap.team.com',
            mailboxPort: 993,
        );

        $query = $this->createStub(GetDomainIngestionMatrix::class);
        $query->method('forTeams')->willReturn([$result]);

        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $scenarioResolver->method('resolveForDomainIds')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, new RuaScenarioResult(RuaScenario::PointsAtExternal, 'dmarc@team.com')));

        $mailboxRepo = $this->createMock(MailboxConnectionRepository::class);
        $mailboxRepo->expects(self::never())->method('get');

        $matcher = $this->createMock(RuaMailboxMatcher::class);
        $matcher->expects(self::never())->method('matchesMailbox');

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertFalse($resolved[0]->pathMatchesMailbox);
    }

    #[Test]
    public function pathMatchesMailboxFalseWhenMailboxLookupFails(): void
    {
        // Defensive: if the row points at a mailbox UUID that doesn't resolve
        // (race condition, stale data, etc.), don't flip the badge — fall
        // through to the conservative scenario-warning rendering.
        $result = new DomainIngestionMatrixResult(
            domainId: Uuid::uuid7()->toString(),
            domainName: 'team.com',
            path: IngestionPath::Mailbox,
            lastReportAt: new \DateTimeImmutable('2026-05-01 10:00:00'),
            mailboxId: Uuid::uuid7()->toString(),
            mailboxHost: 'imap.team.com',
            mailboxPort: 993,
        );

        $query = $this->createStub(GetDomainIngestionMatrix::class);
        $query->method('forTeams')->willReturn([$result]);

        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $scenarioResolver->method('resolveForDomainIds')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, new RuaScenarioResult(RuaScenario::PointsAtExternal, 'dmarc@team.com')));

        $mailboxRepo = $this->createStub(MailboxConnectionRepository::class);
        $mailboxRepo->method('get')->willThrowException(new MailboxConnectionNotFound('gone'));

        $matcher = $this->createMock(RuaMailboxMatcher::class);
        $matcher->expects(self::never())->method('matchesMailbox');

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertFalse($resolved[0]->pathMatchesMailbox);
    }

    #[Test]
    public function pathMatchesMailboxFalseWhenMailboxIdIsNull(): void
    {
        // Defensive: a `path=mailbox + lastReportAt + scenario=external` row
        // with NO mailboxId (data race during DELETE, partially-purged team)
        // must not blow up — bail before the lookup.
        $result = new DomainIngestionMatrixResult(
            domainId: Uuid::uuid7()->toString(),
            domainName: 'team.com',
            path: IngestionPath::Mailbox,
            lastReportAt: new \DateTimeImmutable('2026-05-01 10:00:00'),
            mailboxId: null,
            mailboxHost: null,
            mailboxPort: null,
        );

        $query = $this->createStub(GetDomainIngestionMatrix::class);
        $query->method('forTeams')->willReturn([$result]);

        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $scenarioResolver->method('resolveForDomainIds')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys($ids, new RuaScenarioResult(RuaScenario::PointsAtExternal, 'dmarc@team.com')));

        $mailboxRepo = $this->createMock(MailboxConnectionRepository::class);
        $mailboxRepo->expects(self::never())->method('get');

        $matcher = $this->createMock(RuaMailboxMatcher::class);
        $matcher->expects(self::never())->method('matchesMailbox');

        $resolver = new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);

        $resolved = $resolver->resolveForTeams(['team-1']);

        self::assertFalse($resolved[0]->pathMatchesMailbox);
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

    private function buildMailbox(
        \Ramsey\Uuid\UuidInterface $id,
        string $encryptedUsername,
    ): MailboxConnection {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'unit',
            slug: 'unit-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $team->popEvents();

        $mailbox = new MailboxConnection(
            id: $id,
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.team.com',
            port: 993,
            encryptedUsername: $encryptedUsername,
            encryptedPassword: 'enc:pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $mailbox->popEvents();

        return $mailbox;
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
        // TASK-134: the resolver now calls the batch method. We return a
        // closure-built map so the default scenario applies to whatever
        // domain IDs the test's matrix rows happen to use.
        $scenarioResolver->method('resolveForDomainIds')
            ->willReturnCallback(static fn (array $ids): array => array_fill_keys(
                $ids,
                new RuaScenarioResult(RuaScenario::NoRecord, null),
            ));

        $mailboxRepo = $this->createStub(MailboxConnectionRepository::class);
        $matcher = $this->createStub(RuaMailboxMatcher::class);

        return new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);
    }

    private function newResolver(): IngestionPathResolver
    {
        $query = $this->createStub(GetDomainIngestionMatrix::class);
        $scenarioResolver = $this->createStub(RuaScenarioResolver::class);
        $mailboxRepo = $this->createStub(MailboxConnectionRepository::class);
        $matcher = $this->createStub(RuaMailboxMatcher::class);

        return new IngestionPathResolver($query, $scenarioResolver, $mailboxRepo, $matcher);
    }
}
