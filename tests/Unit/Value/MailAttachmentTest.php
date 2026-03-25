<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\MailAttachment;
use PHPUnit\Framework\TestCase;

final class MailAttachmentTest extends TestCase
{
    public function testProperties(): void
    {
        $attachment = new MailAttachment(
            filename: 'report.xml.gz',
            content: 'binary-content',
            mimeType: 'application/gzip',
        );

        self::assertSame('report.xml.gz', $attachment->filename);
        self::assertSame('binary-content', $attachment->content);
        self::assertSame('application/gzip', $attachment->mimeType);
    }
}
