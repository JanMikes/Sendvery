<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Message\ReleaseQuarantinedReportsForTeam;
use App\Message\UpgradeTeamPlan;
use App\MessageHandler\UpgradeTeamPlanHandler;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Upgrading is the moment a customer buys more headroom. If nothing asks for
 * the reports the old cap withheld, the upgrade quietly fails to deliver the
 * thing it was bought for.
 */
final class UpgradeTeamPlanReleasesParkedReportsTest extends IntegrationTestCase
{
    #[Test]
    public function upgradingAskesForTheReportsTheOldCapWithheld(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Upgrade Release',
            slug: 'upgrade-release-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        $this->getService(UpgradeTeamPlanHandler::class)(new UpgradeTeamPlan(
            teamId: $teamId,
            plan: SubscriptionPlan::Pro,
            stripeSubscriptionId: 'sub_release_1',
            stripeCustomerId: 'cus_release_1',
        ));
        $em->flush();

        $releases = array_values(array_filter(
            array_map(
                static fn ($envelope): object => $envelope->getMessage(),
                $this->asyncTransport()->getSent(),
            ),
            static fn (object $message): bool => $message instanceof ReleaseQuarantinedReportsForTeam,
        ));

        self::assertCount(1, $releases, 'An upgrade must ask for the reports the previous cap parked.');
        self::assertTrue($teamId->equals($releases[0]->teamId));
    }

    #[Test]
    public function theReleaseIsQueuedRatherThanRunInsideTheStripeWebhook(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Upgrade Async',
            slug: 'upgrade-async-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        $this->getService(UpgradeTeamPlanHandler::class)(new UpgradeTeamPlan(
            teamId: $teamId,
            plan: SubscriptionPlan::Business,
            stripeSubscriptionId: 'sub_release_2',
            stripeCustomerId: 'cus_release_2',
        ));
        $em->flush();

        // Landing on the async transport is the assertion: a backlog can be
        // thousands of reports and Stripe needs its 200 immediately.
        $queued = array_filter(
            $this->asyncTransport()->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof ReleaseQuarantinedReportsForTeam,
        );

        self::assertNotSame([], $queued);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
