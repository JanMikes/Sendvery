<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DmarcCheckResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DmarcCheckResultTest extends TestCase
{
    #[Test]
    public function is_passing_with_reject_policy_and_rua(): void
    {
        $result = new DmarcCheckResult(
            'v=DMARC1; p=reject; rua=mailto:d@ex.com',
            'reject',
            null,
            ['d@ex.com'],
            [],
            null,
            null,
            null,
            [],
            [],
        );

        self::assertTrue($result->isPassing());
        self::assertTrue($result->isEnforcing());
    }

    #[Test]
    public function is_not_passing_with_none_policy(): void
    {
        $result = new DmarcCheckResult(
            'v=DMARC1; p=none',
            'none',
            null,
            [],
            [],
            null,
            null,
            null,
            [],
            [],
        );

        self::assertFalse($result->isPassing());
        self::assertFalse($result->isEnforcing());
    }

    #[Test]
    public function quarantine_is_enforcing(): void
    {
        $result = new DmarcCheckResult(
            'v=DMARC1; p=quarantine; rua=mailto:d@ex.com',
            'quarantine',
            null,
            ['d@ex.com'],
            [],
            null,
            null,
            null,
            [],
            [],
        );

        self::assertTrue($result->isPassing());
        self::assertTrue($result->isEnforcing());
    }

    #[Test]
    public function has_record_returns_false_when_null(): void
    {
        $result = new DmarcCheckResult(null, null, null, [], [], null, null, null, [], []);

        self::assertFalse($result->hasRecord());
    }
}
