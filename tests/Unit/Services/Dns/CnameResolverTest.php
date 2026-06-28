<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\CnameResolver;
use App\Services\Dns\FakeDns;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CnameResolverTest extends TestCase
{
    #[Test]
    public function resolvesTheCnameTargetAndStripsTheTrailingDot(): void
    {
        $dns = (new FakeDns())->withCname('_dmarc.acme.com', 'acme.com._dmarc.sendvery.test.');

        self::assertSame('acme.com._dmarc.sendvery.test', (new CnameResolver($dns))->resolve('_dmarc.acme.com'));
    }

    #[Test]
    public function returnsNullWhenNoCnameExists(): void
    {
        self::assertNull((new CnameResolver(new FakeDns()))->resolve('_dmarc.acme.com'));
    }

    #[Test]
    public function returnsNullWhenTheLookupThrows(): void
    {
        $dns = (new FakeDns())->throwOn('_dmarc.acme.com', 'CNAME');

        self::assertNull((new CnameResolver($dns))->resolve('_dmarc.acme.com'));
    }
}
