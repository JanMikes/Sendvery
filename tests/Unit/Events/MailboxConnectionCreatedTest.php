<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\MailboxConnectionCreated;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class MailboxConnectionCreatedTest extends TestCase
{
    public function testProperties(): void
    {
        $connectionId = Uuid::uuid7();
        $teamId = Uuid::uuid7();

        $event = new MailboxConnectionCreated($connectionId, $teamId);

        self::assertSame($connectionId, $event->connectionId);
        self::assertSame($teamId, $event->teamId);
    }
}
