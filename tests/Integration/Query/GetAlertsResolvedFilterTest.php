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
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Resolved alerts stay visible in the list as the receipt that a fix landed,
 * but drop out of everything that demands attention.
 */
final class GetAlertsResolvedFilterTest extends IntegrationTestCase
{
    #[Test]
    public function resolvedAlertsStayInTheDefaultList(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'resolved-visible');
        $this->persistAlert($em, $team, 'Still broken', resolvedAt: null);
        $this->persistAlert($em, $team, 'Already fixed', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'));

        $titles = array_map(static fn ($row) => $row->title, $query->forTeams([$team->id->toString()]));

        self::assertContains('Already fixed', $titles, 'A resolved alert is a record that the fix landed — it must not vanish from the list.');
        self::assertContains('Still broken', $titles);
    }

    #[Test]
    public function theResolvedFilterShowsOnlyResolvedAlerts(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'resolved-only');
        $this->persistAlert($em, $team, 'Still broken', resolvedAt: null);
        $this->persistAlert($em, $team, 'Already fixed', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'));

        $results = $query->forTeams([$team->id->toString()], isResolved: true);

        self::assertCount(1, $results);
        self::assertSame('Already fixed', $results[0]->title);
    }

    #[Test]
    public function theUnresolvedFilterHidesResolvedAlerts(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'unresolved-only');
        $this->persistAlert($em, $team, 'Still broken', resolvedAt: null);
        $this->persistAlert($em, $team, 'Already fixed', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'));

        $results = $query->forTeams([$team->id->toString()], isResolved: false);

        self::assertCount(1, $results);
        self::assertSame('Still broken', $results[0]->title);
    }

    #[Test]
    public function theListExposesTheResolutionTimestamp(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'resolved-field');
        $this->persistAlert($em, $team, 'Already fixed', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'));

        $results = $query->forTeams([$team->id->toString()], isResolved: true);

        self::assertCount(1, $results);
        self::assertNotNull($results[0]->resolvedAt);
    }

    #[Test]
    public function aResolvedAlertIsNotCountedAsUnreadEvenWhenItWasNeverOpened(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'resolved-unread-count');
        $this->persistAlert($em, $team, 'Still broken', resolvedAt: null);
        $this->persistAlert($em, $team, 'Fixed but never opened', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'));

        self::assertSame(
            1,
            $query->countUnreadForTeams([$team->id->toString()]),
            'A fixed problem must stop demanding attention regardless of its read flag.',
        );
    }

    #[Test]
    public function aResolvedCriticalAlertDropsOutOfTheCriticalBadge(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'resolved-critical-count');
        $this->persistAlert($em, $team, 'Open critical', resolvedAt: null, severity: AlertSeverity::Critical);
        $this->persistAlert($em, $team, 'Fixed critical', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'), severity: AlertSeverity::Critical);

        self::assertSame(1, $query->countUnreadCriticalForTeams([$team->id->toString()]));
    }

    #[Test]
    public function theMarkAllAsReadCountCoversSnoozedAlertsToo(): void
    {
        // "Mark all as read" flips the read flag across the whole unresolved
        // backlog, so the number it reports has to include snoozed rows — but
        // still never the resolved ones.
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAlerts::class);

        $team = $this->persistTeam($em, 'mark-all-count');
        $this->persistAlert($em, $team, 'Open', resolvedAt: null);
        $this->persistAlert($em, $team, 'Snoozed', resolvedAt: null, snoozedUntil: new \DateTimeImmutable('+7 days'));
        $this->persistAlert($em, $team, 'Resolved', resolvedAt: new \DateTimeImmutable('2026-03-27 04:00:00'));

        $teamIds = [$team->id->toString()];

        self::assertSame(1, $query->countUnreadForTeams($teamIds), 'The badge count keeps hiding snoozed alerts.');
        self::assertSame(2, $query->countUnreadForTeams($teamIds, includeSnoozed: true));
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
        ?\DateTimeImmutable $resolvedAt,
        AlertSeverity $severity = AlertSeverity::Warning,
        ?\DateTimeImmutable $snoozedUntil = null,
    ): void {
        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::DnsRecordInvalid,
            severity: $severity,
            title: $title,
            message: 'msg',
            data: [],
            createdAt: new \DateTimeImmutable(),
            snoozedUntil: $snoozedUntil,
            resolvedAt: $resolvedAt,
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();
    }
}
