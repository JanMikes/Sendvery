<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\MailboxConnectionNotFound;
use PHPUnit\Framework\TestCase;

final class MailboxConnectionNotFoundTest extends TestCase
{
    public function testIsADomainException(): void
    {
        $exception = new MailboxConnectionNotFound('Not found');

        self::assertSame(\DomainException::class, get_parent_class($exception));
        self::assertSame('Not found', $exception->getMessage());
    }
}
