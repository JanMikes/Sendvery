<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\FakeReverseDnsResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeReverseDnsResolverTest extends TestCase
{
    #[Test]
    public function answersOnlyWithHostnamesATestScripted(): void
    {
        $resolver = (new FakeReverseDnsResolver())->withHostname('77.75.76.89', 'mxb.seznam.cz');

        self::assertSame('mxb.seznam.cz', $resolver->resolve('77.75.76.89'));
        self::assertNull($resolver->resolve('198.51.100.5'), 'Anything unscripted must look like an address with no PTR, never a real lookup.');
    }

    #[Test]
    public function countsLookupsSoTestsCanProveTheCacheIsWorking(): void
    {
        $resolver = (new FakeReverseDnsResolver())->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $resolver->resolve('77.75.76.89');
        $resolver->resolve('198.51.100.5');

        self::assertSame(2, $resolver->lookupCount());
    }

    #[Test]
    public function forgetsEverythingWhenReset(): void
    {
        $resolver = (new FakeReverseDnsResolver())->withHostname('77.75.76.89', 'mxb.seznam.cz');
        $resolver->resolve('77.75.76.89');

        $resolver->reset();

        self::assertNull($resolver->resolve('77.75.76.89'));
        self::assertSame(1, $resolver->lookupCount(), 'The counter restarts with the scripted hosts.');
    }
}
