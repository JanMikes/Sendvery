<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Services\BlacklistChecker;
use App\Value\BlocklistRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlocklistRegistryTest extends TestCase
{
    private BlocklistRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new BlocklistRegistry();
    }

    #[Test]
    public function everyBlocklistWeQueryCanBeDescribed(): void
    {
        // A list Sendvery checks but cannot name would surface in an alert as a
        // bare hostname — the exact opacity this registry exists to remove.
        foreach ((new BlacklistChecker())->getDnsblList() as $dnsbl) {
            self::assertNotSame($dnsbl, $this->registry->name($dnsbl), "No display name for {$dnsbl}");
            self::assertNotNull($this->registry->delistUrl($dnsbl), "No delisting URL for {$dnsbl}");
            self::assertNotNull($this->registry->operator($dnsbl), "No operator for {$dnsbl}");
        }
    }

    #[Test]
    public function listsMailboxProvidersActuallyQueryAreMarkedAsBlockingDelivery(): void
    {
        self::assertTrue($this->registry->blocksDelivery('zen.spamhaus.org'));
        self::assertTrue($this->registry->blocksDelivery('b.barracudacentral.org'));
    }

    #[Test]
    public function advisoryListsAreNotMarkedAsBlockingDelivery(): void
    {
        self::assertFalse($this->registry->blocksDelivery('psbl.surriel.com'));
        self::assertFalse($this->registry->blocksDelivery('dnsbl-1.uceprotect.net'));
    }

    #[Test]
    public function aDiscontinuedListIsStillNameableForHistoricalRowsButIsNoLongerQueried(): void
    {
        // SORBS shut down in June 2024 — the zone has no NS and no SOA, so it
        // could only ever answer NXDOMAIN, which reads as "not listed" and
        // silently padded every verdict. It is no longer queried, but stored
        // results that predate the removal must still render with a name.
        self::assertNotContains('dnsbl.sorbs.net', (new BlacklistChecker())->getDnsblList());
        self::assertSame('SORBS (discontinued)', $this->registry->name('dnsbl.sorbs.net'));
        self::assertNull(
            $this->registry->delistUrl('dnsbl.sorbs.net'),
            'There is nowhere to delist from a list that no longer exists.',
        );
    }

    #[Test]
    public function anUnknownListCannotEscalateAnAlertByDefault(): void
    {
        self::assertFalse($this->registry->blocksDelivery('someone.new.example'));
        self::assertSame('someone.new.example', $this->registry->name('someone.new.example'));
        self::assertNull($this->registry->delistUrl('someone.new.example'));
    }

    #[Test]
    public function anyBlocksDeliveryIsTrueWhenAtLeastOneListGatesDelivery(): void
    {
        self::assertTrue($this->registry->anyBlocksDelivery(['psbl.surriel.com', 'zen.spamhaus.org']));
        self::assertFalse($this->registry->anyBlocksDelivery(['psbl.surriel.com', 'dnsbl.dronebl.org']));
        self::assertFalse($this->registry->anyBlocksDelivery([]));
    }

    #[Test]
    public function describeAllReadsAsASentence(): void
    {
        self::assertSame('Spamhaus ZEN', $this->registry->describeAll(['zen.spamhaus.org']));
        self::assertSame(
            'Spamhaus ZEN and Spamhaus CBL/XBL',
            $this->registry->describeAll(['zen.spamhaus.org', 'cbl.abuseat.org']),
        );
        self::assertSame(
            'Spamhaus ZEN, Spamhaus CBL/XBL and SpamCop Blocking List',
            $this->registry->describeAll(['zen.spamhaus.org', 'cbl.abuseat.org', 'bl.spamcop.net']),
        );
    }
}
