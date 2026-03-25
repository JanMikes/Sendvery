<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\IssueSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssueSeverityTest extends TestCase
{
    #[Test]
    public function has_expected_cases(): void
    {
        self::assertSame('info', IssueSeverity::Info->value);
        self::assertSame('warning', IssueSeverity::Warning->value);
        self::assertSame('critical', IssueSeverity::Critical->value);
    }

    #[Test]
    public function can_be_created_from_string(): void
    {
        self::assertSame(IssueSeverity::Critical, IssueSeverity::from('critical'));
    }
}
