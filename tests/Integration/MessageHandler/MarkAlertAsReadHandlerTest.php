<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\Team;
use App\Message\MarkAlertAsRead;
use App\MessageHandler\MarkAlertAsReadHandler;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class MarkAlertAsReadHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function marksAlertAsRead(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Alert Read Team',
            slug: 'alert-read-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $alertId = Uuid::uuid7();
        $alert = new Alert(
            id: $alertId,
            team: $team,
            monitoredDomain: null,
            type: AlertType::DnsRecordChanged,
            severity: AlertSeverity::Warning,
            title: 'Test alert',
            message: 'Test message.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();

        self::assertFalse($alert->isRead);

        $handler = $this->getService(MarkAlertAsReadHandler::class);
        $handler(new MarkAlertAsRead(alertId: $alertId));
        $em->flush();

        $em->clear();
        $reloaded = $em->find(Alert::class, $alertId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isRead);
    }
}
