<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\FakeReverseDnsResolver;
use App\Services\Dns\ForwardConfirmedReverseDns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @see docs/16-sender-identity-and-digest-truthfulness.md (DEC-059 §3.3)
 *
 * The addresses and hostnames here are the real ones observed in production, so
 * a change that breaks a legitimate sender fails a test that names it.
 */
final class ForwardConfirmedReverseDnsTest extends TestCase
{
    private FakeReverseDnsResolver $dns;

    private ForwardConfirmedReverseDns $confirmation;

    protected function setUp(): void
    {
        $this->dns = new FakeReverseDnsResolver();
        $this->confirmation = new ForwardConfirmedReverseDns($this->dns);
    }

    #[Test]
    public function confirmsAHostThatResolvesBackToTheAddressThatClaimedIt(): void
    {
        $this->dns->withHostname('77.75.76.89', 'mxb.seznam.cz');

        self::assertTrue($this->confirmation->confirms('77.75.76.89', 'mxb.seznam.cz'));
    }

    #[Test]
    public function refusesAReverseRecordTheHostnameItselfDoesNotBackUp(): void
    {
        // Anyone renting an address can write its reverse zone; nobody but
        // Mimecast can publish Mimecast's addresses.
        $this->dns->withForgedHostname('203.0.113.240', 'eu-smtp-delivery-1.mimecast.com');
        $this->dns->withForwardAddresses('eu-smtp-delivery-1.mimecast.com', '195.130.217.1');

        self::assertFalse($this->confirmation->confirms('203.0.113.240', 'eu-smtp-delivery-1.mimecast.com'));
    }

    #[Test]
    public function confirmsARelayThatPublishesOnlyAnIpv6Address(): void
    {
        // A real Seznam relay: AAAA and no A record whatsoever. Checking only
        // the A records would strip a legitimate host of its identity.
        $this->dns->withForwardAddresses('mxb-2-904.seznam.cz', '2a02:598:64:8a00::1000:904');

        self::assertTrue($this->confirmation->confirms('2a02:598:64:8a00::1000:904', 'mxb-2-904.seznam.cz'));
    }

    #[Test]
    public function confirmsAnyNodeOfARotatingGatewayPool(): void
    {
        // INKY answers with an eight-address pool and sends from any of them;
        // reading only the first answer would reject seven eighths of it.
        $this->dns->withForwardAddresses(
            'ipw-outbound.inkyphishfence.com',
            '3.132.108.44',
            '18.208.14.99',
            '34.210.15.192',
            '35.171.24.11',
            '44.192.8.7',
            '52.6.90.201',
            '54.86.3.19',
            '107.20.44.62',
        );

        self::assertTrue($this->confirmation->confirms('3.132.108.44', 'ipw-outbound.inkyphishfence.com'));
        self::assertTrue($this->confirmation->confirms('34.210.15.192', 'ipw-outbound.inkyphishfence.com'));
        self::assertFalse($this->confirmation->confirms('203.0.113.7', 'ipw-outbound.inkyphishfence.com'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function equivalentSpellingProvider(): array
    {
        return [
            // Microsoft answers AAAA queries with IPv4-mapped addresses while
            // the DMARC report names the same machine in plain dotted quad.
            'IPv4-mapped IPv6 answer for an IPv4 sender' => ['40.93.13.100', '::ffff:40.93.13.100'],
            'IPv4-mapped IPv6 sender against an IPv4 answer' => ['::ffff:40.93.13.100', '40.93.13.100'],
            'IPv6 shorthand against its expanded form' => [
                '2a02:598:64:8a00::1000:904',
                '2a02:0598:0064:8a00:0000:0000:1000:0904',
            ],
            'IPv6 upper case against lower case' => [
                '2A02:598:64:8A00::1000:904',
                '2a02:598:64:8a00::1000:904',
            ],
            'surrounding whitespace' => ['77.75.76.89', ' 77.75.76.89 '],
        ];
    }

    #[Test]
    #[DataProvider('equivalentSpellingProvider')]
    public function treatsEverySpellingOfOneAddressAsTheSameAddress(string $sender, string $published): void
    {
        $this->dns->withForwardAddresses('mail.example.com', $published);

        self::assertTrue(
            $this->confirmation->confirms($sender, 'mail.example.com'),
            'Comparing addresses as text rejects legitimate hosts; they have to be compared as addresses.',
        );
    }

    #[Test]
    public function refusesAHostnameThatResolvesToNothingAtAll(): void
    {
        self::assertFalse($this->confirmation->confirms('203.0.113.10', 'nowhere.example'));
    }

    #[Test]
    public function refusesAnAddressItCannotEvenParse(): void
    {
        $this->dns->withForwardAddresses('mail.example.com', 'not-an-address');

        self::assertFalse($this->confirmation->confirms('also-not-an-address', 'mail.example.com'));
        self::assertFalse($this->confirmation->confirms('77.75.76.89', 'mail.example.com'));
    }

    #[Test]
    public function asksTheResolverOnceForOneConfirmation(): void
    {
        $this->dns->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $this->confirmation->confirms('77.75.76.89', 'mxb.seznam.cz');

        self::assertSame(
            1,
            $this->dns->forwardLookupCount(),
            'Confirmation runs on the ingest path, so it must cost one query, not one per candidate address.',
        );
    }
}
