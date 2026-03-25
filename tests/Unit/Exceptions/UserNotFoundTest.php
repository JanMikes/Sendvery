<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\UserNotFound;
use PHPUnit\Framework\TestCase;

final class UserNotFoundTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $exception = new UserNotFound('User not found');

        self::assertInstanceOf(\DomainException::class, $exception);
        self::assertSame('User not found', $exception->getMessage());
    }
}
