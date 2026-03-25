<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DnsMonitoringCheckResult;
use App\Value\Dns\DnsMonitoringResult;
use App\Value\DnsCheckType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DnsMonitoringResultTest extends TestCase
{
    #[Test]
    public function hasAnyChangesReturnsTrueWhenAtLeastOneChanged(): void
    {
        $result = new DnsMonitoringResult([
            DnsCheckType::Spf->value => new DnsMonitoringCheckResult(
                type: DnsCheckType::Spf,
                rawRecord: 'v=spf1 ~all',
                isValid: true,
                issues: [],
                details: [],
                previousRawRecord: 'v=spf1 -all',
                hasChanged: true,
            ),
            DnsCheckType::Dmarc->value => new DnsMonitoringCheckResult(
                type: DnsCheckType::Dmarc,
                rawRecord: 'v=DMARC1; p=reject',
                isValid: true,
                issues: [],
                details: [],
                previousRawRecord: 'v=DMARC1; p=reject',
                hasChanged: false,
            ),
        ]);

        self::assertTrue($result->hasAnyChanges());
    }

    #[Test]
    public function hasAnyChangesReturnsFalseWhenNoneChanged(): void
    {
        $result = new DnsMonitoringResult([
            DnsCheckType::Spf->value => new DnsMonitoringCheckResult(
                type: DnsCheckType::Spf,
                rawRecord: 'v=spf1 ~all',
                isValid: true,
                issues: [],
                details: [],
                previousRawRecord: 'v=spf1 ~all',
                hasChanged: false,
            ),
        ]);

        self::assertFalse($result->hasAnyChanges());
    }
}
