<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\MailboxPollCompleted;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class MailboxPollCompletedTest extends TestCase
{
    public function testProperties(): void
    {
        $connectionId = Uuid::uuid7();

        $event = new MailboxPollCompleted($connectionId, 5, 2);

        self::assertSame($connectionId, $event->connectionId);
        self::assertSame(5, $event->reportsFound);
        self::assertSame(2, $event->errors);
    }
}
