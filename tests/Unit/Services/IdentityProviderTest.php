<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\IdentityProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\UuidInterface;

final class IdentityProviderTest extends TestCase
{
    public function testNextIdentityReturnsUuid(): void
    {
        $provider = new IdentityProvider();

        $uuid = $provider->nextIdentity();

        self::assertInstanceOf(UuidInterface::class, $uuid);
    }

    public function testNextIdentityReturnsDifferentUuidsEachTime(): void
    {
        $provider = new IdentityProvider();

        $first = $provider->nextIdentity();
        $second = $provider->nextIdentity();

        self::assertFalse($first->equals($second));
    }

    public function testNextIdentityReturnsUuidV7(): void
    {
        $provider = new IdentityProvider();

        $uuid = $provider->nextIdentity();

        self::assertSame(7, $uuid->getFields()->getVersion());
    }
}
