<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\FeedbackType;
use Ramsey\Uuid\UuidInterface;

final readonly class SubmitFeedback
{
    public function __construct(
        public UuidInterface $feedbackId,
        public UuidInterface $userId,
        public UuidInterface $teamId,
        public FeedbackType $type,
        public string $message,
        public string $page,
    ) {
    }
}
