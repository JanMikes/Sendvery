<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Message\DowngradeTeamPlan;
use App\MessageHandler\DowngradeTeamPlanHandler;
use App\Repository\TeamRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class DowngradeTeamPlanHandlerTest extends IntegrationTestCase
{
    public function testDowngradesToFreePlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(DowngradeTeamPlanHandler::class);
        $teamRepo = $this->getService(TeamRepository::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Downgrade Test',
            slug: 'downgrade-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: 'personal',
            stripeSubscriptionId: 'sub_old_123',
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        self::assertSame('personal', $team->plan);

        $handler(new DowngradeTeamPlan(teamId: $teamId));
        $em->flush();

        $updated = $teamRepo->get($teamId);
        self::assertSame('free', $updated->plan);
        self::assertNull($updated->stripeSubscriptionId);
        self::assertNull($updated->planWarningAt);
    }
}
