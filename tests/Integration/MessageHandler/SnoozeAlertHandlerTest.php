<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\Team;
use App\Message\SnoozeAlert;
use App\Message\UnsnoozeAlert;
use App\MessageHandler\SnoozeAlertHandler;
use App\MessageHandler\UnsnoozeAlertHandler;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class SnoozeAlertHandlerTest extends IntegrationTestCase
{
    public function testSnoozeSetsDeadline(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(SnoozeAlertHandler::class);

        $alertId = $this->persistAlert($em);

        $snoozedUntil = new \DateTimeImmutable('2026-06-01 12:00:00');

        $handler(new SnoozeAlert(alertId: $alertId, snoozedUntil: $snoozedUntil));
        $em->flush();

        $em->clear();
        $reloaded = $em->find(Alert::class, $alertId);
        self::assertNotNull($reloaded);
        self::assertEquals($snoozedUntil, $reloaded->snoozedUntil);
    }

    public function testUnsnoozeClearsDeadline(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $snoozeHandler = $this->getService(SnoozeAlertHandler::class);
        $unsnoozeHandler = $this->getService(UnsnoozeAlertHandler::class);

        $alertId = $this->persistAlert($em);

        $snoozeHandler(new SnoozeAlert(
            alertId: $alertId,
            snoozedUntil: new \DateTimeImmutable('+7 days'),
        ));
        $em->flush();

        $unsnoozeHandler(new UnsnoozeAlert(alertId: $alertId));
        $em->flush();

        $em->clear();
        $reloaded = $em->find(Alert::class, $alertId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->snoozedUntil);
    }

    private function persistAlert(EntityManagerInterface $em): UuidInterface
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Snooze Team',
            slug: 'snooze-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $alertId = Uuid::uuid7();
        $alert = new Alert(
            id: $alertId,
            team: $team,
            monitoredDomain: null,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Warning,
            title: 'Spike',
            message: 'Failures up.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();

        return $alertId;
    }
}
