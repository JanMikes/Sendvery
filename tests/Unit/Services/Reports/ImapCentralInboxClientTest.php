<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Reports;

use App\Services\Reports\ImapCentralInboxClient;
use App\Services\Reports\RawEmailMimeParser;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * Regression guard for the outage where every central-inbox report was
 * ignored as "No attachments": Webklex's getRawBody() strips the RFC 822
 * header section, so persisting it alone produced blobs that could never
 * be re-parsed into attachments.
 */
final class ImapCentralInboxClientTest extends TestCase
{
    public function testPersistedEmlKeepsHeadersSoAttachmentsSurviveReprocessing(): void
    {
        $original = $this->dmarcReportEml();
        $message = Message::fromString($original);

        $persisted = ImapCentralInboxClient::fullRawEml($message);

        self::assertSame($original, $persisted, 'reassembled EML is byte-identical to what arrived over IMAP');

        $attachments = (new RawEmailMimeParser())->extractAttachments($persisted);
        self::assertCount(1, $attachments, 'the stored blob still yields the report attachment when re-parsed');
        self::assertSame('report.xml', $attachments[0]->filename);
    }

    public function testBodyAloneWouldLoseTheAttachment(): void
    {
        $message = Message::fromString($this->dmarcReportEml());

        $attachments = (new RawEmailMimeParser())->extractAttachments((string) $message->getRawBody());

        self::assertCount(0, $attachments, 'a headerless body has no MIME structure — persisting it silently drops every report');
    }

    private function dmarcReportEml(): string
    {
        $xml = '<?xml version="1.0"?><feedback><policy_published><domain>example.com</domain></policy_published></feedback>';
        $base64Xml = chunk_split(base64_encode($xml), 76, "\r\n");

        return implode("\r\n", [
            'From: noreply-dmarc-support@google.com',
            'To: reports@sendvery.test',
            'Subject: Report Domain: example.com',
            'Message-ID: <full-eml-1@google.com>',
            'Date: Fri, 22 May 2026 08:00:00 +0000',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b-full-eml"',
            '',
            '--b-full-eml',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Aggregate report attached.',
            '',
            '--b-full-eml',
            'Content-Type: application/xml; name="report.xml"',
            'Content-Disposition: attachment; filename="report.xml"',
            'Content-Transfer-Encoding: base64',
            '',
            $base64Xml,
            '--b-full-eml--',
            '',
        ]);
    }
}
