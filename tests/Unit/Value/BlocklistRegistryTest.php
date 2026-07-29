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
        self::assertFalse($this->registry->blocksDelivery('dnsbl.sorbs.net'));
        self::assertFalse($this->registry->blocksDelivery('dnsbl-1.uceprotect.net'));
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
        self::assertTrue($this->registry->anyBlocksDelivery(['dnsbl.sorbs.net', 'zen.spamhaus.org']));
        self::assertFalse($this->registry->anyBlocksDelivery(['dnsbl.sorbs.net', 'dnsbl.dronebl.org']));
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
            'Spamhaus ZEN, Spamhaus CBL/XBL and SORBS',
            $this->registry->describeAll(['zen.spamhaus.org', 'cbl.abuseat.org', 'dnsbl.sorbs.net']),
        );
    }
}
