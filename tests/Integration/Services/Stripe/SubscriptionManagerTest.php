<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Stripe;

use App\Entity\Team;
use App\Services\Stripe\SubscriptionManager;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class SubscriptionManagerTest extends IntegrationTestCase
{
    public function testCheckoutSessionCompletedDispatchesUpgradeWithMetadataInterval(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $manager = $this->getService(SubscriptionManager::class);

        $team = $this->createTeam($em);
        $em->flush();

        $manager->dispatchStripeEvent('checkout.session.completed', [
            'subscription' => 'sub_test_123',
            'customer' => 'cus_test_123',
            'metadata' => [
                'team_id' => $team->id->toString(),
                'plan' => 'personal',
                'interval' => 'annual',
            ],
        ]);

        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('personal', $reloaded->plan);
        self::assertSame('annual', $reloaded->billingInterval);
        self::assertSame('sub_test_123', $reloaded->stripeSubscriptionId);
    }

    public function testSubscriptionUpdatedDispatchesPlanChange(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $manager = $this->getService(SubscriptionManager::class);

        $team = $this->createTeam($em, plan: SubscriptionPlan::Personal);
        $team->stripeSubscriptionId = 'sub_test_999';
        $em->flush();

        $manager->dispatchStripeEvent('customer.subscription.updated', [
            'id' => 'sub_test_999',
            'customer' => 'cus_test_999',
            'metadata' => [
                'team_id' => $team->id->toString(),
                'plan' => 'pro',
                'interval' => 'monthly',
            ],
        ]);

        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('pro', $reloaded->plan);
        self::assertSame('monthly', $reloaded->billingInterval);
    }

    public function testSubscriptionUpdatedIgnoredWhenMetadataMissing(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $manager = $this->getService(SubscriptionManager::class);

        $team = $this->createTeam($em, plan: SubscriptionPlan::Personal);
        $em->flush();

        // No metadata payload — webhook should be a no-op (Stripe's own
        // dashboard edits can fire this event too).
        $manager->dispatchStripeEvent('customer.subscription.updated', [
            'id' => 'sub_no_meta',
            'customer' => 'cus_x',
        ]);

        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('personal', $reloaded->plan);
    }

    public function testSubscriptionDeletedDowngradesTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $manager = $this->getService(SubscriptionManager::class);

        $team = $this->createTeam($em, plan: SubscriptionPlan::Pro);
        $em->flush();

        $manager->dispatchStripeEvent('customer.subscription.deleted', [
            'metadata' => ['team_id' => $team->id->toString()],
        ]);

        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame(SubscriptionPlan::Free->value, $reloaded->plan);
    }

    public function testInvoicePaymentFailedIsLogOnly(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $manager = $this->getService(SubscriptionManager::class);

        $team = $this->createTeam($em, plan: SubscriptionPlan::Personal);
        $em->flush();

        // Per docs: log-only at launch. Stripe's retry schedule + the
        // eventual customer.subscription.deleted event handle the downgrade.
        $manager->dispatchStripeEvent('invoice.payment_failed', [
            'id' => 'in_test',
            'customer' => 'cus_test',
            'subscription' => 'sub_test',
            'attempt_count' => 2,
        ]);

        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('personal', $reloaded->plan, 'invoice.payment_failed alone must not change plan state.');
    }

    public function testUnknownEventTypeIsIgnoredWithoutSideEffects(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $manager = $this->getService(SubscriptionManager::class);

        $team = $this->createTeam($em, plan: SubscriptionPlan::Pro);
        $em->flush();

        $manager->dispatchStripeEvent('payout.created', ['id' => 'po_x']);

        $em->clear();
        $reloaded = $em->find(Team::class, $team->id);
        self::assertNotNull($reloaded);
        self::assertSame('pro', $reloaded->plan, 'Unknown events must not mutate team state.');
    }

    private function createTeam(EntityManagerInterface $em, SubscriptionPlan $plan = SubscriptionPlan::Free): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Sub Mgr Test',
            slug: 'submgr-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan->value,
        );
        $team->popEvents();
        $em->persist($team);

        return $team;
    }
}
