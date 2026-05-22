<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Services\IdentityProvider;
use App\Services\Stripe\PlanEnforcement;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

final class WarnApproachingPlanLimitsCommandTest extends IntegrationTestCase
{
    public function testReportsZeroWhenNobodyIsCloseToALimit(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $this->createTeamWithOwner($em, SubscriptionPlan::Pro, 'fresh-owner@example.com');
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No teams crossed an 80% threshold', $tester->getDisplay());
    }

    public function testWarnsOwnerWhenDomainCapIsAt80Percent(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        // Personal plan = 5 domain cap. 4 domains = 80%.
        $team = $this->createTeamWithOwner($em, SubscriptionPlan::Personal, 'limited-owner@example.com');
        for ($i = 0; $i < 4; ++$i) {
            $this->addDomain($em, $team, 'one-'.$i.'-'.Uuid::uuid7()->toString().'.com');
        }
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);

        $warning = $this->findEmailTo('limited-owner@example.com');
        self::assertNotNull($warning);
        self::assertStringContainsString('Approaching', (string) $warning->getSubject());
        self::assertStringContainsString('4 of 5 domains used', (string) $warning->getTextBody());
    }

    public function testDoesNotReWarnTeamThatAlreadyHasPlanWarning(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = $this->createTeamWithOwner($em, SubscriptionPlan::Personal, 'already-warned@example.com');
        for ($i = 0; $i < 4; ++$i) {
            $this->addDomain($em, $team, 'dup-'.$i.'-'.Uuid::uuid7()->toString().'.com');
        }
        $team->planWarningAt = new \DateTimeImmutable('-1 hour');
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertNull($this->findEmailTo('already-warned@example.com'));
    }

    public function testWarnsOnMonthlyReportCapAt80Percent(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);

        // Free plan = 100 reports/mo. 80 reports = 80%.
        $team = $this->createTeamWithOwner($em, SubscriptionPlan::Free, 'reports-owner@example.com');
        $em->flush();

        $teamId = $team->id->toString();
        for ($i = 0; $i < 80; ++$i) {
            $enforcement->incrementMonthlyReportCount($teamId);
        }

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);

        $warning = $this->findEmailTo('reports-owner@example.com');
        self::assertNotNull($warning);
        self::assertStringContainsString('80 of 100 monthly reports', (string) $warning->getTextBody());
    }

    private function createTeamWithOwner(EntityManagerInterface $em, SubscriptionPlan $plan, string $email): Team
    {
        $idProvider = $this->getService(IdentityProvider::class);

        $user = new User(
            id: $idProvider->nextIdentity(),
            email: $email,
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: $idProvider->nextIdentity(),
            name: 'Warn '.$email,
            slug: 'warn-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan->value,
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: $idProvider->nextIdentity(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        return $team;
    }

    private function addDomain(EntityManagerInterface $em, Team $team, string $name): void
    {
        $idProvider = $this->getService(IdentityProvider::class);
        $domain = new MonitoredDomain(
            id: $idProvider->nextIdentity(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
    }

    private function findEmailTo(string $recipient): ?Email
    {
        foreach (self::getMailerMessages() as $message) {
            if (!$message instanceof Email) {
                continue;
            }
            foreach ($message->getTo() as $address) {
                if ($address->getAddress() === $recipient) {
                    return $message;
                }
            }
        }

        return null;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:plan-limits:warn-approaching'));
    }
}
