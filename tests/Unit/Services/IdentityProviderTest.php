<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\IdentityProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Rfc4122\FieldsInterface;

final class IdentityProviderTest extends TestCase
{
    public function testNextIdentityReturnsUuid(): void
    {
        $provider = new IdentityProvider();

        $uuid = $provider->nextIdentity();

        self::assertNotEmpty($uuid->toString());
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

        $fields = $uuid->getFields();
        assert($fields instanceof FieldsInterface);
        self::assertSame(7, $fields->getVersion());
    }
}
