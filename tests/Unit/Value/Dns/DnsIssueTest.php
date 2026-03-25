<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DnsIssue;
use App\Value\Dns\IssueSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DnsIssueTest extends TestCase
{
    #[Test]
    public function can_be_constructed(): void
    {
        $issue = new DnsIssue(IssueSeverity::Critical, 'Something is wrong', 'Fix it');

        self::assertSame(IssueSeverity::Critical, $issue->severity);
        self::assertSame('Something is wrong', $issue->message);
        self::assertSame('Fix it', $issue->recommendation);
    }

    #[Test]
    public function recommendation_defaults_to_empty(): void
    {
        $issue = new DnsIssue(IssueSeverity::Info, 'Just info');

        self::assertSame('', $issue->recommendation);
    }
}
