<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\ParsedDmarcRecord;
use PHPUnit\Framework\TestCase;

final class ParsedDmarcRecordTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $record = new ParsedDmarcRecord(
            sourceIp: '209.85.220.41',
            count: 150,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'example.com',
            dkimDomain: 'example.com',
            dkimSelector: 'google',
            spfDomain: 'example.com',
        );

        self::assertSame('209.85.220.41', $record->sourceIp);
        self::assertSame(150, $record->count);
        self::assertSame(Disposition::None, $record->disposition);
        self::assertSame(AuthResult::Pass, $record->dkimResult);
        self::assertSame(AuthResult::Pass, $record->spfResult);
        self::assertSame('example.com', $record->headerFrom);
        self::assertSame('example.com', $record->dkimDomain);
        self::assertSame('google', $record->dkimSelector);
        self::assertSame('example.com', $record->spfDomain);
    }

    public function testNullableFieldsDefaultToNull(): void
    {
        $record = new ParsedDmarcRecord(
            sourceIp: '1.2.3.4',
            count: 1,
            disposition: Disposition::Reject,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: 'test.com',
        );

        self::assertNull($record->dkimDomain);
        self::assertNull($record->dkimSelector);
        self::assertNull($record->spfDomain);
    }
}
