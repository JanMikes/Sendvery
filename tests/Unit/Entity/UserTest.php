<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Events\UserRegistered;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UserTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $createdAt = new \DateTimeImmutable('2026-03-25 10:00:00');

        $user = new User(
            id: $id,
            email: 'user@example.com',
            createdAt: $createdAt,
        );

        self::assertSame($id, $user->id);
        self::assertSame('user@example.com', $user->email);
        self::assertSame($createdAt, $user->createdAt);
        self::assertSame('en', $user->locale);
        self::assertNull($user->lastLoginAt);
        self::assertTrue($user->emailDigestEnabled);
        self::assertTrue($user->emailAlertsEnabled);
    }

    public function testConstructorWithOptionalFields(): void
    {
        $id = Uuid::uuid7();
        $createdAt = new \DateTimeImmutable();
        $lastLogin = new \DateTimeImmutable('2026-03-24');

        $user = new User(
            id: $id,
            email: 'user@example.com',
            createdAt: $createdAt,
            locale: 'cs',
            lastLoginAt: $lastLogin,
        );

        self::assertSame('cs', $user->locale);
        self::assertSame($lastLogin, $user->lastLoginAt);
    }

    public function testGetRolesReturnsRoleUser(): void
    {
        $user = $this->createUser();

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = $this->createUser();

        self::assertSame('user@example.com', $user->getUserIdentifier());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = $this->createUser();

        $user->eraseCredentials();

        self::assertSame('user@example.com', $user->email);
    }

    public function testEmailPreferencesCanBeDisabled(): void
    {
        $user = new User(
            id: Uuid::uuid7(),
            email: 'user@example.com',
            createdAt: new \DateTimeImmutable(),
            emailDigestEnabled: false,
            emailAlertsEnabled: false,
        );

        self::assertFalse($user->emailDigestEnabled);
        self::assertFalse($user->emailAlertsEnabled);
    }

    public function testRecordsUserRegisteredEvent(): void
    {
        $id = Uuid::uuid7();

        $user = new User(
            id: $id,
            email: 'test@example.com',
            createdAt: new \DateTimeImmutable(),
        );

        $events = $user->popEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(UserRegistered::class, $events[0]);
        self::assertSame($id, $events[0]->userId);
        self::assertSame('test@example.com', $events[0]->email);
    }

    private function createUser(): User
    {
        return new User(
            id: Uuid::uuid7(),
            email: 'user@example.com',
            createdAt: new \DateTimeImmutable(),
        );
    }
}
