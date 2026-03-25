<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MagicLinkToken;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class MagicLinkTokenTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $expiresAt = new \DateTimeImmutable('+15 minutes');
        $createdAt = new \DateTimeImmutable();

        $token = new MagicLinkToken(
            id: $id,
            email: 'user@example.com',
            token: 'abc123',
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );

        self::assertSame($id, $token->id);
        self::assertSame('user@example.com', $token->email);
        self::assertSame('abc123', $token->token);
        self::assertSame($expiresAt, $token->expiresAt);
        self::assertSame($createdAt, $token->createdAt);
        self::assertNull($token->user);
        self::assertNull($token->usedAt);
    }

    public function testIsExpiredReturnsTrueWhenPastExpiry(): void
    {
        $token = $this->createToken(expiresAt: new \DateTimeImmutable('2026-01-01 12:00:00'));

        self::assertTrue($token->isExpired(new \DateTimeImmutable('2026-01-01 12:00:01')));
    }

    public function testIsExpiredReturnsFalseWhenBeforeExpiry(): void
    {
        $token = $this->createToken(expiresAt: new \DateTimeImmutable('2026-01-01 12:00:00'));

        self::assertFalse($token->isExpired(new \DateTimeImmutable('2026-01-01 11:59:59')));
    }

    public function testIsUsedReturnsFalseByDefault(): void
    {
        $token = $this->createToken();

        self::assertFalse($token->isUsed());
    }

    public function testMarkUsedSetsUsedAt(): void
    {
        $token = $this->createToken();
        $now = new \DateTimeImmutable();

        $token->markUsed($now);

        self::assertTrue($token->isUsed());
        self::assertSame($now, $token->usedAt);
    }

    public function testIsUsedReturnsTrueWhenUsedAtIsSet(): void
    {
        $token = $this->createToken(usedAt: new \DateTimeImmutable());

        self::assertTrue($token->isUsed());
    }

    private function createToken(
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $usedAt = null,
    ): MagicLinkToken {
        return new MagicLinkToken(
            id: Uuid::uuid7(),
            email: 'test@example.com',
            token: bin2hex(random_bytes(32)),
            expiresAt: $expiresAt ?? new \DateTimeImmutable('+15 minutes'),
            createdAt: new \DateTimeImmutable(),
            usedAt: $usedAt,
        );
    }
}
