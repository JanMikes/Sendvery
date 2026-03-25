<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class ProcessDmarcReport
{
    public function __construct(
        public UuidInterface $reportId,
        public UuidInterface $domainId,
        public string $xmlContent,
    ) {
    }
}
