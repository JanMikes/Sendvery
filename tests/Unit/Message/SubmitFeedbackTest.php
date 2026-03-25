<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\SubmitFeedback;
use App\Value\FeedbackType;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SubmitFeedbackTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $feedbackId = Uuid::uuid7();
        $userId = Uuid::uuid7();
        $teamId = Uuid::uuid7();

        $message = new SubmitFeedback(
            feedbackId: $feedbackId,
            userId: $userId,
            teamId: $teamId,
            type: FeedbackType::FeatureRequest,
            message: 'Add dark mode',
            page: '/app/domains',
        );

        self::assertSame($feedbackId, $message->feedbackId);
        self::assertSame($userId, $message->userId);
        self::assertSame($teamId, $message->teamId);
        self::assertSame(FeedbackType::FeatureRequest, $message->type);
        self::assertSame('Add dark mode', $message->message);
        self::assertSame('/app/domains', $message->page);
    }
}
