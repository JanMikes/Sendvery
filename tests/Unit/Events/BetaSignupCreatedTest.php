<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\BetaSignupCreated;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class BetaSignupCreatedTest extends TestCase
{
    public function testProperties(): void
    {
        $signupId = Uuid::uuid7();

        $event = new BetaSignupCreated($signupId, 'test@example.com', 'token123');

        self::assertSame($signupId, $event->signupId);
        self::assertSame('test@example.com', $event->email);
        self::assertSame('token123', $event->confirmationToken);
    }
}
