<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\BlacklistResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlacklistResultTest extends TestCase
{
    #[Test]
    public function listedResult(): void
    {
        $result = new BlacklistResult(
            ipAddress: '1.2.3.4',
            results: [
                'zen.spamhaus.org' => ['listed' => true, 'reason' => 'Spam source'],
                'b.barracudacentral.org' => ['listed' => false, 'reason' => null],
                'dnsbl.sorbs.net' => ['listed' => true, 'reason' => null],
            ],
            isListed: true,
        );

        self::assertSame('1.2.3.4', $result->ipAddress);
        self::assertTrue($result->isListed);
        self::assertSame(2, $result->listedCount());
        self::assertSame(3, $result->totalChecked());
    }

    #[Test]
    public function cleanResult(): void
    {
        $result = new BlacklistResult(
            ipAddress: '5.6.7.8',
            results: [
                'zen.spamhaus.org' => ['listed' => false, 'reason' => null],
                'b.barracudacentral.org' => ['listed' => false, 'reason' => null],
            ],
            isListed: false,
        );

        self::assertFalse($result->isListed);
        self::assertSame(0, $result->listedCount());
        self::assertSame(2, $result->totalChecked());
    }
}
