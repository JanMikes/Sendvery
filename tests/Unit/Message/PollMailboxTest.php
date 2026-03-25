<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\PollMailbox;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PollMailboxTest extends TestCase
{
    public function testProperties(): void
    {
        $connectionId = Uuid::uuid7();

        $message = new PollMailbox(connectionId: $connectionId);

        self::assertSame($connectionId, $message->connectionId);
    }
}
