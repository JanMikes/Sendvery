<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\RegisterBetaSignup;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class RegisterBetaSignupTest extends TestCase
{
    public function testProperties(): void
    {
        $signupId = Uuid::uuid7();

        $command = new RegisterBetaSignup(
            signupId: $signupId,
            email: 'test@example.com',
            domainCount: 10,
            painPoint: 'DNS management',
            source: 'homepage',
        );

        self::assertSame($signupId, $command->signupId);
        self::assertSame('test@example.com', $command->email);
        self::assertSame(10, $command->domainCount);
        self::assertSame('DNS management', $command->painPoint);
        self::assertSame('homepage', $command->source);
    }

    public function testNullableProperties(): void
    {
        $signupId = Uuid::uuid7();

        $command = new RegisterBetaSignup(
            signupId: $signupId,
            email: 'test@example.com',
            domainCount: null,
            painPoint: null,
            source: 'beta-page',
        );

        self::assertNull($command->domainCount);
        self::assertNull($command->painPoint);
    }
}
