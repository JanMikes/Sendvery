<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Ramsey\Uuid\UuidInterface;

readonly final class ConnectMailbox
{
    public function __construct(
        public UuidInterface $connectionId,
        public UuidInterface $teamId,
        public ?UuidInterface $domainId,
        public MailboxType $type,
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public MailboxEncryption $encryption,
    ) {
    }
}
