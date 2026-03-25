<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\BlacklistCheckCompleted;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class BlacklistCheckCompletedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $domainId = Uuid::uuid7();

        $event = new BlacklistCheckCompleted(
            domainId: $domainId,
            ipAddress: '1.2.3.4',
            isListed: true,
            listedOn: ['zen.spamhaus.org', 'dnsbl.sorbs.net'],
        );

        self::assertSame($domainId, $event->domainId);
        self::assertSame('1.2.3.4', $event->ipAddress);
        self::assertTrue($event->isListed);
        self::assertSame(['zen.spamhaus.org', 'dnsbl.sorbs.net'], $event->listedOn);
    }
}
