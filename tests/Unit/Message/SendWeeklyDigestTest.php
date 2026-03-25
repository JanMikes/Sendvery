<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\SendWeeklyDigest;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SendWeeklyDigestTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $teamId = Uuid::uuid7();
        $message = new SendWeeklyDigest(teamId: $teamId);

        self::assertSame($teamId, $message->teamId);
    }
}
