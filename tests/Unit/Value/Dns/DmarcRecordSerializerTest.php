<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\Dns\DmarcRecordSerializer;
use App\Value\Dns\DmarcRuaInstruction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DmarcRecordSerializerTest extends TestCase
{
    #[Test]
    public function serializesAFullRejectPolicyInCanonicalTagOrder(): void
    {
        $record = (new DmarcRecordSerializer())->serialize(
            p: DmarcPolicy::Reject,
            sp: DmarcPolicy::Reject,
            pct: 100,
            ruaAddresses: ['reports@sendvery.test'],
            adkim: DmarcAlignment::Relaxed,
            aspf: DmarcAlignment::Relaxed,
            fo: '1',
        );

        self::assertSame(
            'v=DMARC1; p=reject; sp=reject; rua=mailto:reports@sendvery.test; adkim=r; aspf=r; fo=1',
            $record,
        );
    }

    #[Test]
    public function omitsPctWhen100AndSpWhenNull(): void
    {
        $serializer = new DmarcRecordSerializer();

        $monitoring = $serializer->serialize(DmarcPolicy::None, null, 100, ['reports@sendvery.test']);
        self::assertSame('v=DMARC1; p=none; rua=mailto:reports@sendvery.test', $monitoring);
        self::assertStringNotContainsString('pct=', $monitoring);
        self::assertStringNotContainsString('sp=', $monitoring);

        // The inverse branches: pct is emitted when not 100, sp when present.
        $partial = $serializer->serialize(DmarcPolicy::Quarantine, DmarcPolicy::Quarantine, 50, ['reports@sendvery.test']);
        self::assertStringContainsString('sp=quarantine', $partial);
        self::assertStringContainsString('pct=50', $partial);
    }

    #[Test]
    public function joinsMultipleRuaWithMailtoAndNoSpaces(): void
    {
        $record = (new DmarcRecordSerializer())->serialize(
            DmarcPolicy::None,
            null,
            100,
            ['reports@sendvery.test', 'extra@sendvery.test'],
        );

        self::assertStringContainsString('rua=mailto:reports@sendvery.test,mailto:extra@sendvery.test', $record);
        self::assertStringNotContainsString(', ', $record);
    }

    #[Test]
    public function emitsFoWhenSupplied(): void
    {
        $with = (new DmarcRecordSerializer())->serialize(DmarcPolicy::None, null, 100, ['reports@sendvery.test'], fo: '1');
        self::assertStringContainsString('fo=1', $with);

        $without = (new DmarcRecordSerializer())->serialize(DmarcPolicy::None, null, 100, ['reports@sendvery.test']);
        self::assertStringNotContainsString('fo=', $without);
    }

    #[Test]
    public function dmarcRuaInstructionOutputIsByteIdenticalAfterDelegating(): void
    {
        $instruction = DmarcRuaInstruction::build('v=DMARC1; aspf=r; pct=100; p=reject', 'reports@sendvery.com');

        $expected = (new DmarcRecordSerializer())->rebuildRecord([
            'v' => 'DMARC1',
            'aspf' => 'r',
            'pct' => '100',
            'p' => 'reject',
            'rua' => 'mailto:reports@sendvery.com',
        ]);

        self::assertSame('v=DMARC1; p=reject; rua=mailto:reports@sendvery.com; aspf=r; pct=100', $expected);
        self::assertSame($expected, $instruction->finalRecord);
    }

    #[Test]
    public function preservesUnknownCustomerTagsThroughTheArrayPath(): void
    {
        // `np` (non-existent-subdomain policy) is not in the canonical list, so
        // it must be appended verbatim after the known tags, never dropped.
        $instruction = DmarcRuaInstruction::build('v=DMARC1; p=none; np=quarantine', 'reports@sendvery.com');

        self::assertSame(
            'v=DMARC1; p=none; rua=mailto:reports@sendvery.com; np=quarantine',
            $instruction->finalRecord,
        );
    }
}
