<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DkimCheckResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DkimCheckResultTest extends TestCase
{
    #[Test]
    public function is_passing_with_strong_key(): void
    {
        $result = new DkimCheckResult('v=DKIM1; k=rsa; p=...', true, 'rsa', 2048, 'google', [], []);

        self::assertTrue($result->isPassing());
    }

    #[Test]
    public function is_not_passing_with_weak_key(): void
    {
        $result = new DkimCheckResult('v=DKIM1; k=rsa; p=...', true, 'rsa', 1024, 'google', [], []);

        self::assertFalse($result->isPassing());
    }

    #[Test]
    public function is_not_passing_when_key_missing(): void
    {
        $result = new DkimCheckResult(null, false, null, null, 'default', [], []);

        self::assertFalse($result->isPassing());
    }
}
