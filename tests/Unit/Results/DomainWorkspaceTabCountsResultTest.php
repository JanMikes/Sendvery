<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainWorkspaceTabCountsResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainWorkspaceTabCountsResultTest extends TestCase
{
    #[Test]
    public function fromDatabaseRowHandlesStringValuesFromPostgresDriver(): void
    {
        // DBAL + pgsql returns scalar subselects as strings.
        $result = DomainWorkspaceTabCountsResult::fromDatabaseRow([
            'reports_24h' => '5',
            'unauthorized_senders' => '3',
            'dns_failing' => '1',
            'blacklist_listed' => '2',
            'history_changed_7d' => '0',
        ]);

        self::assertSame(5, $result->reports24h);
        self::assertSame(3, $result->unauthorizedSenders);
        self::assertTrue($result->dnsFailing);
        self::assertSame(2, $result->blacklistListed);
        self::assertFalse($result->historyChanged7d);
    }

    #[Test]
    public function fromDatabaseRowHandlesNativeBoolBranch(): void
    {
        // Defensive branch in toBool() for drivers that return native bools.
        $result = DomainWorkspaceTabCountsResult::fromDatabaseRow([
            'reports_24h' => 0,
            'unauthorized_senders' => 0,
            'dns_failing' => true,
            'blacklist_listed' => 0,
            'history_changed_7d' => false,
        ]);

        self::assertTrue($result->dnsFailing);
        self::assertFalse($result->historyChanged7d);
    }

    #[Test]
    public function fromDatabaseRowHandlesMissingKeys(): void
    {
        // Null coalesce branch — every nullable field falls back to 0/false.
        $result = DomainWorkspaceTabCountsResult::fromDatabaseRow([
            'reports_24h' => null,
            'unauthorized_senders' => null,
            'dns_failing' => null,
            'blacklist_listed' => null,
            'history_changed_7d' => null,
        ]);

        self::assertSame(0, $result->reports24h);
        self::assertSame(0, $result->unauthorizedSenders);
        self::assertFalse($result->dnsFailing);
        self::assertSame(0, $result->blacklistListed);
        self::assertFalse($result->historyChanged7d);
    }

    #[Test]
    public function toTwigArrayCollapsesZerosToNull(): void
    {
        $result = new DomainWorkspaceTabCountsResult(
            reports24h: 0,
            unauthorizedSenders: 0,
            dnsFailing: false,
            blacklistListed: 0,
            historyChanged7d: false,
        );

        self::assertSame(
            [
                'reports' => null,
                'senders' => null,
                'dns' => null,
                'blacklist' => null,
                'history' => null,
                'overview' => null,
            ],
            $result->toTwigArray(),
        );
    }

    #[Test]
    public function toTwigArrayKeepsPositiveCountsAndMapsBoolsToOne(): void
    {
        $result = new DomainWorkspaceTabCountsResult(
            reports24h: 4,
            unauthorizedSenders: 2,
            dnsFailing: true,
            blacklistListed: 1,
            historyChanged7d: true,
        );

        self::assertSame(
            [
                'reports' => 4,
                'senders' => 2,
                'dns' => 1,
                'blacklist' => 1,
                'history' => 1,
                'overview' => null,
            ],
            $result->toTwigArray(),
        );
    }
}
