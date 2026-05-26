<?php

declare(strict_types=1);

namespace App\Services\Dns;

final class FakeDnsRecordPublisher implements DnsRecordPublisher
{
    /** @var array<string, string> domain => recordId */
    private array $publishedRecords = [];

    private bool $shouldFail = false;

    public function publishAuthorizationRecord(string $customerDomain): ?string
    {
        if ($this->shouldFail) {
            return null;
        }

        $recordId = sprintf('fake-cf-record-%s', md5($customerDomain));
        $this->publishedRecords[$customerDomain] = $recordId;

        return $recordId;
    }

    public function removeAuthorizationRecord(string $customerDomain): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        unset($this->publishedRecords[$customerDomain]);

        return true;
    }

    public function authorizationRecordExists(string $customerDomain): bool
    {
        return isset($this->publishedRecords[$customerDomain]);
    }

    public function simulateFailure(): void
    {
        $this->shouldFail = true;
    }

    public function simulateSuccess(): void
    {
        $this->shouldFail = false;
    }

    /** @return array<string, string> */
    public function getPublishedRecords(): array
    {
        return $this->publishedRecords;
    }

    public function getRecordId(string $customerDomain): ?string
    {
        return $this->publishedRecords[$customerDomain] ?? null;
    }
}
