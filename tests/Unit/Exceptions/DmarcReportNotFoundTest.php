<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\DmarcReportNotFound;
use PHPUnit\Framework\TestCase;

final class DmarcReportNotFoundTest extends TestCase
{
    public function testIsADomainException(): void
    {
        $exception = new DmarcReportNotFound('Not found');

        self::assertInstanceOf(\DomainException::class, $exception);
        self::assertSame('Not found', $exception->getMessage());
    }
}
