<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Services\OrganizationMapper;
use Spatie\Dns\Dns;
use Spatie\Dns\Records\MX;

/**
 * Detects which email service provider(s) a domain uses by inspecting its
 * MX records and SPF `include:` mechanisms. Used to focus DKIM selector
 * probing on the right candidates instead of blindly brute-forcing.
 */
final readonly class EmailProviderDetector
{
    public function __construct(
        private Dns $dns,
        private OrganizationMapper $organizationMapper,
    ) {
    }

    /** @return list<string> distinct provider names */
    public function detect(string $domain): array
    {
        $detected = [];

        foreach ($this->lookupMxHosts($domain) as $host) {
            $provider = $this->organizationMapper->resolve($host);
            if (null !== $provider) {
                $detected[$provider] = true;
            }
        }

        foreach ($this->lookupSpfIncludes($domain) as $include) {
            $provider = $this->organizationMapper->resolve($include);
            if (null !== $provider) {
                $detected[$provider] = true;
            }
        }

        return array_keys($detected);
    }

    /** @return list<string> */
    private function lookupMxHosts(string $domain): array
    {
        try {
            $records = $this->dns->getRecords($domain, 'MX');
        } catch (\Throwable) {
            return [];
        }

        $hosts = [];
        foreach ($records as $record) {
            if ($record instanceof MX) {
                $host = rtrim($record->target(), '.');
                if ('' !== $host) {
                    $hosts[] = $host;
                }
            }
        }

        return $hosts;
    }

    /** @return list<string> */
    private function lookupSpfIncludes(string $domain): array
    {
        try {
            $records = $this->dns->getRecords($domain, 'TXT');
        } catch (\Throwable) {
            return [];
        }

        foreach ($records as $record) {
            $value = $this->extractTxtValue((string) $record);
            if (!str_starts_with($value, 'v=spf1')) {
                continue;
            }

            return $this->parseSpfIncludes($value);
        }

        return [];
    }

    /** @return list<string> */
    private function parseSpfIncludes(string $spfRecord): array
    {
        $includes = [];

        foreach (preg_split('/\s+/', $spfRecord) ?: [] as $token) {
            if (str_starts_with($token, 'include:')) {
                $includes[] = substr($token, 8);

                continue;
            }
            if (str_starts_with($token, 'redirect=')) {
                $includes[] = substr($token, 9);
            }
        }

        return $includes;
    }

    private function extractTxtValue(string $record): string
    {
        if (preg_match('/TXT\s+"?(.+?)"?\s*$/', $record, $matches)) {
            return trim($matches[1], '"');
        }

        if (preg_match_all('/"([^"]*)"/', $record, $matches)) {
            return implode('', $matches[1]);
        }

        return $record;
    }
}
