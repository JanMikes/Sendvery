<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DnsRecordAction;
use App\Value\Dns\SetupTier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary of the guided setup surface. Every tier and every record action
 * has to read as an instruction on its own — a user glancing at the page should
 * not have to infer meaning from a colour.
 */
final class SetupTierTest extends TestCase
{
    #[Test]
    public function everyTierNamesItselfAndExplainsWhatBelongsInIt(): void
    {
        foreach (SetupTier::cases() as $tier) {
            self::assertNotSame('', $tier->label());
            self::assertNotSame('', $tier->description());
            self::assertNotSame('', $tier->tone());
        }
    }

    #[Test]
    public function theTiersReadAsAnOrderedInstructionNotAsThreeSynonyms(): void
    {
        self::assertSame('Action required now', SetupTier::ActionRequired->label());
        self::assertSame('Waiting on you later', SetupTier::Later->label());
        self::assertSame('Done', SetupTier::Done->label());
    }

    #[Test]
    public function tierTonesFollowUrgencyRatherThanBeingDecorative(): void
    {
        self::assertSame('warning', SetupTier::ActionRequired->tone());
        self::assertSame('info', SetupTier::Later->tone());
        self::assertSame('success', SetupTier::Done->tone());
    }

    #[Test]
    public function everyRecordActionStatesTheVerbTheUserPerformsAtTheirProvider(): void
    {
        self::assertSame('Add a new record', DnsRecordAction::AddNew->label());
        self::assertSame('Edit the existing record', DnsRecordAction::EditExisting->label());
        self::assertSame('Nothing to do', DnsRecordAction::NothingToDo->label());

        foreach (DnsRecordAction::cases() as $action) {
            self::assertNotSame('', $action->tone());
        }
    }

    #[Test]
    public function anExistingValueMeansEditAndAnAbsentOneMeansAdd(): void
    {
        // Telling a user to "add" a record when one already exists at that host
        // is how domains end up with two SPF or two DMARC records.
        self::assertSame(DnsRecordAction::EditExisting, DnsRecordAction::forCurrentValue('v=spf1 -all'));
        self::assertSame(DnsRecordAction::AddNew, DnsRecordAction::forCurrentValue(null));
        self::assertSame(DnsRecordAction::AddNew, DnsRecordAction::forCurrentValue(''));
        self::assertSame(DnsRecordAction::AddNew, DnsRecordAction::forCurrentValue('   '));
    }
}
