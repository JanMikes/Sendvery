<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\FakeAsnResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeAsnResolverTest extends TestCase
{
    #[Test]
    public function answersOnlyWithNetworksATestScripted(): void
    {
        $resolver = new FakeAsnResolver()->withAsn('52.212.19.177', 16509, 'AMAZON-02');
        $registration = $resolver->resolve('52.212.19.177');

        self::assertNotNull($registration);
        self::assertSame(16509, $registration->number);
        self::assertSame('AMAZON-02', $registration->organization);
        self::assertNull(
            $resolver->resolve('198.51.100.5'),
            'Anything unscripted must look like an address nobody announces, never a real lookup.',
        );
    }

    #[Test]
    public function scriptsANetworkWhoseNameIsNotKnown(): void
    {
        $registration = new FakeAsnResolver()->withAsn('198.51.100.9', 64500)->resolve('198.51.100.9');

        self::assertNotNull($registration);
        self::assertSame(64500, $registration->number);
        self::assertNull($registration->organization);
    }

    #[Test]
    public function countsItsLookupsSoCachingCanBeProved(): void
    {
        $resolver = new FakeAsnResolver()->withAsn('52.212.19.177', 16509);

        $resolver->resolve('52.212.19.177');
        $resolver->resolve('198.51.100.5');

        self::assertSame(2, $resolver->lookupCount(), 'An address nobody announces still cost a lookup.');
    }

    #[Test]
    public function forgetsEverythingWhenReset(): void
    {
        $resolver = new FakeAsnResolver()->withAsn('52.212.19.177', 16509);
        $resolver->resolve('52.212.19.177');

        $resolver->reset();

        self::assertNull($resolver->resolve('52.212.19.177'));
        self::assertSame(1, $resolver->lookupCount(), 'The counter restarts with the script; the call just made is the first of the new run.');
    }
}
