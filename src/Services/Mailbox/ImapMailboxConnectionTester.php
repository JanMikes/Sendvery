<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Value\ConnectionTestResult;
use App\Value\MailboxConnectionAttempt;
use App\Value\MailboxConnectionErrorCode;
use Webklex\PHPIMAP\ClientManager;

/**
 * Production implementation of the pre-submit mailbox-setup probe. Uses
 * the same Webklex transport as the production poller but with a 3-second
 * TCP timeout — long enough to handle a slow handshake, short enough that
 * the wizard form doesn't appear hung. Errors are classified into a
 * handful of human-friendly codes via case-insensitive substring matching
 * on the underlying exception message because Webklex wraps a variety of
 * lower-level errors in opaque strings.
 */
final readonly class ImapMailboxConnectionTester implements MailboxConnectionTester
{
    public function test(MailboxConnectionAttempt $attempt): ConnectionTestResult
    {
        try {
            $manager = new ClientManager();
            $client = $manager->make([
                'host' => $attempt->host,
                'port' => $attempt->port,
                'encryption' => $attempt->encryption->value,
                'validate_cert' => true,
                'username' => $attempt->username,
                'password' => $attempt->password,
                'protocol' => 'imap',
                'timeout' => 3,
            ]);

            $client->connect();

            $folder = $client->getFolderByName('INBOX') ?? $client->getFolderByPath('INBOX');

            if (null === $folder) {
                $client->disconnect();

                return new ConnectionTestResult(
                    success: false,
                    error: 'INBOX folder not found.',
                    mailboxCount: 0,
                    errorCode: MailboxConnectionErrorCode::InboxNotFound,
                );
            }

            $status = $folder->status();
            $messageCount = (int) ($status['messages'] ?? 0);

            $client->disconnect();

            return new ConnectionTestResult(
                success: true,
                error: null,
                mailboxCount: $messageCount,
            );
        } catch (\Throwable $e) {
            return new ConnectionTestResult(
                success: false,
                error: $e->getMessage(),
                mailboxCount: 0,
                errorCode: self::classifyError($e->getMessage()),
            );
        }
    }

    public static function classifyError(string $message): MailboxConnectionErrorCode
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'authentication') || str_contains($lower, 'auth failed') || str_contains($lower, 'authenticationfailed') || str_contains($lower, 'login failed') || str_contains($lower, 'credentials') || str_contains($lower, 'invalid login') => MailboxConnectionErrorCode::AuthenticationFailed,
            str_contains($lower, 'refused') => MailboxConnectionErrorCode::ConnectionRefused,
            str_contains($lower, 'timeout') || str_contains($lower, 'timed out') => MailboxConnectionErrorCode::ConnectionTimeout,
            str_contains($lower, 'starttls') => MailboxConnectionErrorCode::StarttlsNotSupported,
            str_contains($lower, 'inbox') || str_contains($lower, 'folder') => MailboxConnectionErrorCode::InboxNotFound,
            default => MailboxConnectionErrorCode::Unknown,
        };
    }
}
