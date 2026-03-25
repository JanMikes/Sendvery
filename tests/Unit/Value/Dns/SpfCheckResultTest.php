<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DnsIssue;
use App\Value\Dns\IssueSeverity;
use App\Value\Dns\SpfCheckResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpfCheckResultTest extends TestCase
{
    #[Test]
    public function hasRecordReturnsTrueWhenRecordPresent(): void
    {
        $result = new SpfCheckResult('v=spf1 ~all', true, 1, 0, [], [], []);

        self::assertTrue($result->hasRecord());
    }

    #[Test]
    public function hasRecordReturnsFalseWhenNull(): void
    {
        $result = new SpfCheckResult(null, false, 0, 0, [], [], []);

        self::assertFalse($result->hasRecord());
    }

    #[Test]
    public function isPassingWhenValidAndUnderLimit(): void
    {
        $result = new SpfCheckResult('v=spf1 ~all', true, 1, 5, [], [], []);

        self::assertTrue($result->isPassing());
    }

    #[Test]
    public function isNotPassingWhenOverLookupLimit(): void
    {
        $result = new SpfCheckResult('v=spf1 ~all', true, 1, 11, [], [], []);

        self::assertFalse($result->isPassing());
    }

    #[Test]
    public function isNotPassingWhenInvalid(): void
    {
        $result = new SpfCheckResult('v=spf1 ~all', false, 1, 5, [], [], []);

        self::assertFalse($result->isPassing());
    }

    #[Test]
    public function isNotPassingWithCriticalIssues(): void
    {
        $result = new SpfCheckResult(
            'v=spf1 +all',
            true,
            1,
            5,
            [],
            [new DnsIssue(IssueSeverity::Critical, '+all is dangerous')],
            [],
        );

        self::assertFalse($result->isPassing());
    }
}
