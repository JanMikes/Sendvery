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
    public function has_record_returns_true_when_record_present(): void
    {
        $result = new SpfCheckResult('v=spf1 ~all', true, 1, 0, [], [], []);

        self::assertTrue($result->hasRecord());
    }

    #[Test]
    public function has_record_returns_false_when_null(): void
    {
        $result = new SpfCheckResult(null, false, 0, 0, [], [], []);

        self::assertFalse($result->hasRecord());
    }

    #[Test]
    public function is_passing_when_valid_and_under_limit(): void
    {
        $result = new SpfCheckResult('v=spf1 ~all', true, 1, 5, [], [], []);

        self::assertTrue($result->isPassing());
    }

    #[Test]
    public function is_not_passing_when_over_lookup_limit(): void
    {
        $result = new SpfCheckResult('v=spf1 ~all', true, 1, 11, [], [], []);

        self::assertFalse($result->isPassing());
    }

    #[Test]
    public function is_not_passing_when_invalid(): void
    {
        $result = new SpfCheckResult('v=spf1 ~all', false, 1, 5, [], [], []);

        self::assertFalse($result->isPassing());
    }

    #[Test]
    public function is_not_passing_with_critical_issues(): void
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
