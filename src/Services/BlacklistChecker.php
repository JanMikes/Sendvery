<?php

declare(strict_types=1);

namespace App\Services;

use App\Value\BlacklistResult;

final readonly class BlacklistChecker
{
    /** @var array<string> */
    private const array DNSBLS = [
        'zen.spamhaus.org',
        'b.barracudacentral.org',
        'dnsbl.sorbs.net',
        'bl.spamcop.net',
        'cbl.abuseat.org',
        'dnsbl-1.uceprotect.net',
        'psbl.surriel.com',
        'dnsbl.dronebl.org',
    ];

    public function check(string $ipAddress): BlacklistResult
    {
        $reversedIp = implode('.', array_reverse(explode('.', $ipAddress)));
        $results = [];
        $isListed = false;

        foreach (self::DNSBLS as $dnsbl) {
            $lookup = $reversedIp.'.'.$dnsbl;
            $listed = false;
            $reason = null;

            $record = @dns_get_record($lookup, DNS_A);

            if (false !== $record && [] !== $record) {
                $listed = true;
                $isListed = true;

                $txtRecords = @dns_get_record($lookup, DNS_TXT);
                if (false !== $txtRecords && [] !== $txtRecords) {
                    $reason = $txtRecords[0]['txt'] ?? null;
                }
            }

            $results[$dnsbl] = [
                'listed' => $listed,
                'reason' => $reason,
            ];
        }

        return new BlacklistResult(
            ipAddress: $ipAddress,
            results: $results,
            isListed: $isListed,
        );
    }

    /**
     * @return array<string>
     */
    public function getDnsblList(): array
    {
        return self::DNSBLS;
    }
}
