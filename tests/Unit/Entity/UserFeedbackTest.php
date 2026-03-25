<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Team;
use App\Entity\User;
use App\Entity\UserFeedback;
use App\Value\FeedbackType;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UserFeedbackTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $createdAt = new \DateTimeImmutable('2026-03-25');

        $user = new User(
            id: Uuid::uuid7(),
            email: 'user@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test Team',
            slug: 'test-team',
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();

        $feedback = new UserFeedback(
            id: $id,
            user: $user,
            team: $team,
            type: FeedbackType::Bug,
            message: 'Something is broken',
            page: '/app/domains',
            createdAt: $createdAt,
        );

        self::assertSame($id, $feedback->id);
        self::assertSame($user, $feedback->user);
        self::assertSame($team, $feedback->team);
        self::assertSame(FeedbackType::Bug, $feedback->type);
        self::assertSame('Something is broken', $feedback->message);
        self::assertSame('/app/domains', $feedback->page);
        self::assertSame($createdAt, $feedback->createdAt);
    }
}
