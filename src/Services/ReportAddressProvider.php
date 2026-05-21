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
}
