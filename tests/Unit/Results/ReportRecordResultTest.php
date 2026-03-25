<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\ReportRecordResult;
use PHPUnit\Framework\TestCase;

final class ReportRecordResultTest extends TestCase
{
    public function testFromDatabaseRow(): void
    {
        $result = ReportRecordResult::fromDatabaseRow([
            'record_id' => 'rec-123',
            'source_ip' => '209.85.220.41',
            'count' => '150',
            'disposition' => 'none',
            'dkim_result' => 'pass',
            'spf_result' => 'pass',
            'header_from' => 'example.com',
            'dkim_domain' => 'example.com',
            'dkim_selector' => 'google',
            'spf_domain' => 'example.com',
            'resolved_hostname' => 'mail.google.com',
            'resolved_org' => 'Google',
        ]);

        self::assertSame('rec-123', $result->recordId);
        self::assertSame('209.85.220.41', $result->sourceIp);
        self::assertSame(150, $result->count);
        self::assertSame('none', $result->disposition);
        self::assertSame('pass', $result->dkimResult);
        self::assertSame('pass', $result->spfResult);
        self::assertSame('example.com', $result->headerFrom);
        self::assertSame('example.com', $result->dkimDomain);
        self::assertSame('google', $result->dkimSelector);
        self::assertSame('example.com', $result->spfDomain);
        self::assertSame('mail.google.com', $result->resolvedHostname);
        self::assertSame('Google', $result->resolvedOrg);
    }

    public function testFromDatabaseRowWithNullFields(): void
    {
        $result = ReportRecordResult::fromDatabaseRow([
            'record_id' => 'rec-456',
            'source_ip' => '1.2.3.4',
            'count' => '1',
            'disposition' => 'reject',
            'dkim_result' => 'fail',
            'spf_result' => 'fail',
            'header_from' => 'test.com',
            'dkim_domain' => null,
            'dkim_selector' => null,
            'spf_domain' => null,
            'resolved_hostname' => null,
            'resolved_org' => null,
        ]);

        self::assertNull($result->dkimDomain);
        self::assertNull($result->dkimSelector);
        self::assertNull($result->spfDomain);
        self::assertNull($result->resolvedHostname);
        self::assertNull($result->resolvedOrg);
    }
}
