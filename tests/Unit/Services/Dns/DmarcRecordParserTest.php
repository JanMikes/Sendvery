<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\DmarcRecordParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DmarcRecordParserTest extends TestCase
{
    #[Test]
    public function parseReturnsNullForNull(): void
    {
        self::assertNull((new DmarcRecordParser())->parse(null));
    }

    #[Test]
    public function parseReturnsNullForEmpty(): void
    {
        self::assertNull((new DmarcRecordParser())->parse(''));
        self::assertNull((new DmarcRecordParser())->parse('   '));
    }

    #[Test]
    public function parseReturnsNullForNonDmarcRecord(): void
    {
        // Anything that isn't `v=DMARC1...` is not a DMARC record — we treat
        // it the same as missing rather than try to coerce a half-valid
        // string into a partial DTO.
        self::assertNull((new DmarcRecordParser())->parse('random string'));
        self::assertNull((new DmarcRecordParser())->parse('v=spf1 ~all'));
    }

    #[Test]
    public function parseExtractsAllFieldsFromCanonicalRecord(): void
    {
        $parsed = (new DmarcRecordParser())->parse(
            'v=DMARC1; p=none; rua=mailto:reports@sendvery.com; pct=100',
        );

        self::assertNotNull($parsed);
        self::assertSame('none', $parsed->policy);
        self::assertSame(['reports@sendvery.com'], $parsed->ruaAddresses);
        self::assertSame([], $parsed->rufAddresses);
        self::assertSame(100, $parsed->pct);
    }

    #[Test]
    public function parseHandlesMultipleRuaAddresses(): void
    {
        $parsed = (new DmarcRecordParser())->parse(
            'v=DMARC1; p=none; rua=mailto:a@x.com,mailto:b@y.com',
        );

        self::assertNotNull($parsed);
        self::assertSame(['a@x.com', 'b@y.com'], $parsed->ruaAddresses);
    }

    #[Test]
    public function parseHandlesWhitespaceAroundSemicolons(): void
    {
        $parsed = (new DmarcRecordParser())->parse(
            'v=DMARC1;  p = quarantine ; rua = mailto:dmarc@example.com ;  pct = 50  ',
        );

        self::assertNotNull($parsed);
        self::assertSame('quarantine', $parsed->policy);
        self::assertSame(['dmarc@example.com'], $parsed->ruaAddresses);
        self::assertSame(50, $parsed->pct);
    }

    #[Test]
    public function parseSkipsMalformedTagsWithoutEqualSign(): void
    {
        // A semicolon-separated chunk with no `=` is invalid syntax; we
        // skip it rather than throwing so a single bad tag doesn't poison
        // the rest of the record.
        $parsed = (new DmarcRecordParser())->parse(
            'v=DMARC1; bogus_tag_no_equals; p=reject; rua=mailto:x@y.com',
        );

        self::assertNotNull($parsed);
        self::assertSame('reject', $parsed->policy);
        self::assertSame(['x@y.com'], $parsed->ruaAddresses);
    }

    #[Test]
    public function parseReturnsEmptyArrayWhenNoRuaTag(): void
    {
        $parsed = (new DmarcRecordParser())->parse('v=DMARC1; p=none');

        self::assertNotNull($parsed);
        self::assertSame('none', $parsed->policy);
        self::assertSame([], $parsed->ruaAddresses);
        self::assertSame([], $parsed->rufAddresses);
        self::assertNull($parsed->pct);
    }
}
