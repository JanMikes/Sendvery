<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value\Dns;

use App\Value\Dns\MxRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MxRecordTest extends TestCase
{
    #[Test]
    public function canBeConstructed(): void
    {
        $record = new MxRecord('mail.example.com', 10, '1.2.3.4', true, true);

        self::assertSame('mail.example.com', $record->host);
        self::assertSame(10, $record->priority);
        self::assertSame('1.2.3.4', $record->ip);
        self::assertTrue($record->reachable);
        self::assertTrue($record->tlsSupported);
    }

    #[Test]
    public function nullableFieldsCanBeNull(): void
    {
        $record = new MxRecord('mail.example.com', 10, null, false, null);

        self::assertNull($record->ip);
        self::assertNull($record->tlsSupported);
    }
}
