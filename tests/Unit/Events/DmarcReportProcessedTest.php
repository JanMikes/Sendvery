<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\DmarcReportProcessed;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DmarcReportProcessedTest extends TestCase
{
    public function testProperties(): void
    {
        $reportId = Uuid::uuid7();
        $domainId = Uuid::uuid7();

        $event = new DmarcReportProcessed(
            reportId: $reportId,
            domainId: $domainId,
            reporterOrg: 'google.com',
            totalRecords: 5,
            passCount: 140,
            failCount: 10,
        );

        self::assertSame($reportId, $event->reportId);
        self::assertSame($domainId, $event->domainId);
        self::assertSame('google.com', $event->reporterOrg);
        self::assertSame(5, $event->totalRecords);
        self::assertSame(140, $event->passCount);
        self::assertSame(10, $event->failCount);
    }
}
