<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AiModel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiModelTest extends TestCase
{
    #[Test]
    public function modelsUseBareAliasIdsSoWeAlwaysGetTheLatestSnapshot(): void
    {
        self::assertSame('claude-haiku-4-5', AiModel::Haiku->value);
        self::assertSame('claude-sonnet-4-6', AiModel::Sonnet->value);
        self::assertSame('claude-opus-4-8', AiModel::Opus->value);
        self::assertCount(3, AiModel::cases());
    }

    #[Test]
    public function eachTierCapsOutputTokensSoCostAndLatencyStayBounded(): void
    {
        self::assertSame(700, AiModel::Haiku->maxOutputTokens());
        self::assertSame(1200, AiModel::Sonnet->maxOutputTokens());
        self::assertSame(1500, AiModel::Opus->maxOutputTokens());
    }
}
