<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\SystemReverseDnsResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\DnsMock;

/**
 * The production resolver, exercised against symfony/phpunit-bridge's DnsMock
 * so no packet ever leaves the process. Mocked hosts stay configured for the
 * whole test, which is what keeps DnsMock from delegating to the real resolver.
 */
final class SystemReverseDnsResolverTest extends TestCase
{
    private SystemReverseDnsResolver $resolver;

    protected function setUp(): void
    {
        DnsMock::register(SystemReverseDnsResolver::class);
        DnsMock::withMockedHosts([
            'mxb.seznam.cz.' => [['type' => 'A', 'ip' => '77.75.76.89']],
            'mxb-2-904.seznam.cz.' => [['type' => 'AAAA', 'ipv6' => '2a02:598:2::9']],
        ]);

        $this->resolver = new SystemReverseDnsResolver();
    }

    protected function tearDown(): void
    {
        DnsMock::withMockedHosts([]);
        putenv('RES_OPTIONS');
    }

    #[Test]
    public function returnsThePtrHostnameOfAnIpv4Sender(): void
    {
        self::assertSame('mxb.seznam.cz', $this->resolver->resolve('77.75.76.89'));
    }

    #[Test]
    public function returnsThePtrHostnameOfAnIpv6Sender(): void
    {
        self::assertSame('mxb-2-904.seznam.cz', $this->resolver->resolve('2a02:598:2::9'));
    }

    #[Test]
    public function reportsNothingWhenTheAddressHasNoReverseRecord(): void
    {
        self::assertNull(
            $this->resolver->resolve('198.51.100.5'),
            'The system resolver echoes the address back when there is no PTR; that is an absence, not a hostname.',
        );
    }

    #[Test]
    public function reportsNothingForAnUnusableAddress(): void
    {
        self::assertNull($this->resolver->resolve(''));
    }

    #[Test]
    public function boundsTheSystemResolverSoASlowNameserverCannotStallAWorker(): void
    {
        self::assertSame(
            'timeout:2 attempts:1',
            getenv('RES_OPTIONS'),
            'gethostbyaddr() takes no timeout argument, so the libc resolver has to be capped from the environment.',
        );
    }
}
