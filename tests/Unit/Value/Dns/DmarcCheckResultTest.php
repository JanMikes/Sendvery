<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DmarcCheckResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DmarcCheckResultTest extends TestCase
{
    #[Test]
    public function isPassingWithRejectPolicyAndRua(): void
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
    public function isNotPassingWithNonePolicy(): void
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
    public function quarantineIsEnforcing(): void
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
    public function hasRecordReturnsFalseWhenNull(): void
    {
        $result = new DmarcCheckResult(null, null, null, [], [], null, null, null, [], []);

        self::assertFalse($result->hasRecord());
    }
}
