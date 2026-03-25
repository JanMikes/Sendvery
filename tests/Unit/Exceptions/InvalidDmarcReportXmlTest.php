<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\InvalidDmarcReportXml;
use PHPUnit\Framework\TestCase;

final class InvalidDmarcReportXmlTest extends TestCase
{
    public function testIsARuntimeException(): void
    {
        $exception = new InvalidDmarcReportXml('Bad XML');

        self::assertSame(\RuntimeException::class, get_parent_class($exception));
        self::assertSame('Bad XML', $exception->getMessage());
    }
}
