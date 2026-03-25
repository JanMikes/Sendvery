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
        self::assertCount(3, AlertSeverity::cases());
    }
}
