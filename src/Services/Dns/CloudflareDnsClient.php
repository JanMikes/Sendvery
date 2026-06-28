<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Services\ReportAddressProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CloudflareDnsClient implements DnsRecordPublisher
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ReportAddressProvider $reportAddressProvider,
        private LoggerInterface $logger,
        #[Autowire(env: 'CLOUDFLARE_API_TOKEN')]
        private string $apiToken,
        #[Autowire(env: 'CLOUDFLARE_ZONE_ID')]
        private string $zoneId,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiToken && '' !== $this->zoneId;
    }

    public function publishAuthorizationRecord(string $customerDomain): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $name = $this->buildRecordName($customerDomain);
        $response = $this->apiRequest('POST', $this->dnsRecordsUrl(), [
            'type' => 'TXT',
            'name' => $name,
            'content' => 'v=DMARC1;',
            'ttl' => 1,
            'comment' => sprintf('DMARC report authorization for %s', $customerDomain),
        ]);

        if (null === $response) {
            return null;
        }

        if (isset($response['result']['id'])) {
            $this->logger->info('Published DMARC authorization record for {domain}', ['domain' => $customerDomain]);

            return $response['result']['id'];
        }

        if ($this->isDuplicateError($response)) {
            $existing = $this->findTxtRecord($name);
            if (null !== $existing) {
                $this->logger->info('DMARC authorization record already exists for {domain}', ['domain' => $customerDomain]);

                return $existing->id;
            }
        }

        $this->logger->error('Failed to publish DMARC authorization record for {domain}', [
            'domain' => $customerDomain,
            'response' => $response,
        ]);

        return null;
    }

    public function removeAuthorizationRecord(string $customerDomain): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $name = $this->buildRecordName($customerDomain);
        $record = $this->findTxtRecord($name);

        if (null === $record) {
            return true;
        }

        return $this->deleteRecordById($record->id);
    }

    public function authorizationRecordExists(string $customerDomain): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $name = $this->buildRecordName($customerDomain);

        return null !== $this->findTxtRecord($name);
    }

    public function publishPolicyRecord(string $customerDomain, string $policyContent): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $name = $this->buildPolicyRecordName($customerDomain);

        // Tri-state lookup. A *failed* GET must never be read as "no record
        // exists" — otherwise a transient Cloudflare error on the update path
        // would fall through to POST and create a SECOND TXT at the same name.
        // Two DMARC records => receivers PERMERROR => the customer's policy
        // silently breaks. So: lookup failed -> abort (the sync cron retries);
        // lookup succeeded with a record -> PATCH in place; succeeded empty -> POST.
        $lookup = $this->apiRequest('GET', $this->dnsRecordsUrl(), query: [
            'type' => 'TXT',
            'name' => $name,
        ]);

        if (null === $lookup || !isset($lookup['result']) || !is_array($lookup['result'])) {
            $this->capturePolicyFailure('lookup', $customerDomain, $lookup);

            return null;
        }

        $existing = $this->firstTxtRecord($lookup['result']);

        // Strict upsert with a single-record invariant: never POST-on-change
        // (Cloudflare allows multiple TXT at one name; two DMARC records make
        // receivers permerror and silently break the policy).
        if (null !== $existing) {
            if (trim($existing->content) === trim($policyContent)) {
                return $existing->id;
            }

            $response = $this->apiRequest('PATCH', sprintf('%s/%s', $this->dnsRecordsUrl(), $existing->id), [
                'type' => 'TXT',
                'name' => $name,
                'content' => $policyContent,
                'ttl' => 1,
                'comment' => sprintf('Managed DMARC policy for %s', $customerDomain),
            ]);

            if (null !== $response && isset($response['result']['id'])) {
                $this->logger->info('Updated managed DMARC policy record for {domain}', ['domain' => $customerDomain]);

                return $response['result']['id'];
            }

            $this->capturePolicyFailure('update', $customerDomain, $response);

            return null;
        }

        $response = $this->apiRequest('POST', $this->dnsRecordsUrl(), [
            'type' => 'TXT',
            'name' => $name,
            'content' => $policyContent,
            'ttl' => 1,
            'comment' => sprintf('Managed DMARC policy for %s', $customerDomain),
        ]);

        if (null !== $response && isset($response['result']['id'])) {
            $this->logger->info('Published managed DMARC policy record for {domain}', ['domain' => $customerDomain]);

            return $response['result']['id'];
        }

        // A concurrent publish may have created the record first — clean down to one.
        if (null !== $response && $this->isDuplicateError($response)) {
            $existing = $this->findTxtRecord($name);
            if (null !== $existing) {
                return $existing->id;
            }
        }

        $this->capturePolicyFailure('publish', $customerDomain, $response);

        return null;
    }

    public function removePolicyRecord(string $customerDomain): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $record = $this->findTxtRecord($this->buildPolicyRecordName($customerDomain));
        if (null === $record) {
            return true;
        }

        $deleted = $this->deleteRecordById($record->id);
        if (!$deleted) {
            $this->capturePolicyFailure('delete', $customerDomain, null);
        }

        return $deleted;
    }

    public function policyRecordExists(string $customerDomain): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        return null !== $this->findTxtRecord($this->buildPolicyRecordName($customerDomain));
    }

    public function findPolicyRecord(string $customerDomain): ?CloudflareDnsRecord
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return $this->findTxtRecord($this->buildPolicyRecordName($customerDomain));
    }

    /**
     * List every hosted managed DMARC policy record. Explicitly excludes the
     * `._report._dmarc` authorization records, which collide on the
     * `._dmarc.<reportDomain>` suffix.
     *
     * @return array<CloudflareDnsRecord>
     */
    public function listPolicyRecords(): array
    {
        $reportDomain = $this->getReportDomain();
        if (null === $reportDomain) {
            return [];
        }

        $records = [];
        $page = 1;

        do {
            $response = $this->apiRequest('GET', $this->dnsRecordsUrl(), query: [
                'type' => 'TXT',
                'name' => sprintf('contains:._dmarc.%s', $reportDomain),
                'per_page' => 100,
                'page' => $page,
            ]);

            if (null === $response || !isset($response['result']) || !is_array($response['result'])) {
                break;
            }

            foreach ($response['result'] as $record) {
                if (is_array($record) && isset($record['id'], $record['name'], $record['content'])) {
                    /** @var array{id: string, name: string, content: string, comment?: string, created_on?: string} $record */
                    if (str_contains($record['name'], '._report._dmarc.')) {
                        continue;
                    }

                    $records[] = CloudflareDnsRecord::fromApiResponse($record);
                }
            }

            $totalPages = $response['result_info']['total_pages'] ?? 1;
            ++$page;
        } while ($page <= $totalPages);

        return $records;
    }

    public function extractPolicyCustomerDomain(CloudflareDnsRecord $record): ?string
    {
        $reportDomain = $this->getReportDomain();
        if (null === $reportDomain) {
            return null;
        }

        // Never mistake an authorization record for a policy record (the
        // `._report._dmarc.<reportDomain>` name ends with `._dmarc.<reportDomain>`).
        if (str_contains($record->name, '._report._dmarc.')) {
            return null;
        }

        $suffix = sprintf('._dmarc.%s', $reportDomain);
        if (!str_ends_with($record->name, $suffix)) {
            return null;
        }

        $domain = substr($record->name, 0, -strlen($suffix));

        return '' !== $domain ? $domain : null;
    }

    public function deleteRecordById(string $recordId): bool
    {
        $response = $this->apiRequest('DELETE', sprintf('%s/%s', $this->dnsRecordsUrl(), $recordId));

        if (null === $response) {
            return false;
        }

        if (true === ($response['success'] ?? false)) {
            return true;
        }

        if ($this->isNotFoundError($response)) {
            return true;
        }

        $this->logger->error('Failed to delete Cloudflare DNS record {recordId}', [
            'recordId' => $recordId,
            'response' => $response,
        ]);

        return false;
    }

    public function findTxtRecord(string $name): ?CloudflareDnsRecord
    {
        $response = $this->apiRequest('GET', $this->dnsRecordsUrl(), query: [
            'type' => 'TXT',
            'name' => $name,
        ]);

        if (null === $response || !isset($response['result']) || !is_array($response['result'])) {
            return null;
        }

        return $this->firstTxtRecord($response['result']);
    }

    /**
     * The first well-formed TXT record from a Cloudflare list `result`, or null
     * if none. Shared by findTxtRecord and the policy upsert so both interpret a
     * list payload identically.
     *
     * @param array<mixed> $result
     */
    private function firstTxtRecord(array $result): ?CloudflareDnsRecord
    {
        foreach ($result as $record) {
            if (is_array($record) && isset($record['id'], $record['name'], $record['content'])) {
                /* @var array{id: string, name: string, content: string, comment?: string, created_on?: string} $record */
                return CloudflareDnsRecord::fromApiResponse($record);
            }
        }

        return null;
    }

    /** @return array<CloudflareDnsRecord> */
    public function listAuthorizationRecords(): array
    {
        $reportDomain = $this->getReportDomain();
        if (null === $reportDomain) {
            return [];
        }

        $records = [];
        $page = 1;

        do {
            $response = $this->apiRequest('GET', $this->dnsRecordsUrl(), query: [
                'type' => 'TXT',
                'name' => sprintf('contains:._report._dmarc.%s', $reportDomain),
                'per_page' => 100,
                'page' => $page,
            ]);

            if (null === $response || !isset($response['result']) || !is_array($response['result'])) {
                break;
            }

            foreach ($response['result'] as $record) {
                if (is_array($record) && isset($record['id'], $record['name'], $record['content'])) {
                    /* @var array{id: string, name: string, content: string, comment?: string, created_on?: string} $record */
                    $records[] = CloudflareDnsRecord::fromApiResponse($record);
                }
            }

            $totalPages = $response['result_info']['total_pages'] ?? 1;
            ++$page;
        } while ($page <= $totalPages);

        return $records;
    }

    public function extractCustomerDomain(CloudflareDnsRecord $record): ?string
    {
        $reportDomain = $this->getReportDomain();
        if (null === $reportDomain) {
            return null;
        }

        $suffix = sprintf('._report._dmarc.%s', $reportDomain);
        if (!str_ends_with($record->name, $suffix)) {
            return null;
        }

        $domain = substr($record->name, 0, -strlen($suffix));

        return '' !== $domain ? $domain : null;
    }

    private function buildRecordName(string $customerDomain): string
    {
        $reportDomain = $this->getReportDomain();

        if (null === $reportDomain) {
            throw new \RuntimeException('SENDVERY_REPORT_ADDRESS is missing or malformed — cannot build authorization record name.');
        }

        return sprintf('%s._report._dmarc.%s', strtolower($customerDomain), $reportDomain);
    }

    private function buildPolicyRecordName(string $customerDomain): string
    {
        $reportDomain = $this->getReportDomain();

        if (null === $reportDomain) {
            throw new \RuntimeException('SENDVERY_REPORT_ADDRESS is missing or malformed — cannot build policy record name.');
        }

        return sprintf('%s._dmarc.%s', strtolower($customerDomain), $reportDomain);
    }

    /** @param array<string, mixed>|null $response */
    private function capturePolicyFailure(string $action, string $customerDomain, ?array $response): void
    {
        $this->logger->error('Failed to {action} managed DMARC policy record for {domain}', [
            'action' => $action,
            'domain' => $customerDomain,
            'response' => $response,
        ]);

        // Surface to Sentry so a stuck publish is observable — the record id
        // stays unset and the sync cron retries, but we must not fail silently.
        \Sentry\captureException(new \RuntimeException(sprintf('Cloudflare managed DMARC %s failed for %s', $action, $customerDomain)));
    }

    private function getReportDomain(): ?string
    {
        return $this->reportAddressProvider->getReportDomain();
    }

    private function dnsRecordsUrl(): string
    {
        return sprintf('%s/zones/%s/dns_records', self::BASE_URL, $this->zoneId);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>|null $query
     *
     * @return array<string, mixed>|null
     */
    private function apiRequest(string $method, string $url, ?array $body = null, ?array $query = null): ?array
    {
        $options = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->apiToken),
                'Content-Type' => 'application/json',
            ],
        ];

        if (null !== $body && 'GET' !== $method) {
            $options['json'] = $body;
        }

        if (null !== $query) {
            $options['query'] = $query;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);

            return $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Cloudflare API request failed: {error}', [
                'error' => $e->getMessage(),
                'method' => $method,
                'url' => $url,
            ]);

            return null;
        }
    }

    /** @param array<string, mixed> $response */
    private function isDuplicateError(array $response): bool
    {
        foreach ($response['errors'] ?? [] as $error) {
            if (isset($error['code']) && 81057 === $error['code']) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $response */
    private function isNotFoundError(array $response): bool
    {
        foreach ($response['errors'] ?? [] as $error) {
            if (isset($error['code']) && 81044 === $error['code']) {
                return true;
            }
        }

        return false;
    }
}
