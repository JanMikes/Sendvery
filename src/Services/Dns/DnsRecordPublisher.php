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

    /**
     * Publish (or update in place) the full-policy managed DMARC TXT record at
     * `<customerDomain>._dmarc.<reportDomain>` with the given serialized policy
     * content. Idempotent upsert with a single-record invariant: identical
     * content is a no-op, changed content is updated in place (never a second
     * record). Returns the provider record ID on success, null on failure.
     */
    public function publishPolicyRecord(string $customerDomain, string $policyContent): ?string;

    /**
     * Remove the managed DMARC policy TXT record for the given customer domain.
     * Returns true on success (including when the record didn't exist).
     */
    public function removePolicyRecord(string $customerDomain): bool;

    /**
     * Check whether the managed DMARC policy TXT record exists for the domain.
     */
    public function policyRecordExists(string $customerDomain): bool;

    /**
     * Fetch the current managed DMARC policy TXT record (for content-drift
     * reconciliation), or null when none exists.
     */
    public function findPolicyRecord(string $customerDomain): ?CloudflareDnsRecord;
}
