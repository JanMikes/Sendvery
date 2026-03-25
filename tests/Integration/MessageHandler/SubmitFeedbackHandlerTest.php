<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Entity\UserFeedback;
use App\Message\SubmitFeedback;
use App\MessageHandler\SubmitFeedbackHandler;
use App\Tests\IntegrationTestCase;
use App\Value\FeedbackType;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class SubmitFeedbackHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function createsFeedbackEntity(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(SubmitFeedbackHandler::class);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'feedback-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Feedback Test Team',
            slug: 'feedback-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);
        $em->flush();

        $feedbackId = Uuid::uuid7();
        $handler(new SubmitFeedback(
            feedbackId: $feedbackId,
            userId: $user->id,
            teamId: $team->id,
            type: FeedbackType::Bug,
            message: 'DNS checker page crashes',
            page: '/app/domains',
        ));

        $feedback = $em->find(UserFeedback::class, $feedbackId);
        self::assertNotNull($feedback);
        self::assertSame(FeedbackType::Bug, $feedback->type);
        self::assertSame('DNS checker page crashes', $feedback->message);
        self::assertSame('/app/domains', $feedback->page);
    }
}
