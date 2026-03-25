<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\MarkSenderAuthorized;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class MarkSenderAuthorizedTest extends TestCase
{
    #[Test]
    public function constructorSetsFields(): void
    {
        $senderId = Uuid::uuid7();

        $message = new MarkSenderAuthorized(
            senderId: $senderId,
            isAuthorized: true,
        );

        self::assertSame($senderId, $message->senderId);
        self::assertTrue($message->isAuthorized);
    }
}
