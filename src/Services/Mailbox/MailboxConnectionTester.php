<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Value\ConnectionTestResult;
use App\Value\MailboxConnectionAttempt;

/**
 * Pre-submit connectivity check for the mailbox-setup wizard. Distinct from
 * {@see \App\Services\Mail\MailClient::testConnection()} because this seam
 * operates on plaintext credentials BEFORE the `MailboxConnection` entity
 * exists. The wizard refuses to dispatch `ConnectMailbox` unless the
 * tester returns `success=true`, so no broken rows ever land in the DB.
 */
interface MailboxConnectionTester
{
    public function test(MailboxConnectionAttempt $attempt): ConnectionTestResult;
}
