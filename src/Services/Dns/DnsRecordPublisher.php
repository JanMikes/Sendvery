<?php

declare(strict_types=1);

namespace App\Services\Dns;

interface DnsRecordPublisher
{
    /**
     * Publish the RFC 7489 authorization TXT record for the given customer
     * domain on the report-address domain's DNS. Returns the provider
     * record ID on success, null on failure.
     */
    public function publishAuthorizationRecord(string $customerDomain): ?string;

    /**
     * Remove the authorization TXT record for the given customer domain.
     * Returns true on success (including when the record didn't exist).
     */
    public function removeAuthorizationRecord(string $customerDomain): bool;

    /**
     * Check whether the authorization TXT record already exists for the
     * given customer domain.
     */
    public function authorizationRecordExists(string $customerDomain): bool;
}
