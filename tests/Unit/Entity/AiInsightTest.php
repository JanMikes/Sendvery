<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AiInsight;
use App\Value\AiInsightType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AiInsightTest extends TestCase
{
    #[Test]
    public function itStoresTheCachedInsightWithANullTeamForGlobalSenderLabels(): void
    {
        $id = Uuid::uuid7();
        $createdAt = new \DateTimeImmutable('2026-05-29 12:00:00');

        $insight = new AiInsight(
            id: $id,
            team: null,
            type: AiInsightType::SenderLabel,
            subjectId: '192.0.2.1',
            cacheKey: 'sender_label:192.0.2.1:acme.example',
            content: ['label' => 'SendGrid', 'confidence' => 0.9],
            createdAt: $createdAt,
        );

        self::assertSame($id, $insight->id);
        self::assertNull($insight->team);
        self::assertSame(AiInsightType::SenderLabel, $insight->type);
        self::assertSame('192.0.2.1', $insight->subjectId);
        self::assertSame('sender_label:192.0.2.1:acme.example', $insight->cacheKey);
        self::assertSame(['label' => 'SendGrid', 'confidence' => 0.9], $insight->content);
        self::assertSame($createdAt, $insight->createdAt);
    }
}
