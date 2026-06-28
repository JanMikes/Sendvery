<?php

declare(strict_types=1);

namespace App\Services\Dns;

final class FakeDnsRecordPublisher implements DnsRecordPublisher
{
    /** @var array<string, string> domain => recordId */
    private array $publishedRecords = [];

    /** @var array<string, string> domain => serialized policy content */
    private array $policyRecords = [];

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

    public function publishPolicyRecord(string $customerDomain, string $policyContent): ?string
    {
        if ($this->shouldFail) {
            return null;
        }

        $this->policyRecords[$customerDomain] = $policyContent;

        return sprintf('fake-cf-policy-%s', md5($customerDomain));
    }

    public function removePolicyRecord(string $customerDomain): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        unset($this->policyRecords[$customerDomain]);

        return true;
    }

    public function policyRecordExists(string $customerDomain): bool
    {
        return isset($this->policyRecords[$customerDomain]);
    }

    public function findPolicyRecord(string $customerDomain): ?CloudflareDnsRecord
    {
        if (!isset($this->policyRecords[$customerDomain])) {
            return null;
        }

        return new CloudflareDnsRecord(
            id: sprintf('fake-cf-policy-%s', md5($customerDomain)),
            name: sprintf('%s._dmarc.fake', strtolower($customerDomain)),
            content: $this->policyRecords[$customerDomain],
            comment: sprintf('Managed DMARC policy for %s', $customerDomain),
            createdOn: '',
        );
    }

    /** Test assertion helper — the serialized policy content last published. */
    public function getPublishedPolicyContent(string $customerDomain): ?string
    {
        return $this->policyRecords[$customerDomain] ?? null;
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
