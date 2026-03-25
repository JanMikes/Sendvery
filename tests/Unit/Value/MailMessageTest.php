<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\MailAttachment;
use App\Value\MailMessage;
use PHPUnit\Framework\TestCase;

final class MailMessageTest extends TestCase
{
    public function testProperties(): void
    {
        $date = new \DateTimeImmutable('2026-03-25 10:00:00');
        $attachment = new MailAttachment('report.xml', '<feedback/>', 'application/xml');

        $message = new MailMessage(
            messageId: '<msg-123@example.com>',
            subject: 'Report domain: example.com',
            from: 'noreply-dmarc-support@google.com',
            date: $date,
            attachments: [$attachment],
        );

        self::assertSame('<msg-123@example.com>', $message->messageId);
        self::assertSame('Report domain: example.com', $message->subject);
        self::assertSame('noreply-dmarc-support@google.com', $message->from);
        self::assertSame($date, $message->date);
        self::assertCount(1, $message->attachments);
        self::assertSame($attachment, $message->attachments[0]);
    }
}
