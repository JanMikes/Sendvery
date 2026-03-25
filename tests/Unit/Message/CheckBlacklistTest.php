<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\CheckBlacklist;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CheckBlacklistTest extends TestCase
{
    #[Test]
    public function constructorSetsFields(): void
    {
        $domainId = Uuid::uuid7();

        $message = new CheckBlacklist(
            domainId: $domainId,
            ipAddress: '1.2.3.4',
        );

        self::assertSame($domainId, $message->domainId);
        self::assertSame('1.2.3.4', $message->ipAddress);
    }
}
