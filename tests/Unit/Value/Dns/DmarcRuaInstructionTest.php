<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\DmarcRuaInstruction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DmarcRuaInstructionTest extends TestCase
{
    #[Test]
    public function buildsBrandNewRecordWhenCurrentIsNull(): void
    {
        $instruction = DmarcRuaInstruction::build(null, 'reports@sendvery.com');

        self::assertNull($instruction->currentRecord);
        self::assertFalse($instruction->alreadyConfigured);
        self::assertStringContainsString('v=DMARC1', $instruction->finalRecord);
        self::assertStringContainsString('p=none', $instruction->finalRecord);
        self::assertStringContainsString('rua=mailto:reports@sendvery.com', $instruction->finalRecord);
    }

    #[Test]
    public function buildsBrandNewRecordWhenCurrentIsEmpty(): void
    {
        $instruction = DmarcRuaInstruction::build('', 'reports@sendvery.com');

        self::assertSame('', $instruction->currentRecord);
        self::assertFalse($instruction->alreadyConfigured);
        self::assertStringContainsString('rua=mailto:reports@sendvery.com', $instruction->finalRecord);
    }

    #[Test]
    public function buildsBrandNewRecordWhenCurrentIsGarbage(): void
    {
        $instruction = DmarcRuaInstruction::build('something-random', 'reports@sendvery.com');

        self::assertSame('something-random', $instruction->currentRecord);
        self::assertFalse($instruction->alreadyConfigured);
        self::assertStringStartsWith('v=DMARC1', $instruction->finalRecord);
    }

    #[Test]
    public function appendsRuaToExistingDmarcWithoutRua(): void
    {
        $instruction = DmarcRuaInstruction::build('v=DMARC1; p=quarantine', 'reports@sendvery.com');

        self::assertFalse($instruction->alreadyConfigured);
        self::assertStringContainsString('v=DMARC1', $instruction->finalRecord);
        self::assertStringContainsString('p=quarantine', $instruction->finalRecord);
        self::assertStringContainsString('rua=mailto:reports@sendvery.com', $instruction->finalRecord);
    }

    #[Test]
    public function appendsToExistingRuaList(): void
    {
        $instruction = DmarcRuaInstruction::build(
            'v=DMARC1; p=reject; rua=mailto:dmarc@example.com',
            'reports@sendvery.com',
        );

        self::assertFalse($instruction->alreadyConfigured);
        self::assertStringContainsString('mailto:dmarc@example.com', $instruction->finalRecord);
        self::assertStringContainsString('mailto:reports@sendvery.com', $instruction->finalRecord);
    }

    #[Test]
    public function detectsAlreadyConfiguredAddress(): void
    {
        $existing = 'v=DMARC1; p=reject; rua=mailto:reports@sendvery.com';
        $instruction = DmarcRuaInstruction::build($existing, 'reports@sendvery.com');

        self::assertTrue($instruction->alreadyConfigured);
        self::assertSame($existing, $instruction->finalRecord);
    }

    #[Test]
    public function detectsAlreadyConfiguredAddressCaseInsensitive(): void
    {
        $instruction = DmarcRuaInstruction::build(
            'v=DMARC1; p=reject; rua=mailto:Reports@SendVery.com',
            'reports@sendvery.com',
        );

        self::assertTrue($instruction->alreadyConfigured);
    }

    #[Test]
    public function preservesCanonicalTagOrdering(): void
    {
        $instruction = DmarcRuaInstruction::build(
            'v=DMARC1; aspf=r; pct=100; p=reject',
            'reports@sendvery.com',
        );

        $position = static fn (string $needle): int|false => strpos($instruction->finalRecord, $needle);

        $vPos = $position('v=DMARC1');
        $pPos = $position('p=reject');
        $ruaPos = $position('rua=mailto:');
        $aspfPos = $position('aspf=r');

        self::assertIsInt($vPos);
        self::assertIsInt($pPos);
        self::assertIsInt($ruaPos);
        self::assertIsInt($aspfPos);
        self::assertLessThan($pPos, $vPos);
        self::assertLessThan($ruaPos, $pPos);
        self::assertLessThan($aspfPos, $ruaPos);
    }

    #[Test]
    public function trimsCurrentRecordWhitespace(): void
    {
        $instruction = DmarcRuaInstruction::build('   v=DMARC1; p=none   ', 'reports@sendvery.com');

        self::assertSame('v=DMARC1; p=none', $instruction->currentRecord);
    }
}
