<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Mail;

use App\Services\Reports\ImapCentralInboxClient;
use App\Tests\TestSupport\ProjectSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\Message;

/**
 * Both ingestion paths now store the bytes of the mail they received, and they
 * have to store the SAME bytes for the same reason.
 *
 * Webklex's `getRawBody()` returns the body without the headers. A blob stored
 * that way has no top-level `Content-Type`, so re-parsing it finds no MIME
 * structure and therefore no attachments — the failure the central inbox was
 * already caught by once ("Central inbox headerless EML bug"). `fullRawEml()`
 * is the answer that was arrived at then, and the BYO poller now shares it
 * rather than re-deriving it.
 *
 * WHAT THIS FILE CANNOT REACH, stated plainly: `ImapMailClient` needs a live
 * IMAP server. It builds its `ClientManager` inline, so there is no seam to
 * substitute, and the memory `tests-never-make-real-external-requests` rules
 * out dialling a real one. Making it injectable is a refactor of the
 * production IMAP path, which is not a change to make for a coverage number
 * with no way to prove it still works. So `fetchDmarcReports()`,
 * `markAsProcessed()` and `testConnection()` remain unreached. What IS covered
 * here is the one line in that method with real semantics — the shared
 * definition of "the whole message" — plus a source assertion that the BYO
 * path still calls it, because "simplifying" that call to `getRawBody()` is
 * both tempting and silent.
 */
final class BothInboxesKeepTheWholeMessageTest extends TestCase
{
    #[Test]
    public function theStoredBytesAreTheHeadersAndTheBodyTogether(): void
    {
        $rawHeader = "Message-ID: <report@example.com>\r\nSubject: Report Domain: example.com\r\nContent-Type: multipart/mixed; boundary=\"XYZ\"";
        $rawBody = "--XYZ\r\nContent-Type: application/gzip\r\n\r\nreport-bytes\r\n--XYZ--";

        $eml = ImapCentralInboxClient::fullRawEml($this->message($rawHeader, $rawBody));

        self::assertSame($rawHeader."\r\n\r\n".$rawBody, $eml);
        self::assertStringContainsString(
            'Content-Type: multipart/mixed',
            $eml,
            'Without the headers there is no top-level Content-Type, so a re-parse of the stored blob finds no MIME parts and no attachments.',
        );
    }

    #[Test]
    public function aMessageWithNoHeaderAtAllStillProducesABlobRatherThanBlowingUp(): void
    {
        // Seznam has served header-less fetches before. The separator alone is
        // a truthful "we got a body and no headers", and it keeps the poll
        // moving instead of throwing mid-batch.
        $message = $this->createStub(Message::class);
        $message->method('getHeader')->willReturn(null);
        $message->method('getRawBody')->willReturn('orphan body');

        self::assertSame("\r\n\r\norphan body", ImapCentralInboxClient::fullRawEml($message));
    }

    #[Test]
    public function theByoPollerStillStoresTheWholeMessageAndNotJustTheBody(): void
    {
        // A source assertion, deliberately: there is no seam to observe this
        // through, and the mistake it guards against produces no error at all —
        // just envelopes whose stored bytes silently re-parse to zero
        // attachments, discovered months later by someone reprocessing one.
        $client = (string) file_get_contents(ProjectSource::projectDir().'/src/Services/Mail/ImapMailClient.php');

        self::assertStringContainsString(
            'rawEml: ImapCentralInboxClient::fullRawEml($message)',
            $client,
            'The BYO path must keep sharing the central inbox\'s definition of "the whole message" rather than reaching for getRawBody().',
        );
    }

    private function message(string $rawHeader, string $rawBody): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getHeader')->willReturn(new Header($rawHeader, Config::make()));
        $message->method('getRawBody')->willReturn($rawBody);

        return $message;
    }
}
