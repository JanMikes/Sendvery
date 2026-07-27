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
    /** @return array{Team, MonitoredDomain} */
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
    public function anAlertCountsAsSnoozedOnlyUntilItsDeadlinePasses(): void
    {
        [$team] = $this->createTeamAndDomain();

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Critical,
            title: 'Failure spike',
            message: 'Spike.',
            data: [],
            createdAt: new \DateTimeImmutable('2026-03-25 10:00:00'),
        );

        self::assertFalse($alert->isSnoozed(new \DateTimeImmutable('2026-03-25 11:00:00')), 'An alert that was never snoozed is never hidden.');

        $alert->snoozeUntil(new \DateTimeImmutable('2026-04-01 10:00:00'));

        self::assertTrue($alert->isSnoozed(new \DateTimeImmutable('2026-03-26 10:00:00')));
        self::assertFalse(
            $alert->isSnoozed(new \DateTimeImmutable('2026-04-02 10:00:00')),
            'An expired snooze puts the alert back in front of the user — no manual cleanup needed.',
        );

        $alert->unsnooze();

        self::assertFalse($alert->isSnoozed(new \DateTimeImmutable('2026-03-26 10:00:00')));
    }

    #[Test]
    public function aFreshAlertIsNotResolved(): void
    {
        [$team] = $this->createTeamAndDomain();

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::DnsRecordInvalid,
            severity: AlertSeverity::Critical,
            title: 'MX is broken for example.com',
            message: 'Broken.',
            data: [],
            createdAt: new \DateTimeImmutable(),
        );

        self::assertNull($alert->resolvedAt);
        self::assertFalse($alert->isResolved());
    }

    #[Test]
    public function resolvingRecordsWhenTheProblemWasObservedFixed(): void
    {
        [$team] = $this->createTeamAndDomain();
        $resolvedAt = new \DateTimeImmutable('2026-03-27 04:00:00');

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::DnsRecordMissing,
            severity: AlertSeverity::Critical,
            title: 'MX record removed for example.com',
            message: 'Missing.',
            data: [],
            createdAt: new \DateTimeImmutable('2026-03-25 10:00:00'),
        );

        $alert->resolve($resolvedAt);

        self::assertTrue($alert->isResolved());
        self::assertSame($resolvedAt, $alert->resolvedAt);
    }

    #[Test]
    public function resolvingAnAlreadyResolvedAlertKeepsTheOriginalResolutionTime(): void
    {
        // The nightly DNS sweep re-observes the same healthy record forever;
        // bumping the timestamp each night would make a months-old fix look
        // like it happened last night.
        [$team] = $this->createTeamAndDomain();
        $firstResolution = new \DateTimeImmutable('2026-03-27 04:00:00');

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::DnsRecordInvalid,
            severity: AlertSeverity::Critical,
            title: 'SPF is broken for example.com',
            message: 'Broken.',
            data: [],
            createdAt: new \DateTimeImmutable('2026-03-25 10:00:00'),
        );

        $alert->resolve($firstResolution);
        $alert->resolve(new \DateTimeImmutable('2026-06-01 04:00:00'));

        self::assertSame($firstResolution, $alert->resolvedAt);
    }

    #[Test]
    public function aResolvedAlertCanBeRehydratedFromStorage(): void
    {
        [$team] = $this->createTeamAndDomain();
        $resolvedAt = new \DateTimeImmutable('2026-03-27 04:00:00');

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::DnsRecordInvalid,
            severity: AlertSeverity::Critical,
            title: 'DKIM is broken for example.com',
            message: 'Broken.',
            data: [],
            createdAt: new \DateTimeImmutable('2026-03-25 10:00:00'),
            resolvedAt: $resolvedAt,
        );

        self::assertTrue($alert->isResolved());
        self::assertSame($resolvedAt, $alert->resolvedAt);
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
        assert($events[0] instanceof AlertCreated);
        self::assertNull($events[0]->domainName);
    }
}
