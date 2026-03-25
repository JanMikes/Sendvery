<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dmarc;

use App\Exceptions\InvalidDmarcReportXml;
use App\Services\Dmarc\ReportAttachmentExtractor;
use PHPUnit\Framework\TestCase;

final class ReportAttachmentExtractorTest extends TestCase
{
    private ReportAttachmentExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ReportAttachmentExtractor();
    }

    public function testExtractsPlainXml(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/google-report.xml');
        assert(is_string($xml));

        $result = $this->extractor->extract($xml, 'report.xml');

        self::assertCount(1, $result);
        self::assertSame($xml, $result[0]);
    }

    public function testExtractsGzipByExtension(): void
    {
        $gzContent = file_get_contents(__DIR__ . '/../../../Fixtures/google-report.xml.gz');
        assert(is_string($gzContent));

        $result = $this->extractor->extract($gzContent, 'report.xml.gz');

        self::assertCount(1, $result);
        self::assertStringContainsString('<feedback>', $result[0]);
        self::assertStringContainsString('google.com', $result[0]);
    }

    public function testExtractsGzipByMagicBytes(): void
    {
        $gzContent = file_get_contents(__DIR__ . '/../../../Fixtures/google-report.xml.gz');
        assert(is_string($gzContent));

        $result = $this->extractor->extract($gzContent, 'unknown-file');

        self::assertCount(1, $result);
        self::assertStringContainsString('<feedback>', $result[0]);
    }

    public function testExtractsZipByExtension(): void
    {
        $zipContent = file_get_contents(__DIR__ . '/../../../Fixtures/yahoo-report.zip');
        assert(is_string($zipContent));

        $result = $this->extractor->extract($zipContent, 'report.zip');

        self::assertCount(1, $result);
        self::assertStringContainsString('<feedback>', $result[0]);
        self::assertStringContainsString('Yahoo', $result[0]);
    }

    public function testExtractsZipByMagicBytes(): void
    {
        $zipContent = file_get_contents(__DIR__ . '/../../../Fixtures/yahoo-report.zip');
        assert(is_string($zipContent));

        $result = $this->extractor->extract($zipContent, 'unknown-file');

        self::assertCount(1, $result);
        self::assertStringContainsString('<feedback>', $result[0]);
    }

    public function testDetectsXmlByContent(): void
    {
        $xml = '<?xml version="1.0"?><feedback></feedback>';

        $result = $this->extractor->extract($xml, 'unknown-file');

        self::assertCount(1, $result);
        self::assertSame($xml, $result[0]);
    }

    public function testDetectsXmlByFeedbackTag(): void
    {
        $xml = '<feedback><report_metadata></report_metadata></feedback>';

        $result = $this->extractor->extract($xml, 'unknown-file');

        self::assertCount(1, $result);
        self::assertSame($xml, $result[0]);
    }

    public function testThrowsOnUnsupportedFormat(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Unsupported file format');

        $this->extractor->extract('random binary data here!', 'report.pdf');
    }

    public function testThrowsOnInvalidGzip(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Failed to decompress gzip');

        $this->extractor->extract("\x1f\x8b" . 'corrupted data', 'report.gz');
    }

    public function testThrowsOnInvalidZip(): void
    {
        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('Failed to open zip');

        $this->extractor->extract("PK\x03\x04corrupted data", 'report.zip');
    }

    public function testThrowsOnZipWithNoXmlFiles(): void
    {
        $zipContent = file_get_contents(__DIR__ . '/../../../Fixtures/no-xml.zip');
        assert(is_string($zipContent));

        $this->expectException(InvalidDmarcReportXml::class);
        $this->expectExceptionMessage('No XML files found in zip archive');

        $this->extractor->extract($zipContent, 'no-xml.zip');
    }

    public function testExtractsGzipByGzipExtension(): void
    {
        $gzContent = file_get_contents(__DIR__ . '/../../../Fixtures/google-report.xml.gz');
        assert(is_string($gzContent));

        $result = $this->extractor->extract($gzContent, 'report.gzip');

        self::assertCount(1, $result);
        self::assertStringContainsString('<feedback>', $result[0]);
    }
}
