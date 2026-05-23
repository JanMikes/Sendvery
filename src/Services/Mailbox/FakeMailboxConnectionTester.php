<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use App\Value\ConnectionTestResult;
use App\Value\MailboxConnectionAttempt;
use App\Value\MailboxConnectionErrorCode;

/**
 * Test double for the mailbox-wizard probe. Defaults to success so happy-
 * path controller tests don't need extra setup. Tests that exercise the
 * inline-error UI call {@see willFail()} with the specific error code
 * they want to surface; tests that need to assert the tester was NOT
 * called (e.g. validation-error branches) check {@see wasInvoked()}.
 */
final class FakeMailboxConnectionTester implements MailboxConnectionTester
{
    private bool $shouldFail = false;
    private MailboxConnectionErrorCode $failureCode = MailboxConnectionErrorCode::Unknown;
    private bool $invoked = false;

    public function test(MailboxConnectionAttempt $attempt): ConnectionTestResult
    {
        $this->invoked = true;

        if ($this->shouldFail) {
            return new ConnectionTestResult(
                success: false,
                error: 'Simulated failure: '.$this->failureCode->value,
                mailboxCount: 0,
                errorCode: $this->failureCode,
            );
        }

        return new ConnectionTestResult(
            success: true,
            error: null,
            mailboxCount: 0,
        );
    }

    public function willSucceed(): void
    {
        $this->shouldFail = false;
    }

    public function willFail(MailboxConnectionErrorCode $code): void
    {
        $this->shouldFail = true;
        $this->failureCode = $code;
    }

    public function wasInvoked(): bool
    {
        return $this->invoked;
    }

    public function reset(): void
    {
        $this->shouldFail = false;
        $this->failureCode = MailboxConnectionErrorCode::Unknown;
        $this->invoked = false;
    }
}
