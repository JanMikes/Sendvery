<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\IssueSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssueSeverityTest extends TestCase
{
    #[Test]
    public function hasExpectedCases(): void
    {
        self::assertSame('info', IssueSeverity::Info->value);
        self::assertSame('warning', IssueSeverity::Warning->value);
        self::assertSame('critical', IssueSeverity::Critical->value);
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(IssueSeverity::Critical, IssueSeverity::from('critical'));
    }
}
