<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\RequestMagicLink;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class RequestMagicLinkTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $tokenId = Uuid::uuid7();

        $message = new RequestMagicLink(
            tokenId: $tokenId,
            email: 'user@example.com',
        );

        self::assertSame($tokenId, $message->tokenId);
        self::assertSame('user@example.com', $message->email);
    }
}
