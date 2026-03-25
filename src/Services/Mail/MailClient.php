<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Entity\MailboxConnection;
use App\Value\ConnectionTestResult;
use App\Value\MailMessage;

interface MailClient
{
    /** @return iterable<MailMessage> */
    public function fetchDmarcReports(MailboxConnection $connection): iterable;

    public function markAsProcessed(MailboxConnection $connection, MailMessage $message): void;

    public function testConnection(MailboxConnection $connection): ConnectionTestResult;
}
