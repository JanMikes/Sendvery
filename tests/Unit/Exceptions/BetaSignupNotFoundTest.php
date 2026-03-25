<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\BetaSignupNotFound;
use PHPUnit\Framework\TestCase;

final class BetaSignupNotFoundTest extends TestCase
{
    public function testIsADomainException(): void
    {
        $exception = new BetaSignupNotFound('Not found');

        self::assertInstanceOf(\DomainException::class, $exception);
        self::assertSame('Not found', $exception->getMessage());
    }
}
