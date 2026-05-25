<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Repository\DnsCheckResultRepository;
use App\Services\Dns\DmarcRecordParser;
use App\Services\Dns\RuaScenarioResolver;
use App\Services\ReportAddressProvider;
use App\Value\Dns\RuaScenario;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * TASK-100 unit coverage for the scenario classifier. Mocks the DNS-check
 * repository so each branch (no check / null record / non-DMARC string / no
 * rua= / matches Sendvery / matches an external host / mixed) is testable in
 * isolation; the parser and report-address provider are wired as real
 * collaborators because they're pure and trivially constructable.
 */
final class RuaScenarioResolverTest extends TestCase
{
    #[Test]
    public function resolveForDomainIdReturnsNoRecordWhenNoCheckExists(): void
    {
        $resolver = $this->resolverWithCheck(null);

        $result = $resolver->resolveForDomainId(Uuid::uuid7());

        self::assertSame(RuaScenario::NoRecord, $result->scenario);
        self::assertNull($result->ruaEmail);
    }

    #[Test]
    public function resolveForDomainIdReturnsNoRecordWhenLatestCheckHasNullRawRecord(): void
    {
        $check = $this->buildCheck(null);
        $resolver = $this->resolverWithCheck($check);

        $result = $resolver->resolveForDomainId(Uuid::uuid7());

        self::assertSame(RuaScenario::NoRecord, $result->scenario);
    }

    #[Test]
    public function resolveForDomainIdReturnsNoRecordWhenParsedRecordHasNoRua(): void
    {
        $check = $this->buildCheck('v=DMARC1; p=none');
        $resolver = $this->resolverWithCheck($check);

        $result = $resolver->resolveForDomainId(Uuid::uuid7());

        self::assertSame(RuaScenario::NoRecord, $result->scenario);
        self::assertNull($result->ruaEmail);
    }

    #[Test]
    public function resolveForDomainIdReturnsPointsAtSendveryWhenRuaIsReportsAddress(): void
    {
        $check = $this->buildCheck('v=DMARC1; p=none; rua=mailto:reports@sendvery.com');
        $resolver = $this->resolverWithCheck($check);

        $result = $resolver->resolveForDomainId(Uuid::uuid7());

        self::assertSame(RuaScenario::PointsAtSendvery, $result->scenario);
        self::assertSame('reports@sendvery.com', $result->ruaEmail);
    }

    #[Test]
    public function resolveForDomainIdReturnsPointsAtSendveryWhenAnyRuaIsSendveryDomain(): void
    {
        // Even a non-reports@ address on the sendvery.com host counts as
        // Sendvery — we own the domain, we receive everything sent there.
        $check = $this->buildCheck('v=DMARC1; p=none; rua=mailto:john@sendvery.com');
        $resolver = $this->resolverWithCheck($check);

        $result = $resolver->resolveForDomainId(Uuid::uuid7());

        self::assertSame(RuaScenario::PointsAtSendvery, $result->scenario);
        self::assertSame('john@sendvery.com', $result->ruaEmail);
    }

    #[Test]
    public function resolveForDomainIdReturnsPointsAtExternalWhenRuaIsThirdParty(): void
    {
        $check = $this->buildCheck('v=DMARC1; p=none; rua=mailto:reports@acme.com');
        $resolver = $this->resolverWithCheck($check);

        $result = $resolver->resolveForDomainId(Uuid::uuid7());

        self::assertSame(RuaScenario::PointsAtExternal, $result->scenario);
        self::assertSame('reports@acme.com', $result->ruaEmail);
    }

    #[Test]
    public function resolveForDomainIdPicksFirstSendveryAddressAsRuaEmailWhenMixed(): void
    {
        // External first, Sendvery second — Sendvery still wins (any
        // Sendvery address in the list means we'll receive reports), and
        // the ruaEmail surfaces the matching Sendvery address.
        $check = $this->buildCheck('v=DMARC1; p=none; rua=mailto:reports@acme.com,mailto:reports@sendvery.com');
        $resolver = $this->resolverWithCheck($check);

        $result = $resolver->resolveForDomainId(Uuid::uuid7());

        self::assertSame(RuaScenario::PointsAtSendvery, $result->scenario);
        self::assertSame('reports@sendvery.com', $result->ruaEmail);
    }

    #[Test]
    public function isSendveryAddressAcceptsReportsAtSendvery(): void
    {
        $resolver = $this->resolverWithCheck(null);
        self::assertTrue($resolver->isSendveryAddress('reports@sendvery.com'));
    }

    #[Test]
    public function isSendveryAddressAcceptsAnySendveryDomain(): void
    {
        $resolver = $this->resolverWithCheck(null);
        self::assertTrue($resolver->isSendveryAddress('anyone@sendvery.com'));
    }

    #[Test]
    public function isSendveryAddressRejectsExternal(): void
    {
        $resolver = $this->resolverWithCheck(null);
        self::assertFalse($resolver->isSendveryAddress('reports@acme.com'));
        self::assertFalse($resolver->isSendveryAddress('reports@sendvery.com.evil.com'));
    }

    #[Test]
    public function isSendveryAddressRejectsMalformedNoAt(): void
    {
        // No `@` → can't possibly be a Sendvery address; must not throw.
        $resolver = $this->resolverWithCheck(null);
        self::assertFalse($resolver->isSendveryAddress('not-an-email'));
        self::assertFalse($resolver->isSendveryAddress(''));
    }

    #[Test]
    public function isSendveryAddressCaseInsensitive(): void
    {
        $resolver = $this->resolverWithCheck(null);
        self::assertTrue($resolver->isSendveryAddress('REPORTS@SENDVERY.COM'));
        self::assertTrue($resolver->isSendveryAddress('John@SendVery.Com'));
    }

    /**
     * Mock the repository so each branch is testable without Doctrine. The
     * resolver only ever calls `findLatestForDomainAndType` on it, so a
     * single stubbed method is enough.
     */
    private function resolverWithCheck(?DnsCheckResult $check): RuaScenarioResolver
    {
        $repo = $this->createStub(DnsCheckResultRepository::class);
        $repo->method('findLatestForDomainAndType')->willReturn($check);

        return new RuaScenarioResolver(
            $repo,
            new DmarcRecordParser(),
            new ReportAddressProvider('reports@sendvery.com'),
            // Per-domain resolution doesn't go through DBAL — a stubbed
            // Connection is enough to satisfy the constructor signature.
            $this->createStub(Connection::class),
        );
    }

    /**
     * Builds a DnsCheckResult stub with only the rawRecord populated — the
     * resolver doesn't read any other field.
     */
    private function buildCheck(?string $rawRecord): DnsCheckResult
    {
        // Bypass the full constructor (which requires a fully-formed
        // MonitoredDomain + flushes a DomainAdded event) — we only need to
        // expose `$rawRecord` on a real `DnsCheckResult` instance.
        $reflection = new \ReflectionClass(DnsCheckResult::class);
        $check = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('rawRecord')->setValue($check, $rawRecord);

        return $check;
    }
}
