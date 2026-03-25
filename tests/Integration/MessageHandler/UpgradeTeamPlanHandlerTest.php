<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Message\UpgradeTeamPlan;
use App\MessageHandler\UpgradeTeamPlanHandler;
use App\Repository\TeamRepository;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class UpgradeTeamPlanHandlerTest extends IntegrationTestCase
{
    public function testUpgradesTeamPlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(UpgradeTeamPlanHandler::class);
        $teamRepo = $this->getService(TeamRepository::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Upgrade Test',
            slug: 'upgrade-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        self::assertSame('free', $team->plan);

        $handler(new UpgradeTeamPlan(
            teamId: $teamId,
            plan: SubscriptionPlan::Personal,
            stripeSubscriptionId: 'sub_test_123',
            stripeCustomerId: 'cus_test_456',
        ));
        $em->flush();

        $updated = $teamRepo->get($teamId);
        self::assertSame('personal', $updated->plan);
        self::assertSame('sub_test_123', $updated->stripeSubscriptionId);
        self::assertSame('cus_test_456', $updated->stripeCustomerId);
        self::assertNull($updated->planWarningAt);
    }

    public function testClearsPlanWarningOnUpgrade(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(UpgradeTeamPlanHandler::class);
        $teamRepo = $this->getService(TeamRepository::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Warning Clear Test',
            slug: 'warning-clear-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            planWarningAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        self::assertNotNull($team->planWarningAt);

        $handler(new UpgradeTeamPlan(
            teamId: $teamId,
            plan: SubscriptionPlan::Team,
            stripeSubscriptionId: 'sub_test_789',
            stripeCustomerId: 'cus_test_abc',
        ));
        $em->flush();

        $updated = $teamRepo->get($teamId);
        self::assertNull($updated->planWarningAt);
    }
}
