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
 *
 * Each host is registered twice, with and without the trailing dot: reverse
 * answers arrive fully qualified, and the forward lookup is made against the
 * name after the dot has been stripped.
 */
final class SystemReverseDnsResolverTest extends TestCase
{
    private SystemReverseDnsResolver $resolver;

    protected function setUp(): void
    {
        DnsMock::register(SystemReverseDnsResolver::class);
        DnsMock::withMockedHosts([
            'mxb.seznam.cz.' => [['type' => 'A', 'ip' => '77.75.76.89']],
            'mxb.seznam.cz' => [['type' => 'A', 'ip' => '77.75.76.89']],
            'mxb-2-904.seznam.cz.' => [['type' => 'AAAA', 'ipv6' => '2a02:598:2::9']],
            // A real Seznam relay publishes AAAA and no A at all.
            'mxb-2-904.seznam.cz' => [['type' => 'AAAA', 'ipv6' => '2a02:598:2::9']],
            // A gateway pool answers with every node it may send from, and
            // resolvers hand back the CNAME that led there alongside them.
            'ipw-outbound.inkyphishfence.com' => [
                ['type' => 'CNAME', 'target' => 'pool.inkyphishfence.com'],
                ['type' => 'A', 'ip' => '3.132.108.44'],
                ['type' => 'A', 'ip' => '34.210.15.192'],
                ['type' => 'AAAA', 'ipv6' => '2600:1f18::44'],
                ['type' => 'TXT', 'txt' => 'v=spf1 -all'],
            ],
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
    public function readsTheAddressesAHostnamePublishesForItself(): void
    {
        self::assertSame(['77.75.76.89'], $this->resolver->forwardAddresses('mxb.seznam.cz'));
    }

    #[Test]
    public function readsTheAddressesOfAHostThatPublishesNoIpv4AtAll(): void
    {
        self::assertSame(
            ['2a02:598:2::9'],
            $this->resolver->forwardAddresses('mxb-2-904.seznam.cz'),
            'An A-only lookup would report a legitimate IPv6-only relay as unresolvable.',
        );
    }

    #[Test]
    public function readsEveryAddressOfARotatingPoolAndIgnoresTheOtherRecordTypes(): void
    {
        self::assertSame(
            ['3.132.108.44', '34.210.15.192', '2600:1f18::44'],
            $this->resolver->forwardAddresses('ipw-outbound.inkyphishfence.com'),
            'The sending node can be any member of the pool, so the whole RRset has to come back — and nothing that is not an address.',
        );
    }

    #[Test]
    public function reportsNoAddressesForAHostnameThatDoesNotResolve(): void
    {
        self::assertSame([], $this->resolver->forwardAddresses('nowhere.example'));
    }

    #[Test]
    public function reportsNoAddressesForAnEmptyHostname(): void
    {
        self::assertSame([], $this->resolver->forwardAddresses('   '));
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
