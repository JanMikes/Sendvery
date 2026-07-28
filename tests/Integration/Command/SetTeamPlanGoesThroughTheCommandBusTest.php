<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\ReleaseQuarantinedReportsForTeam;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * `sendvery:team:set-plan` is the staff override for the Stripe path. Writing
 * `team.plan` by hand looks equivalent and is not: everything a plan change is
 * supposed to *cause* lives in UpgradeTeamPlanHandler and DowngradeTeamPlanHandler.
 *
 * Granting a bigger plan by hand therefore left the customer's parked reports
 * parked until the midnight `sendvery:usage:reset` — the support ticket the
 * grant was answering stayed open overnight. Taking a plan away by hand left the
 * Stripe subscription link dangling and left auto-ramp running on managed
 * domains for a team that had just lost the entitlement.
 */
final class SetTeamPlanGoesThroughTheCommandBusTest extends IntegrationTestCase
{
    #[Test]
    public function aStaffGrantedUpgradeAsksForTheReportsTheOldCapWithheld(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, 'staff-upgrade-'.Uuid::uuid7()->toString());

        $tester = $this->commandTester();
        $tester->execute(['team' => $team->id->toString(), 'plan' => 'business']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $releases = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->asyncTransport()->getSent()),
            static fn (object $message): bool => $message instanceof ReleaseQuarantinedReportsForTeam,
        ));

        self::assertCount(
            1,
            $releases,
            'A staff-granted upgrade buys the same headroom a Stripe upgrade does, so it must hand back the parked '
            .'reports now rather than at the next nightly usage reset.',
        );
        self::assertTrue($team->id->equals($releases[0]->teamId));
    }

    #[Test]
    public function aStaffGrantedUpgradeStillRecordsThePlanAndClearsTheApproachingLimitWarning(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, 'staff-upgrade-plan-'.Uuid::uuid7()->toString());
        $team->planWarningAt = new \DateTimeImmutable('2026-01-01 12:00:00');
        $em->flush();

        $tester = $this->commandTester();
        $tester->execute(['team' => $team->id->toString(), 'plan' => 'unlimited']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('unlimited', $reloaded->plan);
        self::assertNull($reloaded->planWarningAt);
    }

    #[Test]
    public function aStaffGrantOnTopOfAPaidSubscriptionKeepsTheStripeLink(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, 'staff-grant-keeps-stripe-'.Uuid::uuid7()->toString());
        $team->stripeSubscriptionId = 'sub_paying_customer';
        $team->stripeCustomerId = 'cus_paying_customer';
        $em->flush();

        $tester = $this->commandTester();
        $tester->execute(['team' => $team->id->toString(), 'plan' => 'unlimited']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('sub_paying_customer', $reloaded->stripeSubscriptionId, 'A staff grant has no Stripe subscription of its own; it must not orphan the one the customer is paying on.');
        self::assertSame('cus_paying_customer', $reloaded->stripeCustomerId);
    }

    #[Test]
    public function takingAPlanAwayByHandFreezesManagedDmarcAndDropsTheSubscriptionLink(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, 'staff-downgrade-'.Uuid::uuid7()->toString());
        $team->plan = 'business';
        $team->stripeSubscriptionId = 'sub_cancelled_by_staff';
        $team->billingInterval = 'month';

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'managed-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = DmarcPolicy::Quarantine;
        $domain->autoRampStage = AutoRampStage::Quarantine;
        $domain->autoRampEnabled = true;
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        $tester = $this->commandTester();
        $tester->execute(['team' => $team->id->toString(), 'plan' => 'free']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $em->clear();

        $reloadedTeam = $em->find(Team::class, $team->id);
        self::assertNotNull($reloadedTeam);
        self::assertSame('free', $reloadedTeam->plan);
        self::assertNull($reloadedTeam->stripeSubscriptionId, 'A hand-made downgrade must clear the subscription link exactly as a Stripe cancellation does, or the team looks like it is still paying.');
        self::assertNull($reloadedTeam->billingInterval);

        $reloadedDomain = $em->find(MonitoredDomain::class, $domain->id);
        self::assertNotNull($reloadedDomain);
        self::assertNotNull($reloadedDomain->autoRampPausedAt, 'DEC-058: losing the entitlement freezes auto-ramp on every managed domain.');
        self::assertSame(DmarcPolicy::Quarantine, $reloadedDomain->managedPolicyP, 'Freezing is not loosening: the protection the customer already has stays live.');
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function commandTester(): CommandTester
    {
        self::bootKernel();
        \assert(null !== self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('sendvery:team:set-plan'));
    }

    private function createTeam(EntityManagerInterface $em, string $slug): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Set Plan Bus Test',
            slug: $slug,
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }
}
