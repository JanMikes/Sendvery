<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\AddDomain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AddDomainTest extends TestCase
{
    #[Test]
    public function itCanBeConstructed(): void
    {
        $domainId = Uuid::uuid7();
        $teamId = Uuid::uuid7();

        $message = new AddDomain(
            domainId: $domainId,
            teamId: $teamId,
            domainName: 'example.com',
        );

        self::assertSame($domainId, $message->domainId);
        self::assertSame($teamId, $message->teamId);
        self::assertSame('example.com', $message->domainName);
    }
}
