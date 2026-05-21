<?php

declare(strict_types=1);

namespace App\Services\Dns;

use IPLib\Address\AddressInterface;
use Spatie\Dns\Records\TXT;
use SPFLib\DNS\Resolver;

/**
 * Test-environment Resolver for SPFLib. SPFLib's StandardResolver uses native
 * dns_get_record(), which is NOT covered by symfony/phpunit-bridge's dns-mock
 * because SPFLib lives outside the App namespace. Without this wrapping, every
 * SpfChecker run in tests would query real DNS.
 *
 * Delegates TXT lookups to FakeDns so the scripting trait sets records once
 * and both spatie/dns + SPFLib see them. Methods we don't currently exercise
 * (MX/PTR/A/PTR reverse) return empty so any accidental dependency surfaces
 * as a clean "no records" rather than a flaky network call.
 */
final readonly class FakeSpfResolver implements Resolver
{
    public function __construct(
        private FakeDns $dns,
    ) {
    }

    /**
     * @return string[]
     */
    public function getTXTRecords(string $domain): array
    {
        $records = $this->dns->getRecords($domain, 'TXT');
        $values = [];
        foreach ($records as $record) {
            if ($record instanceof TXT) {
                /** @var array{txt: string} $row */
                $row = $record->toArray();
                $values[] = $row['txt'];
            }
        }

        return $values;
    }

    /**
     * @return AddressInterface[]
     */
    public function getIPAddressesFromDomainName(string $domain): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getMXRecords(string $domain): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getPTRRecords(string $domain): array
    {
        return [];
    }

    public function getDomainNameFromIPAddress(AddressInterface $ip): string
    {
        return '';
    }
}
