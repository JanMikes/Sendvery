<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OnboardingTrackerTest extends TestCase
{
    public function testIsOnboardingCompleteReturnsFalseForNewUser(): void
    {
        $user = new User(
            id: Uuid::uuid7(),
            email: 'user@example.com',
            createdAt: new \DateTimeImmutable(),
        );

        self::assertNull($user->onboardingCompletedAt);
    }

    public function testIsOnboardingCompleteReturnsTrueWhenSet(): void
    {
        $user = new User(
            id: Uuid::uuid7(),
            email: 'user@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );

        self::assertNotNull($user->onboardingCompletedAt);
    }
}
