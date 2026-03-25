<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class FeedbackTest extends WebTestCase
{
    #[Test]
    public function feedbackSubmissionRedirects(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/app/feedback', [
            'type' => 'bug',
            'message' => 'Something is broken',
            'page' => '/app/domains',
        ]);

        self::assertResponseRedirects('/app');
    }

    #[Test]
    public function feedbackWithEmptyMessageRedirects(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/app/feedback', [
            'type' => 'general',
            'message' => '',
            'page' => '/app',
        ]);

        self::assertResponseRedirects('/app');
    }

    #[Test]
    public function feedbackWidgetPresentInDashboard(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="feedback"]');
    }

    protected function createAuthenticatedClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'feedback-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Feedback Test',
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

        $client->loginUser($user);

        return $client;
    }
}
