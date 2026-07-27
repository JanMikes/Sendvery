<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AlertSeverity;
use App\Value\WeeklyDigestAlertItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeeklyDigestAlertItemTest extends TestCase
{
    #[Test]
    public function carriesTheMostRecentTitleAndHowManyAlertsItStandsFor(): void
    {
        $item = new WeeklyDigestAlertItem(
            title: '3 new sender(s) detected for example.com',
            severity: AlertSeverity::Warning,
            domainName: 'example.com',
            occurrences: 11,
        );

        self::assertSame('3 new sender(s) detected for example.com', $item->title);
        self::assertSame(AlertSeverity::Warning, $item->severity);
        self::assertSame('example.com', $item->domainName);
        self::assertSame(11, $item->occurrences);
    }

    #[Test]
    public function anAlertNotTiedToADomainHasNoDomainName(): void
    {
        $item = new WeeklyDigestAlertItem(
            title: 'Mailbox connection failed',
            severity: AlertSeverity::Critical,
            domainName: null,
            occurrences: 1,
        );

        self::assertNull($item->domainName);
    }
}
