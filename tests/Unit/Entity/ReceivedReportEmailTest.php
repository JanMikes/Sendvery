<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ReceivedReportEmail;
use App\Value\Reports\EnvelopeProcessingStatus;
use App\Value\Reports\ReportSource;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ReceivedReportEmailTest extends TestCase
{
    public function testStartsPending(): void
    {
        $envelope = $this->makeEnvelope();

        self::assertSame(EnvelopeProcessingStatus::Pending, $envelope->processingStatus);
        self::assertNull($envelope->processingError);
        self::assertSame(0, $envelope->attempts);
        self::assertNull($envelope->processedAt);
    }

    public function testMarksParsed(): void
    {
        $envelope = $this->makeEnvelope();
        $now = new \DateTimeImmutable('2026-05-22T10:00:00Z');

        $envelope->markParsed($now);

        self::assertSame(EnvelopeProcessingStatus::Parsed, $envelope->processingStatus);
        self::assertNull($envelope->processingError);
        self::assertSame($now, $envelope->processedAt);
    }

    public function testMarksQuarantined(): void
    {
        $envelope = $this->makeEnvelope();
        $now = new \DateTimeImmutable('2026-05-22T10:00:00Z');

        $envelope->markQuarantined($now);

        self::assertSame(EnvelopeProcessingStatus::Quarantined, $envelope->processingStatus);
        self::assertSame($now, $envelope->processedAt);
    }

    public function testMarksFailedKeepsError(): void
    {
        $envelope = $this->makeEnvelope();
        $now = new \DateTimeImmutable('2026-05-22T10:00:00Z');

        $envelope->markFailed('parser blew up', $now);

        self::assertSame(EnvelopeProcessingStatus::Failed, $envelope->processingStatus);
        self::assertSame('parser blew up', $envelope->processingError);
        self::assertSame($now, $envelope->processedAt);
    }

    public function testMarksIgnoredKeepsReason(): void
    {
        $envelope = $this->makeEnvelope();
        $now = new \DateTimeImmutable('2026-05-22T10:00:00Z');

        $envelope->markIgnored('no DMARC attachments', $now);

        self::assertSame(EnvelopeProcessingStatus::Ignored, $envelope->processingStatus);
        self::assertSame('no DMARC attachments', $envelope->processingError);
        self::assertSame($now, $envelope->processedAt);
    }

    public function testIncrementsAttempts(): void
    {
        $envelope = $this->makeEnvelope();

        $envelope->incrementAttempts();
        $envelope->incrementAttempts();

        self::assertSame(2, $envelope->attempts);
    }

    public function testRawEmlBytesFromString(): void
    {
        $envelope = $this->makeEnvelope(rawEml: 'Subject: hi');

        self::assertSame('Subject: hi', $envelope->rawEmlBytes());
    }

    private function makeEnvelope(string $rawEml = 'raw'): ReceivedReportEmail
    {
        return new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<test@example.com>',
            fromAddress: 'noreply-dmarc-support@google.com',
            subject: 'Report Domain: example.com',
            receivedAt: new \DateTimeImmutable('2026-05-22T09:00:00Z'),
            ingestedAt: new \DateTimeImmutable('2026-05-22T09:05:00Z'),
            sizeBytes: strlen($rawEml),
            rawEml: $rawEml,
            imapUidvalidity: 1000,
            imapUid: 42,
        );
    }
}
