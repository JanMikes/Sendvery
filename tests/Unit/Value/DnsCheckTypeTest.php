<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\DnsCheckType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DnsCheckTypeTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        self::assertSame('spf', DnsCheckType::Spf->value);
        self::assertSame('dkim', DnsCheckType::Dkim->value);
        self::assertSame('dmarc', DnsCheckType::Dmarc->value);
        self::assertSame('mx', DnsCheckType::Mx->value);
        self::assertCount(4, DnsCheckType::cases());
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(DnsCheckType::Spf, DnsCheckType::from('spf'));
        self::assertSame(DnsCheckType::Dmarc, DnsCheckType::from('dmarc'));
    }
}
