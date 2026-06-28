<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AlertSeverity;
use App\Value\AlertType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AlertTypeTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        self::assertSame('new_unknown_sender', AlertType::NewUnknownSender->value);
        self::assertSame('failure_spike', AlertType::FailureSpike->value);
        self::assertSame('policy_recommendation', AlertType::PolicyRecommendation->value);
        self::assertSame('dns_record_changed', AlertType::DnsRecordChanged->value);
        self::assertSame('dns_record_invalid', AlertType::DnsRecordInvalid->value);
        self::assertSame('dns_record_missing', AlertType::DnsRecordMissing->value);
        self::assertSame('mailbox_connection_error', AlertType::MailboxConnectionError->value);
        self::assertSame('ip_blacklisted', AlertType::IpBlacklisted->value);
        self::assertSame('managed_dmarc_regression', AlertType::ManagedDmarcRegression->value);
        self::assertSame('managed_dmarc_dangling', AlertType::ManagedDmarcDangling->value);
        self::assertSame('managed_dmarc_advanced', AlertType::ManagedDmarcAdvanced->value);
        self::assertSame('managed_dmarc_ready', AlertType::ManagedDmarcReady->value);
        self::assertCount(12, AlertType::cases());
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function defaultSeverityMapsEachTypeToItsNaturalSeverity(AlertType $type, AlertSeverity $expected): void
    {
        self::assertSame($expected, $type->defaultSeverity());
    }

    /** @return iterable<string, array{0: AlertType, 1: AlertSeverity}> */
    public static function severityProvider(): iterable
    {
        yield 'regression is critical' => [AlertType::ManagedDmarcRegression, AlertSeverity::Critical];
        yield 'dangling is critical' => [AlertType::ManagedDmarcDangling, AlertSeverity::Critical];
        yield 'advanced is informational' => [AlertType::ManagedDmarcAdvanced, AlertSeverity::Info];
        yield 'ready is informational' => [AlertType::ManagedDmarcReady, AlertSeverity::Info];
        yield 'failure spike is critical' => [AlertType::FailureSpike, AlertSeverity::Critical];
        yield 'new sender is warning' => [AlertType::NewUnknownSender, AlertSeverity::Warning];
        yield 'policy recommendation is info' => [AlertType::PolicyRecommendation, AlertSeverity::Info];
        yield 'mailbox error is warning' => [AlertType::MailboxConnectionError, AlertSeverity::Warning];
        yield 'blacklist is critical' => [AlertType::IpBlacklisted, AlertSeverity::Critical];
        yield 'dns invalid is critical' => [AlertType::DnsRecordInvalid, AlertSeverity::Critical];
        yield 'dns missing is critical' => [AlertType::DnsRecordMissing, AlertSeverity::Critical];
        yield 'dns changed is critical' => [AlertType::DnsRecordChanged, AlertSeverity::Critical];
    }
}
