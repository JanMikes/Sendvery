<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\BetaSignup;
use App\Events\BetaSignupCreated;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class BetaSignupTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $signedUpAt = new \DateTimeImmutable('2026-03-25 10:00:00');

        $signup = new BetaSignup(
            id: $id,
            email: 'test@example.com',
            domainCount: 5,
            painPoint: 'SPF is confusing',
            source: 'homepage',
            signedUpAt: $signedUpAt,
            confirmationToken: 'abc123token',
        );

        self::assertSame($id, $signup->id);
        self::assertSame('test@example.com', $signup->email);
        self::assertSame(5, $signup->domainCount);
        self::assertSame('SPF is confusing', $signup->painPoint);
        self::assertSame('homepage', $signup->source);
        self::assertSame($signedUpAt, $signup->signedUpAt);
        self::assertNull($signup->confirmedAt);
        self::assertSame('abc123token', $signup->confirmationToken);
    }

    public function testConstructorWithNullableFields(): void
    {
        $signup = $this->createSignup();

        self::assertNull($signup->domainCount);
        self::assertNull($signup->painPoint);
    }

    public function testConfirmSetsConfirmedAt(): void
    {
        $signup = $this->createSignup();
        $confirmedAt = new \DateTimeImmutable('2026-03-25 12:00:00');

        $signup->confirm($confirmedAt);

        self::assertSame($confirmedAt, $signup->confirmedAt);
    }

    public function testRecordsBetaSignupCreatedEvent(): void
    {
        $id = Uuid::uuid7();

        $signup = new BetaSignup(
            id: $id,
            email: 'test@example.com',
            domainCount: null,
            painPoint: null,
            source: 'beta-page',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: 'token123',
        );

        $events = $signup->popEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(BetaSignupCreated::class, $events[0]);
        self::assertSame($id, $events[0]->signupId);
        self::assertSame('test@example.com', $events[0]->email);
        self::assertSame('token123', $events[0]->confirmationToken);
    }

    private function createSignup(): BetaSignup
    {
        return new BetaSignup(
            id: Uuid::uuid7(),
            email: 'user@example.com',
            domainCount: null,
            painPoint: null,
            source: 'homepage',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: 'testtoken',
        );
    }
}
