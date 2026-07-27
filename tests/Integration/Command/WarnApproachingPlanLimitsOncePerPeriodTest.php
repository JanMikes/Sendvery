<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Repository\TeamRepository;
use App\Services\IdentityProvider;
use App\Services\Stripe\PlanEnforcement;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * The report cap and the AI quota are MONTHLY, so the warning about
 * approaching them has to be monthly too. Deduping on "has this team ever been
 * warned" told a team once, ever, about a limit it re-approaches every month —
 * and in the month it actually overflowed (reports parked in quarantine) it
 * heard nothing at all.
 */
final class WarnApproachingPlanLimitsOncePerPeriodTest extends IntegrationTestCase
{
    #[Test]
    public function aTeamWarnedInAnEarlierPeriodIsWarnedAgainThisPeriod(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeamWithOwner($em, SubscriptionPlan::Free, 'last-month@example.com');
        $em->flush();

        $this->fillReportCounter($team->id->toString(), 80);

        // Warned inside the previous period: the monthly limit has since reset,
        // so this month's approach is news the owner has not been told.
        $team->planWarningAt = $this->periodStart()->modify('-1 day');
        $em->flush();

        $exit = $this->tester()->execute([]);

        self::assertSame(0, $exit);
        self::assertNotNull(
            $this->findEmailTo('last-month@example.com'),
            'A monthly cap that is being approached again in a new period deserves a new warning.',
        );
    }

    #[Test]
    public function aTeamAlreadyWarnedInThisPeriodIsNotWarnedTwice(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeamWithOwner($em, SubscriptionPlan::Free, 'this-month@example.com');
        $em->flush();

        $this->fillReportCounter($team->id->toString(), 80);

        $team->planWarningAt = $this->getService(ClockInterface::class)->now();
        $em->flush();

        $this->tester()->execute([]);

        self::assertNull(
            $this->findEmailTo('this-month@example.com'),
            'One warning per period is enough; a second in the same month is noise that teaches the user to ignore the next one.',
        );
    }

    #[Test]
    public function theWarningIsStampedInsideTheCurrentPeriodSoTheNextRunStaysQuiet(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeamWithOwner($em, SubscriptionPlan::Free, 'stamped@example.com');
        $em->flush();

        $this->fillReportCounter($team->id->toString(), 80);

        $this->tester()->execute([]);
        $em->flush();

        $stamped = $this->getService(TeamRepository::class)->get($team->id)->planWarningAt;
        self::assertNotNull($stamped);
        self::assertGreaterThanOrEqual(
            $this->periodStart(),
            $stamped,
            'The stamp has to land inside the current period, otherwise the very next nightly run would re-send the same email.',
        );
    }

    private function periodStart(): \DateTimeImmutable
    {
        return $this->getService(PlanEnforcement::class)->currentPeriodStart();
    }

    private function fillReportCounter(string $teamId, int $reports): void
    {
        $enforcement = $this->getService(PlanEnforcement::class);
        for ($i = 0; $i < $reports; ++$i) {
            $enforcement->incrementMonthlyReportCount($teamId);
        }
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
            name: 'Period Warn '.$email,
            slug: 'period-warn-'.Uuid::uuid7()->toString(),
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

        // A monitored domain keeps the Free domain cap (1) from also tripping,
        // so these tests only speak about the monthly report cap.
        $domain = new MonitoredDomain(
            id: $idProvider->nextIdentity(),
            team: $team,
            domain: 'warn-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        return $team;
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
