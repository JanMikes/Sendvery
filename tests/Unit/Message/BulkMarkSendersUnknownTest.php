<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\BulkMarkSendersUnknown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class BulkMarkSendersUnknownTest extends TestCase
{
    #[Test]
    public function constructorSetsFields(): void
    {
        $senderIds = [Uuid::uuid7(), Uuid::uuid7()];
        $teamId = Uuid::uuid7();
        $actorUserId = Uuid::uuid7();

        $message = new BulkMarkSendersUnknown(
            senderIds: $senderIds,
            teamId: $teamId,
            actorUserId: $actorUserId,
        );

        self::assertSame($senderIds, $message->senderIds);
        self::assertSame($teamId, $message->teamId);
        self::assertSame($actorUserId, $message->actorUserId);
    }
}
