<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\Alert;
use App\Entity\Team;
use App\Query\GetAlerts;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetAlertsSnoozeFilterTest extends IntegrationTestCase
{
    public function testDefaultFilterExcludesCurrentlySnoozedAlerts(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'snooze-filter-1');
        $this->persistAlert($em, $team, 'Open alert', null);
        $this->persistAlert($em, $team, 'Snoozed alert', new \DateTimeImmutable('+7 days'));

        $results = $query->forTeams([$team->id->toString()]);

        self::assertCount(1, $results);
        self::assertSame('Open alert', $results[0]->title);
    }

    public function testOnlySnoozedReturnsOnlyCurrentlySnoozed(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'snooze-filter-2');
        $this->persistAlert($em, $team, 'Open alert', null);
        $this->persistAlert($em, $team, 'Snoozed alert', new \DateTimeImmutable('+7 days'));

        $results = $query->forTeams([$team->id->toString()], onlySnoozed: true);

        self::assertCount(1, $results);
        self::assertSame('Snoozed alert', $results[0]->title);
    }

    public function testExpiredSnoozeIsTreatedAsNotSnoozedInDefaultList(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'snooze-filter-3');
        // Snooze deadline in the past — falls back into the default list.
        $this->persistAlert($em, $team, 'Expired snooze', new \DateTimeImmutable('-1 day'));

        $results = $query->forTeams([$team->id->toString()]);

        self::assertCount(1, $results);
        self::assertSame('Expired snooze', $results[0]->title);
    }

    public function testExpiredSnoozeIsNotIncludedInOnlySnoozedFilter(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'snooze-filter-4');
        $this->persistAlert($em, $team, 'Expired snooze', new \DateTimeImmutable('-1 day'));
        $this->persistAlert($em, $team, 'Active snooze', new \DateTimeImmutable('+1 day'));

        $results = $query->forTeams([$team->id->toString()], onlySnoozed: true);

        self::assertCount(1, $results);
        self::assertSame('Active snooze', $results[0]->title);
    }

    public function testCountUnreadExcludesCurrentlySnoozed(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'snooze-count-1');
        $this->persistAlert($em, $team, 'Open', null);
        $this->persistAlert($em, $team, 'Snoozed', new \DateTimeImmutable('+7 days'));
        $this->persistAlert($em, $team, 'Expired snooze', new \DateTimeImmutable('-7 days'));

        self::assertSame(2, $query->countUnreadForTeams([$team->id->toString()]));
    }

    public function testCountUnreadCriticalExcludesCurrentlySnoozed(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'snooze-count-2');
        $this->persistAlert($em, $team, 'Open', null, AlertSeverity::Critical);
        $this->persistAlert($em, $team, 'Snoozed', new \DateTimeImmutable('+7 days'), AlertSeverity::Critical);

        self::assertSame(1, $query->countUnreadCriticalForTeams([$team->id->toString()]));
    }

    public function testResultExposesSnoozedUntilField(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'snooze-field');
        $deadline = new \DateTimeImmutable('+7 days');
        $this->persistAlert($em, $team, 'Snoozed alert', $deadline);

        $results = $query->forTeams([$team->id->toString()], onlySnoozed: true);

        self::assertCount(1, $results);
        self::assertNotNull($results[0]->snoozedUntil);
    }

    private function persistTeam(EntityManagerInterface $em, string $slugPrefix): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: $slugPrefix,
            slug: $slugPrefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function persistAlert(
        EntityManagerInterface $em,
        Team $team,
        string $title,
        ?\DateTimeImmutable $snoozedUntil,
        AlertSeverity $severity = AlertSeverity::Warning,
    ): void {
        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::FailureSpike,
            severity: $severity,
            title: $title,
            message: 'msg',
            data: [],
            createdAt: new \DateTimeImmutable(),
            snoozedUntil: $snoozedUntil,
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();
    }
}
