<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\SetSenderNote;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SetSenderNoteTest extends TestCase
{
    #[Test]
    public function constructorSetsFields(): void
    {
        $senderId = Uuid::uuid7();
        $teamId = Uuid::uuid7();
        $actorUserId = Uuid::uuid7();

        $message = new SetSenderNote(
            senderId: $senderId,
            teamId: $teamId,
            note: 'Mailchimp marketing IP.',
            actorUserId: $actorUserId,
        );

        self::assertSame($senderId, $message->senderId);
        self::assertSame($teamId, $message->teamId);
        self::assertSame('Mailchimp marketing IP.', $message->note);
        self::assertSame($actorUserId, $message->actorUserId);
    }

    #[Test]
    public function noteCanBeNull(): void
    {
        $message = new SetSenderNote(
            senderId: Uuid::uuid7(),
            teamId: Uuid::uuid7(),
            note: null,
            actorUserId: Uuid::uuid7(),
        );

        self::assertNull($message->note);
    }
}
