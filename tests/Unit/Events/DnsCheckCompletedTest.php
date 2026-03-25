<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\DnsCheckCompleted;
use App\Value\DnsCheckType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DnsCheckCompletedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $checkId = Uuid::uuid7();
        $domainId = Uuid::uuid7();
        $teamId = Uuid::uuid7();

        $event = new DnsCheckCompleted(
            dnsCheckResultId: $checkId,
            domainId: $domainId,
            teamId: $teamId,
            type: DnsCheckType::Spf,
            hasChanged: true,
            isValid: false,
            rawRecord: 'v=spf1 ~all',
            previousRawRecord: 'v=spf1 -all',
        );

        self::assertSame($checkId, $event->dnsCheckResultId);
        self::assertSame($domainId, $event->domainId);
        self::assertSame($teamId, $event->teamId);
        self::assertSame(DnsCheckType::Spf, $event->type);
        self::assertTrue($event->hasChanged);
        self::assertFalse($event->isValid);
        self::assertSame('v=spf1 ~all', $event->rawRecord);
        self::assertSame('v=spf1 -all', $event->previousRawRecord);
    }
}
