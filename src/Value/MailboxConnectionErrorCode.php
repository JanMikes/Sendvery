<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Classifies why an IMAP/POP3 connection test failed so the wizard can
 * surface a humane message ("Authentication failed", "Connection refused",
 * …) inline next to the form instead of the raw exception text. The
 * underlying transports (Webklex on top of various stream wrappers) wrap
 * many distinct failure modes in opaque strings, so the
 * `ImapMailboxConnectionTester` does case-insensitive substring matching
 * on the message and maps to one of these cases.
 */
enum MailboxConnectionErrorCode: string
{
    case AuthenticationFailed = 'authentication_failed';
    case ConnectionRefused = 'connection_refused';
    case ConnectionTimeout = 'connection_timeout';
    case StarttlsNotSupported = 'starttls_not_supported';
    case InboxNotFound = 'inbox_not_found';
    case Unknown = 'unknown';

    public function humanMessage(): string
    {
        return match ($this) {
            self::AuthenticationFailed => 'Authentication failed — check the username and password.',
            self::ConnectionRefused => 'Connection refused — verify the host and port are correct.',
            self::ConnectionTimeout => 'Connection timed out — the mail server did not respond within 3 seconds.',
            self::StarttlsNotSupported => 'The mail server does not support STARTTLS — try SSL/TLS instead.',
            self::InboxNotFound => 'Connected, but no INBOX folder was found on this mailbox.',
            self::Unknown => 'Could not connect to the mail server. Double-check the host, port, and credentials.',
        };
    }
}
