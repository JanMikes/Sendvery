<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\MonitoredDomainNotFound;
use PHPUnit\Framework\TestCase;

final class MonitoredDomainNotFoundTest extends TestCase
{
    public function testIsADomainException(): void
    {
        $exception = new MonitoredDomainNotFound('Not found');

        self::assertInstanceOf(\DomainException::class, $exception);
        self::assertSame('Not found', $exception->getMessage());
    }
}
