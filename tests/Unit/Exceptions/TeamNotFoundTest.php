<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\TeamNotFound;
use PHPUnit\Framework\TestCase;

final class TeamNotFoundTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $exception = new TeamNotFound('Team not found');

        self::assertSame(\DomainException::class, get_parent_class($exception));
        self::assertSame('Team not found', $exception->getMessage());
    }
}
