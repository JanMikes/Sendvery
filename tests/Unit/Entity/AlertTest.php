<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\AlertCreated;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AlertTest extends TestCase
{
    private function createTeamAndDomain(): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test',
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();

        return [$team, $domain];
    }

    #[Test]
    public function constructorSetsAllFields(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();
        $id = Uuid::uuid7();
        $createdAt = new \DateTimeImmutable('2026-03-25 10:00:00');

        $alert = new Alert(
            id: $id,
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::DnsRecordChanged,
            severity: AlertSeverity::Warning,
            title: 'SPF record changed',
            message: 'The SPF record was modified.',
            data: ['dns_check_type' => 'spf'],
            createdAt: $createdAt,
        );

        self::assertSame($id, $alert->id);
        self::assertSame($team, $alert->team);
        self::assertSame($domain, $alert->monitoredDomain);
        self::assertSame(AlertType::DnsRecordChanged, $alert->type);
        self::assertSame(AlertSeverity::Warning, $alert->severity);
        self::assertSame('SPF record changed', $alert->title);
        self::assertSame('The SPF record was modified.', $alert->message);
        self::assertSame(['dns_check_type' => 'spf'], $alert->data);
        self::assertFalse($alert->isRead);
        self::assertSame($createdAt, $alert->createdAt);
    }

    #[Test]
    public function recordsAlertCreatedEvent(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Critical,
            title: 'Failure spike',
            message: 'Big spike detected.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );

        $events = $alert->popEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(AlertCreated::class, $events[0]);
        self::assertSame($team->id, $events[0]->teamId);
        self::assertSame(AlertType::FailureSpike, $events[0]->type);
        self::assertSame(AlertSeverity::Critical, $events[0]->severity);
        self::assertSame('example.com', $events[0]->domainName);
    }

    #[Test]
    public function markAsRead(): void
    {
        [$team] = $this->createTeamAndDomain();

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::PolicyRecommendation,
            severity: AlertSeverity::Info,
            title: 'Recommendation',
            message: 'Upgrade policy.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );

        self::assertFalse($alert->isRead);

        $alert->markAsRead();

        self::assertTrue($alert->isRead);
    }

    #[Test]
    public function nullableDomain(): void
    {
        [$team] = $this->createTeamAndDomain();

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::MailboxConnectionError,
            severity: AlertSeverity::Warning,
            title: 'Connection error',
            message: 'Error.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );

        self::assertNull($alert->monitoredDomain);

        $events = $alert->popEvents();
        self::assertNull($events[0]->domainName);
    }
}
