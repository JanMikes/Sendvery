<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DnsIssue;
use App\Value\Dns\IssueSeverity;
use App\Value\Dns\MxCheckResult;
use App\Value\Dns\MxRecord;
use Spatie\Dns\Dns;

readonly final class MxChecker
{
    public function __construct(
        private Dns $dns,
    ) {
    }

    public function check(string $domain): MxCheckResult
    {
        try {
            $dnsRecords = $this->dns->getRecords($domain, 'MX');
        } catch (\Throwable) {
            return new MxCheckResult(
                records: [],
                issues: [new DnsIssue(IssueSeverity::Critical, 'Failed to query MX records for this domain.')],
            );
        }

        $records = [];
        foreach ($dnsRecords as $dnsRecord) {
            $parsed = $this->parseMxRecord((string) $dnsRecord);
            if ($parsed !== null) {
                $records[] = $parsed;
            }
        }

        if ($records === []) {
            return new MxCheckResult(
                records: [],
                issues: [new DnsIssue(IssueSeverity::Warning, 'No MX records found. This domain cannot receive email.', 'Add MX records pointing to your mail server.')],
            );
        }

        usort($records, static fn (MxRecord $a, MxRecord $b): int => $a->priority <=> $b->priority);

        $issues = [];

        $anyReachable = false;
        foreach ($records as $record) {
            if ($record->reachable) {
                $anyReachable = true;
            }
        }

        if (!$anyReachable) {
            $issues[] = new DnsIssue(IssueSeverity::Warning, 'None of the MX servers responded on port 25. Mail delivery may be impaired.', 'Verify your mail servers are running and accepting connections.');
        }

        $anyTlsMissing = false;
        foreach ($records as $record) {
            if ($record->reachable && $record->tlsSupported === false) {
                $anyTlsMissing = true;
            }
        }

        if ($anyTlsMissing) {
            $issues[] = new DnsIssue(IssueSeverity::Warning, 'Some MX servers do not support STARTTLS. Email may be transmitted in plaintext.', 'Enable STARTTLS on your mail servers.');
        }

        return new MxCheckResult(
            records: $records,
            issues: $issues,
        );
    }

    private function parseMxRecord(string $record): ?MxRecord
    {
        // Format: "example.com.  3600  IN  MX  10 mail.example.com."
        if (!preg_match('/MX\s+(\d+)\s+(\S+)/', $record, $matches)) {
            return null;
        }

        $priority = (int) $matches[1];
        $host = rtrim($matches[2], '.');

        $ip = $this->resolveHost($host);
        $reachable = false;
        $tlsSupported = null;

        if ($ip !== null) {
            $reachable = $this->checkPort25($ip);
            if ($reachable) {
                $tlsSupported = $this->checkStartTls($ip);
            }
        }

        return new MxRecord(
            host: $host,
            priority: $priority,
            ip: $ip,
            reachable: $reachable,
            tlsSupported: $tlsSupported,
        );
    }

    private function resolveHost(string $host): ?string
    {
        try {
            $records = $this->dns->getRecords($host, 'A');
            foreach ($records as $record) {
                if (preg_match('/A\s+(\d+\.\d+\.\d+\.\d+)/', (string) $record, $matches)) {
                    return $matches[1];
                }
            }
        } catch (\Throwable) {
            // Fallback: try native DNS
        }

        $ip = gethostbyname($host);

        return $ip !== $host ? $ip : null;
    }

    private function checkPort25(string $ip): bool
    {
        $socket = @fsockopen($ip, 25, $errno, $errstr, 3);
        if ($socket === false) {
            return false;
        }

        $response = @fgets($socket, 1024);
        fclose($socket);

        return $response !== false && str_starts_with($response, '220');
    }

    private function checkStartTls(string $ip): bool
    {
        $socket = @fsockopen($ip, 25, $errno, $errstr, 3);
        if ($socket === false) {
            return false;
        }

        // Read banner
        @fgets($socket, 1024);

        // Send EHLO
        fwrite($socket, "EHLO sendvery.com\r\n");

        $ehloResponse = '';
        while ($line = @fgets($socket, 1024)) {
            $ehloResponse .= $line;
            // Multi-line responses have - after status code, last line has space
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return stripos($ehloResponse, 'STARTTLS') !== false;
    }
}
