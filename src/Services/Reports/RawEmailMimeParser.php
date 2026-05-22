<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Value\MailAttachment;
use Webklex\PHPIMAP\Message;

/**
 * Pulls attachments out of a raw RFC 822 email blob. Wraps Webklex's IMAP
 * parser so we get the same MIME handling we already use for live IMAP
 * mailboxes, just running over bytes we pulled earlier and stored.
 */
final readonly class RawEmailMimeParser
{
    /** @return list<MailAttachment> */
    public function extractAttachments(string $rawEml): array
    {
        $message = Message::fromString($rawEml);

        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = new MailAttachment(
                filename: $attachment->getName() ?? 'attachment',
                content: $attachment->getContent(),
                mimeType: $attachment->getMimeType() ?? 'application/octet-stream',
            );
        }

        return $attachments;
    }
}
