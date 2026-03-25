<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\FeedbackType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeedbackTypeTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        self::assertSame('bug', FeedbackType::Bug->value);
        self::assertSame('feature_request', FeedbackType::FeatureRequest->value);
        self::assertSame('general', FeedbackType::General->value);
        self::assertCount(3, FeedbackType::cases());
    }
}
