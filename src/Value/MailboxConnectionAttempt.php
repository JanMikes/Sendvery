<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Plaintext credentials the mailbox-wizard collected from the form. Lives
 * only inside one request — never persisted (the `MailboxConnection`
 * entity stores Halite-encrypted username/password). Used by
 * `MailboxConnectionTester::test()` to perform the pre-submit
 * connectivity check; failure short-circuits the wizard before
 * `ConnectMailbox` is ever dispatched.
 */
final readonly class MailboxConnectionAttempt
{
    public function __construct(
        public string $host,
        public int $port,
        public MailboxEncryption $encryption,
        public MailboxType $type,
        public string $username,
        public string $password,
    ) {
    }
}
