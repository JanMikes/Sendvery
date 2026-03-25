<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\ProcessDmarcReport;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ProcessDmarcReportTest extends TestCase
{
    public function testProperties(): void
    {
        $reportId = Uuid::uuid7();
        $domainId = Uuid::uuid7();

        $message = new ProcessDmarcReport(
            reportId: $reportId,
            domainId: $domainId,
            xmlContent: '<feedback></feedback>',
        );

        self::assertSame($reportId, $message->reportId);
        self::assertSame($domainId, $message->domainId);
        self::assertSame('<feedback></feedback>', $message->xmlContent);
    }
}
