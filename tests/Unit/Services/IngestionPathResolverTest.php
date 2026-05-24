<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Query\GetDomainIngestionMatrix;
use App\Results\DomainIngestionMatrixResult;
use App\Services\IngestionPathResolver;
use App\Value\IngestionPath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        $resolver = new IngestionPathResolver($query);

        self::assertSame([], $resolver->resolveForTeams([]));
    }

    private function buildResult(
        IngestionPath $path,
        ?string $mailboxHost = null,
        ?int $mailboxPort = null,
    ): DomainIngestionMatrixResult {
        return new DomainIngestionMatrixResult(
            domainId: 'd-1',
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

        return new IngestionPathResolver($query);
    }
}
