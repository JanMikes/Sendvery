<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\Team;
use App\Message\BulkMarkAlertsRead;
use App\Message\BulkSnoozeAlerts;
use App\MessageHandler\BulkMarkAlertsReadHandler;
use App\MessageHandler\BulkSnoozeAlertsHandler;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class BulkAlertHandlersTest extends IntegrationTestCase
{
    public function testBulkMarkReadMarksOnlyOwnedAlerts(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(BulkMarkAlertsReadHandler::class);

        [$teamA, $alertA1, $alertA2] = $this->createTeamWithTwoAlerts($em, 'team-a');
        [, $alertB1] = $this->createTeamWithTwoAlerts($em, 'team-b');

        $handler(new BulkMarkAlertsRead(
            alertIds: [$alertA1, $alertA2, $alertB1],
            teamId: $teamA->id,
        ));
        $em->flush();
        $em->clear();

        $reloadedA1 = $em->find(Alert::class, $alertA1);
        $reloadedA2 = $em->find(Alert::class, $alertA2);
        $reloadedB1 = $em->find(Alert::class, $alertB1);

        self::assertNotNull($reloadedA1);
        self::assertNotNull($reloadedA2);
        self::assertNotNull($reloadedB1);

        self::assertTrue($reloadedA1->isRead);
        self::assertTrue($reloadedA2->isRead);
        // Cross-tenant id was silently skipped (defense-in-depth).
        self::assertFalse($reloadedB1->isRead);
    }

    public function testBulkMarkReadHandlesEmptyList(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(BulkMarkAlertsReadHandler::class);

        $this->expectNotToPerformAssertions();

        $handler(new BulkMarkAlertsRead(alertIds: [], teamId: Uuid::uuid7()));
        $em->flush();
    }

    public function testBulkSnoozeAppliesToOwnedAlertsOnly(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(BulkSnoozeAlertsHandler::class);

        [$teamA, $alertA1, $alertA2] = $this->createTeamWithTwoAlerts($em, 'snooze-a');
        [, $alertB1] = $this->createTeamWithTwoAlerts($em, 'snooze-b');

        $snoozedUntil = new \DateTimeImmutable('2026-06-15 09:00:00');

        $handler(new BulkSnoozeAlerts(
            alertIds: [$alertA1, $alertA2, $alertB1],
            teamId: $teamA->id,
            snoozedUntil: $snoozedUntil,
        ));
        $em->flush();
        $em->clear();

        $reloadedA1 = $em->find(Alert::class, $alertA1);
        $reloadedA2 = $em->find(Alert::class, $alertA2);
        $reloadedB1 = $em->find(Alert::class, $alertB1);

        self::assertNotNull($reloadedA1);
        self::assertNotNull($reloadedA2);
        self::assertNotNull($reloadedB1);

        self::assertEquals($snoozedUntil, $reloadedA1->snoozedUntil);
        self::assertEquals($snoozedUntil, $reloadedA2->snoozedUntil);
        self::assertNull($reloadedB1->snoozedUntil);
    }

    /**
     * @return array{Team, UuidInterface, UuidInterface}
     */
    private function createTeamWithTwoAlerts(EntityManagerInterface $em, string $slugPrefix): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: $slugPrefix,
            slug: $slugPrefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $alertIds = [];
        foreach (range(1, 2) as $i) {
            $id = Uuid::uuid7();
            $alert = new Alert(
                id: $id,
                team: $team,
                monitoredDomain: null,
                type: AlertType::FailureSpike,
                severity: AlertSeverity::Warning,
                title: 'Alert '.$i,
                message: 'msg',
                data: [],
                createdAt: new \DateTimeImmutable(),
            );
            $alert->popEvents();
            $em->persist($alert);
            $alertIds[] = $id;
        }
        $em->flush();

        return [$team, $alertIds[0], $alertIds[1]];
    }
}
