<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DkimCheckResult;
use App\Value\Dns\DmarcCheckResult;
use App\Value\Dns\EmailAuthCheckResult;
use App\Value\Dns\MxCheckResult;
use App\Value\Dns\SpfCheckResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailAuthCheckResultTest extends TestCase
{
    #[Test]
    public function hasDkimKeyReturnsTrueWhenFound(): void
    {
        $result = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult('v=spf1 ~all', true, 1, 0, [], [], []),
            [new DkimCheckResult('v=DKIM1; p=...', true, 'rsa', 2048, 'google', [], [])],
            new DmarcCheckResult('v=DMARC1; p=reject', 'reject', null, [], [], null, null, null, [], []),
            new MxCheckResult([], []),
        );

        self::assertTrue($result->hasDkimKey());
    }

    #[Test]
    public function hasDkimKeyReturnsFalseWhenNotFound(): void
    {
        $result = new EmailAuthCheckResult(
            'example.com',
            new SpfCheckResult(null, false, 0, 0, [], [], []),
            [new DkimCheckResult(null, false, null, null, 'default', [], [])],
            new DmarcCheckResult(null, null, null, [], [], null, null, null, [], []),
            new MxCheckResult([], []),
        );

        self::assertFalse($result->hasDkimKey());
    }
}
