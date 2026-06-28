<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single source of truth for the email address customers point their DMARC
 * `rua=` records at. Sendvery SaaS uses `reports@sendvery.com`; self-hosters
 * override via SENDVERY_REPORT_ADDRESS to receive reports at their own inbox.
 */
final readonly class ReportAddressProvider
{
    public function __construct(
        #[Autowire(env: 'SENDVERY_REPORT_ADDRESS')]
        private string $reportAddress,
    ) {
    }

    public function get(): string
    {
        return $this->reportAddress;
    }

    /**
     * The domain portion of the report address — the zone Sendvery hosts DMARC
     * authorization and managed-policy records in. Returns null when the
     * address is missing or malformed (no `@`). This is the single source of
     * truth for report-domain derivation across the DNS services (the
     * Cloudflare client, the §7.1 authorization checker, and the managed-CNAME
     * checker). Prod resolves to `sendvery.com`; tests to `sendvery.test`.
     */
    public function getReportDomain(): ?string
    {
        $atPos = strrpos($this->reportAddress, '@');
        if (false === $atPos) {
            return null;
        }

        $domain = substr($this->reportAddress, $atPos + 1);

        return '' !== $domain ? $domain : null;
    }
}
