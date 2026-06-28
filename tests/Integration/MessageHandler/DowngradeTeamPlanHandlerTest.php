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

    public function testFreezesManagedDmarcButLeavesThePolicyLive(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(DowngradeTeamPlanHandler::class);

        $teamId = Uuid::uuid7();
        $team = new Team(id: $teamId, name: 'Freeze', slug: 'freeze-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro', stripeSubscriptionId: 'sub_x');
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new \App\Entity\MonitoredDomain(id: $domainId, team: $team, domain: 'frozen.example', createdAt: new \DateTimeImmutable());
        $domain->dmarcSetupMode = \App\Value\Dns\DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = \App\Value\DmarcPolicy::Quarantine;
        $domain->autoRampStage = \App\Value\Dns\AutoRampStage::Quarantine;
        $domain->autoRampEnabled = true;
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        $handler(new DowngradeTeamPlan(teamId: $teamId));
        $em->flush();
        $em->clear();

        $updated = $em->find(\App\Entity\MonitoredDomain::class, $domainId);
        self::assertNotNull($updated);
        self::assertNotNull($updated->autoRampPausedAt, 'Auto-ramp is frozen on downgrade.');
        self::assertSame(\App\Value\DmarcPolicy::Quarantine, $updated->managedPolicyP, 'The live policy is never loosened — protection keeps working.');

        // A freeze is not a regression — no Critical regression alert.
        $alerts = $em->getRepository(\App\Entity\Alert::class)->findBy(['team' => $teamId->toString()]);
        self::assertCount(0, $alerts);
    }
}
