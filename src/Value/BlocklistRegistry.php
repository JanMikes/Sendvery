<?php

declare(strict_types=1);

namespace App\Value;

/**
 * What each DNS blocklist actually is, and what a listing on it means.
 *
 * The alert used to name the lists and stop there — "listed on: zen.spamhaus.org,
 * cbl.abuseat.org" — which tells a reader nothing about whether their mail is
 * about to stop being delivered or whether an aggregator nobody consults has an
 * opinion. The reported experience was panic without information.
 *
 * `blocksDelivery` is the distinction that matters: Spamhaus and Barracuda are
 * consulted by mailbox providers at SMTP time, so a listing there is an active
 * deliverability incident. SORBS and UCEPROTECT are largely advisory, and
 * treating them with the same urgency is how a product trains people to ignore
 * its alerts.
 */
final readonly class BlocklistRegistry
{
    /** @var array<string, array{name: string, operator: string, blocksDelivery: bool, delistUrl: string|null}> */
    private const array LISTS = [
        'zen.spamhaus.org' => [
            'name' => 'Spamhaus ZEN',
            'operator' => 'Spamhaus',
            'blocksDelivery' => true,
            'delistUrl' => 'https://check.spamhaus.org/',
        ],
        'cbl.abuseat.org' => [
            'name' => 'Spamhaus CBL/XBL',
            'operator' => 'Spamhaus',
            'blocksDelivery' => true,
            'delistUrl' => 'https://check.spamhaus.org/',
        ],
        'b.barracudacentral.org' => [
            'name' => 'Barracuda Reputation Block List',
            'operator' => 'Barracuda',
            'blocksDelivery' => true,
            'delistUrl' => 'https://www.barracudacentral.org/rbl/removal-request',
        ],
        'bl.spamcop.net' => [
            'name' => 'SpamCop Blocking List',
            'operator' => 'SpamCop (Cisco Talos)',
            'blocksDelivery' => true,
            'delistUrl' => 'https://www.spamcop.net/bl.shtml',
        ],
        'psbl.surriel.com' => [
            'name' => 'Passive Spam Block List',
            'operator' => 'Surriel',
            'blocksDelivery' => false,
            'delistUrl' => 'https://psbl.org/remove',
        ],
        // Retained for historical rows only — SORBS shut down in June 2024 and
        // BlacklistChecker no longer queries it. Without this entry, a stored
        // result from before the removal would render as a bare hostname.
        'dnsbl.sorbs.net' => [
            'name' => 'SORBS (discontinued)',
            'operator' => 'SORBS',
            'blocksDelivery' => false,
            'delistUrl' => null,
        ],
        'dnsbl-1.uceprotect.net' => [
            'name' => 'UCEPROTECT Level 1',
            'operator' => 'UCEPROTECT',
            'blocksDelivery' => false,
            'delistUrl' => 'https://www.uceprotect.net/en/rblcheck.php',
        ],
        'dnsbl.dronebl.org' => [
            'name' => 'DroneBL',
            'operator' => 'DroneBL',
            'blocksDelivery' => false,
            'delistUrl' => 'https://dronebl.org/lookup',
        ],
    ];

    public function name(string $dnsbl): string
    {
        return self::LISTS[$dnsbl]['name'] ?? $dnsbl;
    }

    public function operator(string $dnsbl): ?string
    {
        return self::LISTS[$dnsbl]['operator'] ?? null;
    }

    /**
     * Is this list actually consulted by mailbox providers at delivery time?
     *
     * Unknown lists answer false: a list we have not classified must not be
     * able to escalate an alert to Critical by default.
     */
    public function blocksDelivery(string $dnsbl): bool
    {
        return self::LISTS[$dnsbl]['blocksDelivery'] ?? false;
    }

    public function delistUrl(string $dnsbl): ?string
    {
        return self::LISTS[$dnsbl]['delistUrl'] ?? null;
    }

    /**
     * @param list<string> $dnsbls
     */
    public function anyBlocksDelivery(array $dnsbls): bool
    {
        foreach ($dnsbls as $dnsbl) {
            if ($this->blocksDelivery($dnsbl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human-readable names for a set of lists, e.g. "Spamhaus ZEN and Barracuda…".
     *
     * @param list<string> $dnsbls
     */
    public function describeAll(array $dnsbls): string
    {
        $names = array_map($this->name(...), $dnsbls);

        if (count($names) <= 2) {
            return implode(' and ', $names);
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }
}
