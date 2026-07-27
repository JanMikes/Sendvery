<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AlertSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AlertSeverityTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        self::assertSame('info', AlertSeverity::Info->value);
        self::assertSame('warning', AlertSeverity::Warning->value);
        self::assertSame('critical', AlertSeverity::Critical->value);
        self::assertSame('success', AlertSeverity::Success->value);
        self::assertCount(4, AlertSeverity::cases());
    }

    #[Test]
    public function anUnrecognisedSeverityStringResolvesToNothingInsteadOfThrowing(): void
    {
        // A stale or hand-typed ?severity= value must degrade to "no filter"
        // rather than blow up the alerts list.
        self::assertNull(AlertSeverity::tryFrom('not-a-severity'));
        self::assertNull(AlertSeverity::tryFrom(''));
    }
}
