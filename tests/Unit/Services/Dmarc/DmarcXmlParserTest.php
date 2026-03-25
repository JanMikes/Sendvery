<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dmarc;

use App\Exceptions\InvalidDmarcReportXml;
use App\Services\Dmarc\DmarcXmlParser;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use PHPUnit\Framework\TestCase;

final class DmarcXmlParserTest extends TestCase
{
    private DmarcXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DmarcXmlParser();
    }

    public function testParsesGoogleReport(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/google-report.xml');
        assert(is_string($xml));

        $report = $this->parser->parse($xml);

        self::assertSame('google.com', $report->reporterOrg);
        self::assertSame('noreply-dmarc-support@google.com', $report->reporterEmail);
        self::assertSame('17238456789012345678', $report->reportId);
        self::assertSame('example.com', $report->policyDomain);
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAdkim);
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAspf);
        self::assertSame(DmarcPolicy::Reject, $report->policyP);
        self::assertSame(DmarcPolicy::Quarantine, $report->policySp);
        self::assertSame(100, $report->policyPct);
        self::assertCount(2, $report->records);

        $firstRecord = $report->records[0];
        self::assertSame('209.85.220.41', $firstRecord->sourceIp);
        self::assertSame(150, $firstRecord->count);
        self::assertSame(Disposition::None, $firstRecord->disposition);
        self::assertSame(AuthResult::Pass, $firstRecord->dkimResult);
        self::assertSame(AuthResult::Pass, $firstRecord->spfResult);
        self::assertSame('example.com', $firstRecord->headerFrom);
        self::assertSame('example.com', $firstRecord->dkimDomain);
        self::assertSame('google', $firstRecord->dkimSelector);
        self::assertSame('example.com', $firstRecord->spfDomain);

        $secondRecord = $report->records[1];
        self::assertSame('185.70.42.3', $secondRecord->sourceIp);
        self::assertSame(5, $secondRecord->count);
        self::assertSame(Disposition::Reject, $secondRecord->disposition);
        self::assertSame(AuthResult::Fail, $secondRecord->dkimResult);
        self::assertSame(AuthResult::Fail, $secondRecord->spfResult);
    }

    public function testParsesYahooReport(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/yahoo-report.xml');
        assert(is_string($xml));

        $report = $this->parser->parse($xml);

        self::assertSame('Yahoo! Inc.', $report->reporterOrg);
        self::assertSame('dmarchelp@yahoo.com', $report->reporterEmail);
        self::assertSame(DmarcAlignment::Strict, $report->policyAdkim);
        self::assertSame(DmarcAlignment::Strict, $report->policyAspf);
        self::assertSame(DmarcPolicy::Quarantine, $report->policyP);
        self::assertNull($report->policySp);
        self::assertSame(50, $report->policyPct);
        self::assertCount(1, $report->records);
    }

    public function testParsesMinimalReport(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/minimal-report.xml');
        assert(is_string($xml));

        $report = $this->parser->parse($xml);

        self::assertSame('microsoft.com', $report->reporterOrg);
        self::assertSame(DmarcPolicy::None, $report->policyP);
        // Defaults to relaxed when not specified
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAdkim);
        self::assertSame(DmarcAlignment::Relaxed, $report->policyAspf);
        self::assertSame(100, $report->policyPct);
    }

    public function testThrowsOnInvalidXml(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Failed to parse XML');

        $this->parser->parse('not xml at all');
    }

    public function testThrowsOnMissingReportMetadata(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <report_metadata>');

        $this->parser->parse('<?xml version="1.0"?><feedback><policy_published><domain>x.com</domain><p>none</p></policy_published></feedback>');
    }

    public function testThrowsOnMissingPolicyPublished(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <policy_published>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata></feedback>');
    }

    public function testThrowsOnMissingReportId(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <report_id>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata><policy_published><domain>x.com</domain><p>none</p></policy_published></feedback>');
    }

    public function testThrowsOnMissingPolicyDomain(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <domain>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata><policy_published><p>none</p></policy_published></feedback>');
    }

    public function testThrowsOnInvalidPolicy(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Invalid or missing <p>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><begin>1711929600</begin><end>1712015999</end></date_range></report_metadata><policy_published><domain>x.com</domain><p>invalid</p></policy_published></feedback>');
    }

    public function testThrowsOnMissingDateRangeBegin(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Missing <date_range.begin>');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><end>1712015999</end></date_range></report_metadata><policy_published><domain>x.com</domain><p>none</p></policy_published></feedback>');
    }

    public function testThrowsOnInvalidTimestamp(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Invalid timestamp');

        $this->parser->parse('<?xml version="1.0"?><feedback><report_metadata><report_id>x</report_id><date_range><begin>0</begin><end>1712015999</end></date_range></report_metadata><policy_published><domain>x.com</domain><p>none</p></policy_published></feedback>');
    }
}
