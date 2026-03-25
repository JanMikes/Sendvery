<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Stripe;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Services\Stripe\PlanEnforcement;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PlanEnforcementTest extends IntegrationTestCase
{
    public function testCanAddDomainWhenUnderLimit(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);

        $team = $this->createTeam($em);

        self::assertTrue($enforcement->canAddDomain($team->id->toString(), SubscriptionPlan::Free));
    }

    public function testCannotAddDomainWhenAtLimit(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);

        $team = $this->createTeam($em);
        $this->createDomain($em, $team);

        // Free plan allows 1 domain, we already have 1
        self::assertFalse($enforcement->canAddDomain($team->id->toString(), SubscriptionPlan::Free));
    }

    public function testCanAddDomainOnHigherPlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);

        $team = $this->createTeam($em);
        $this->createDomain($em, $team);

        // Personal plan allows 5 domains
        self::assertTrue($enforcement->canAddDomain($team->id->toString(), SubscriptionPlan::Personal));
    }

    public function testCanAddTeamMemberWhenUnderLimit(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);

        $team = $this->createTeam($em);

        // Team plan allows 10 members, we have 0
        self::assertTrue($enforcement->canAddTeamMember($team->id->toString(), SubscriptionPlan::Team));
    }

    public function testGetDomainCount(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);

        $team = $this->createTeam($em);
        self::assertSame(0, $enforcement->getDomainCount($team->id->toString()));

        $this->createDomain($em, $team);
        self::assertSame(1, $enforcement->getDomainCount($team->id->toString()));
    }

    public function testGetTeamMemberCount(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);

        $team = $this->createTeam($em);
        self::assertSame(0, $enforcement->getTeamMemberCount($team->id->toString()));

        $this->createMember($em, $team);
        self::assertSame(1, $enforcement->getTeamMemberCount($team->id->toString()));
    }

    public function testCanAccessFeature(): void
    {
        $enforcement = $this->getService(PlanEnforcement::class);

        self::assertFalse($enforcement->canAccessFeature(SubscriptionPlan::Free, 'alerts'));
        self::assertTrue($enforcement->canAccessFeature(SubscriptionPlan::Personal, 'alerts'));
        self::assertTrue($enforcement->canAccessFeature(SubscriptionPlan::Team, 'api_access'));
    }

    private function createTeam(EntityManagerInterface $em): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Enforcement Test',
            slug: 'enforcement-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function createDomain(EntityManagerInterface $em, Team $team): void
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'enforce-'.Uuid::uuid7()->toString().'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();
    }

    private function createMember(EntityManagerInterface $em, Team $team): void
    {
        $user = new User(
            id: Uuid::uuid7(),
            email: 'enforce-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);
        $em->flush();
    }
}
