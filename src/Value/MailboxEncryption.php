<?php

declare(strict_types=1);

namespace App\Value;

enum MailboxEncryption: string
{
    case Ssl = 'ssl';
    case Tls = 'tls';
    case StartTls = 'starttls';
    case None = 'none';
}
