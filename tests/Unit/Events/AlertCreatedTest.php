<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\AlertCreated;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AlertCreatedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $alertId = Uuid::uuid7();
        $teamId = Uuid::uuid7();

        $event = new AlertCreated(
            alertId: $alertId,
            teamId: $teamId,
            type: AlertType::DnsRecordChanged,
            severity: AlertSeverity::Warning,
            title: 'SPF record changed',
            domainName: 'example.com',
        );

        self::assertSame($alertId, $event->alertId);
        self::assertSame($teamId, $event->teamId);
        self::assertSame(AlertType::DnsRecordChanged, $event->type);
        self::assertSame(AlertSeverity::Warning, $event->severity);
        self::assertSame('SPF record changed', $event->title);
        self::assertSame('example.com', $event->domainName);
    }

    #[Test]
    public function nullableDomainName(): void
    {
        $event = new AlertCreated(
            alertId: Uuid::uuid7(),
            teamId: Uuid::uuid7(),
            type: AlertType::MailboxConnectionError,
            severity: AlertSeverity::Warning,
            title: 'Connection error',
            domainName: null,
        );

        self::assertNull($event->domainName);
    }
}
