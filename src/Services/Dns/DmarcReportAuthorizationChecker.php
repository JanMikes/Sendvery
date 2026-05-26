<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Services\ReportAddressProvider;
use Spatie\Dns\Dns;

/**
 * RFC 7489 §7.1: when a domain's rua= points to an address on a DIFFERENT
 * domain, the receiving domain must publish an authorization TXT record at:
 *
 *     {reporting-domain}._report._dmarc.{receiving-domain}
 *
 * containing "v=DMARC1". Without it, most ISPs silently drop the aggregate
 * reports. This checker queries for that record to detect whether Sendvery
 * (or a self-hoster's domain) has published the authorization.
 */
final readonly class DmarcReportAuthorizationChecker
{
    public function __construct(
        private Dns $dns,
        private ReportAddressProvider $reportAddressProvider,
    ) {
    }

    /**
     * Check whether the authorization record exists for the given monitored
     * domain to send reports to the configured report address domain.
     *
     * Returns null when the check doesn't apply (rua domain = monitored domain),
     * true when the authorization record is found, false when it's missing.
     */
    public function check(string $monitoredDomain, ?string $dmarcRawRecord): ?bool
    {
        $reportAddress = $this->reportAddressProvider->get();
        $reportDomain = $this->extractDomain($reportAddress);

        if (null === $reportDomain) {
            return null;
        }

        if (strtolower($reportDomain) === strtolower($monitoredDomain)) {
            return null;
        }

        if (null === $dmarcRawRecord || !$this->ruaIncludesAddress($dmarcRawRecord, $reportAddress)) {
            return null;
        }

        $authDomain = sprintf('%s._report._dmarc.%s', $monitoredDomain, $reportDomain);

        return $this->queryAuthorizationRecord($authDomain);
    }

    public function getReportDomain(): ?string
    {
        return $this->extractDomain($this->reportAddressProvider->get());
    }

    private function queryAuthorizationRecord(string $name): bool
    {
        try {
            $records = $this->dns->getRecords($name, 'TXT');
        } catch (\Throwable) {
            return false;
        }

        foreach ($records as $record) {
            $value = $this->extractTxtValue((string) $record);
            if (str_starts_with(strtolower(trim($value)), 'v=dmarc1')) {
                return true;
            }
        }

        return false;
    }

    private function ruaIncludesAddress(string $dmarcRecord, string $reportAddress): bool
    {
        if (!preg_match('/rua\s*=\s*([^;]+)/i', $dmarcRecord, $matches)) {
            return false;
        }

        $lower = strtolower($reportAddress);
        foreach (explode(',', $matches[1]) as $addr) {
            $addr = strtolower(trim($addr));
            if (str_starts_with($addr, 'mailto:')) {
                $addr = substr($addr, 7);
            }
            if ($addr === $lower) {
                return true;
            }
        }

        return false;
    }

    private function extractDomain(string $email): ?string
    {
        $atPos = strrpos($email, '@');
        if (false === $atPos) {
            return null;
        }

        $domain = substr($email, $atPos + 1);

        return '' !== $domain ? $domain : null;
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
