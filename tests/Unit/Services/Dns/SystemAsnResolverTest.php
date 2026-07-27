<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\SystemAsnResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\DnsMock;

/**
 * The production AS resolver, exercised against symfony/phpunit-bridge's DnsMock
 * so no packet ever leaves the process.
 *
 * The mocked names are Team Cymru's real zone layout: the address written
 * backwards under `origin.asn.cymru.com` (octets for IPv4, nibbles for IPv6),
 * and the number under `AS<n>.asn.cymru.com`.
 *
 * @see docs/18-forwarder-trust-verification-plan.md §4 (DEC-060 WP-D)
 */
final class SystemAsnResolverTest extends TestCase
{
    private SystemAsnResolver $resolver;

    protected function setUp(): void
    {
        DnsMock::register(SystemAsnResolver::class);
        DnsMock::withMockedHosts([
            '177.19.212.52.origin.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '16509 | 52.212.0.0/15 | IE | arin | 2015-09-18'],
            ],
            'AS16509.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '16509 | US | arin | 2000-05-04 | AMAZON-02, US'],
            ],
            // A prefix announced by more than one AS.
            '10.100.51.198.origin.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '701 1239 | 198.51.100.0/24 | US | arin | 1997-03-01'],
            ],
            'AS701.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '701 | US | arin | 1990-08-03 | UUNET, US'],
            ],
            // The number resolves, the name lookup does not.
            '89.76.75.77.origin.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '43037 | 77.75.76.0/22 | CZ | ripencc | 2007-01-16'],
            ],
            // IPv6, nibble-reversed under the origin6 zone.
            '9.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.2.0.0.0.8.9.5.0.2.0.a.2.origin6.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '43037 | 2a02:598::/32 | CZ | ripencc | 2007-01-16'],
            ],
            // An answer whose first field is not a number at all.
            '1.100.51.198.origin.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => 'no origin found'],
            ],
            // A resolver that answers with nothing usable in it.
            '3.100.51.198.origin.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '   '],
            ],
            // An answer with an empty name field.
            '2.100.51.198.origin.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '64500 | 198.51.100.0/24 | US | arin | 2001-01-01'],
            ],
            'AS64500.asn.cymru.com' => [
                ['type' => 'TXT', 'txt' => '64500 | US | arin | 2001-01-01 | '],
            ],
        ]);

        $this->resolver = new SystemAsnResolver();
    }

    protected function tearDown(): void
    {
        DnsMock::withMockedHosts([]);
    }

    #[Test]
    public function namesTheNetworkAnnouncingAnIpv4Address(): void
    {
        $registration = $this->resolver->resolve('52.212.19.177');

        self::assertNotNull($registration);
        self::assertSame(16509, $registration->number);
        self::assertSame('AMAZON-02, US', $registration->organization);
        self::assertSame('AS16509 AMAZON-02, US', $registration->label());
    }

    #[Test]
    public function namesTheNetworkAnnouncingAnIpv6Address(): void
    {
        self::assertSame(
            43037,
            $this->resolver->resolve('2a02:598:2::9')?->number,
            'A relay pool can be IPv6-only, and those are exactly the addresses with the least else to identify them.',
        );
    }

    #[Test]
    public function takesTheFirstOriginOfAPrefixSeveralNetworksAnnounce(): void
    {
        self::assertSame(701, $this->resolver->resolve('198.51.100.10')?->number);
    }

    #[Test]
    public function keepsTheNumberWhenOnlyTheNameLookupFails(): void
    {
        $registration = $this->resolver->resolve('77.75.76.89');

        self::assertNotNull($registration);
        self::assertSame(43037, $registration->number);
        self::assertNull(
            $registration->organization,
            'The number is the part that came from BGP; losing it because a name lookup went unanswered would throw away the evidence to keep the label.',
        );
        self::assertSame('AS43037', $registration->label());
    }

    #[Test]
    public function treatsAnEmptyRegistryNameAsNoNameAtAll(): void
    {
        self::assertNull($this->resolver->resolve('198.51.100.2')?->organization);
    }

    #[Test]
    public function reportsNothingForAnAddressNoNetworkAnnounces(): void
    {
        self::assertNull($this->resolver->resolve('203.0.113.9'));
    }

    #[Test]
    public function reportsNothingWhenTheAnswerCarriesNoNumber(): void
    {
        self::assertNull($this->resolver->resolve('198.51.100.1'));
    }

    #[Test]
    public function reportsNothingWhenTheOnlyAnswerIsBlank(): void
    {
        self::assertNull(
            $this->resolver->resolve('198.51.100.3'),
            'An empty TXT record is a resolver answering without saying anything, which is the same as not answering.',
        );
    }

    #[Test]
    public function reportsNothingForAnUnusableAddress(): void
    {
        self::assertNull($this->resolver->resolve('not an address'));
        self::assertNull($this->resolver->resolve(''));
    }
}
