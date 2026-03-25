<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AlertType;
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
        self::assertCount(8, AlertType::cases());
    }
}
