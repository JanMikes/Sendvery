<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DkimCheckResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DkimCheckResultTest extends TestCase
{
    #[Test]
    public function isPassingWithStrongKey(): void
    {
        $result = new DkimCheckResult('v=DKIM1; k=rsa; p=...', true, 'rsa', 2048, 'google', [], []);

        self::assertTrue($result->isPassing());
    }

    #[Test]
    public function isNotPassingWithWeakKey(): void
    {
        $result = new DkimCheckResult('v=DKIM1; k=rsa; p=...', true, 'rsa', 1024, 'google', [], []);

        self::assertFalse($result->isPassing());
    }

    #[Test]
    public function isNotPassingWhenKeyMissing(): void
    {
        $result = new DkimCheckResult(null, false, null, null, 'default', [], []);

        self::assertFalse($result->isPassing());
    }
}
