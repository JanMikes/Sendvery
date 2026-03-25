<?php

declare(strict_types=1);

namespace App\Value;

enum MailboxType: string
{
    case ImapUser = 'imap_user';
    case ImapHosted = 'imap_hosted';
    case Pop3User = 'pop3_user';
}
