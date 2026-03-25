<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\UserRegistered;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UserRegisteredTest extends TestCase
{
    public function testProperties(): void
    {
        $userId = Uuid::uuid7();

        $event = new UserRegistered($userId, 'test@example.com');

        self::assertSame($userId, $event->userId);
        self::assertSame('test@example.com', $event->email);
    }
}
