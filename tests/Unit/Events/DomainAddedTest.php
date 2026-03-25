<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\DomainAdded;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DomainAddedTest extends TestCase
{
    public function testProperties(): void
    {
        $domainId = Uuid::uuid7();
        $teamId = Uuid::uuid7();

        $event = new DomainAdded($domainId, $teamId);

        self::assertSame($domainId, $event->domainId);
        self::assertSame($teamId, $event->teamId);
    }
}
