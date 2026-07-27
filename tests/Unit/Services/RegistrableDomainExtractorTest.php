<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\RegistrableDomainExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegistrableDomainExtractorTest extends TestCase
{
    private RegistrableDomainExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new RegistrableDomainExtractor();
    }

    /**
     * Every hostname here is a real PTR record from the production incident
     * that prompted DEC-059.
     *
     * @return array<string, array{string, string}>
     */
    public static function productionHostnameProvider(): array
    {
        return [
            'seznam relay, IPv4' => ['mxb.seznam.cz', 'seznam.cz'],
            'seznam relay, rotating IPv6 pool' => ['mxb-2-904.seznam.cz', 'seznam.cz'],
            'security gateway, EU region' => ['eu.cloud-sec-av.com', 'cloud-sec-av.com'],
            'security gateway, CA region' => ['ca.cloud-sec-av.com', 'cloud-sec-av.com'],
            'security gateway, US region' => ['us.cloud-sec-av.com', 'cloud-sec-av.com'],
            'inky phish fence' => ['ipw-outbound.inkyphishfence.com', 'inkyphishfence.com'],
            'microsoft 365 forwarder' => ['mail-dm2pr04cu00304.outbound.protection.outlook.com', 'outlook.com'],
        ];
    }

    #[Test]
    #[DataProvider('productionHostnameProvider')]
    public function reducesARealSenderHostnameToTheDomainSomebodyRegistered(string $hostname, string $expected): void
    {
        self::assertSame($expected, $this->extractor->extract($hostname));
    }

    #[Test]
    public function collapsesAnEntireRotatingRelayPoolOntoOneIdentity(): void
    {
        $pool = [
            'mxb.seznam.cz',
            'mxb-1-a01.seznam.cz',
            'mxb-2-904.seznam.cz',
            'mxb-3-f13.seznam.cz',
        ];

        $identities = array_unique(array_map($this->extractor->extract(...), $pool));

        self::assertSame(
            ['seznam.cz'],
            array_values($identities),
            'Fifteen addresses of one relay must be one sender, with no curated mapping required.',
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function multiLabelSuffixProvider(): array
    {
        return [
            'uk commercial' => ['mail.example.co.uk', 'example.co.uk'],
            'uk commercial at the apex' => ['example.co.uk', 'example.co.uk'],
            'uk academic' => ['smtp.relay.dept.example.ac.uk', 'example.ac.uk'],
            'australian commercial' => ['mx1.example.com.au', 'example.com.au'],
            'japanese commercial' => ['mail.example.co.jp', 'example.co.jp'],
            'brazilian commercial' => ['out.example.com.br', 'example.com.br'],
            'south african commercial' => ['gw.example.co.za', 'example.co.za'],
        ];
    }

    #[Test]
    #[DataProvider('multiLabelSuffixProvider')]
    public function keepsTheRegisteredLabelWhenThePublicSuffixHasSeveralParts(string $hostname, string $expected): void
    {
        self::assertSame($expected, $this->extractor->extract($hostname));
    }

    #[Test]
    public function acceptsATrailingDotFromTheResolver(): void
    {
        self::assertSame('seznam.cz', $this->extractor->extract('mxb.seznam.cz.'));
    }

    #[Test]
    public function isCaseInsensitive(): void
    {
        self::assertSame('outlook.com', $this->extractor->extract('MAIL.OUTBOUND.PROTECTION.OUTLOOK.COM'));
    }

    #[Test]
    public function ignoresSurroundingWhitespace(): void
    {
        self::assertSame('seznam.cz', $this->extractor->extract("  mxb.seznam.cz \n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableInputProvider(): array
    {
        return [
            'empty' => [''],
            'only dots' => ['...'],
            'single label' => ['localhost'],
            'bare tld' => ['com'],
            'ipv4 literal echoed back by the resolver' => ['77.75.76.89'],
            'ipv6 literal' => ['2a02:598:1::1'],
            'public suffix with no registered name' => ['co.uk'],
            'empty label in the middle' => ['mail..seznam.cz'],
            'hostile characters' => ['mail.<script>.com'],
            'leading dash' => ['-mail.seznam.cz'],
            'whitespace inside' => ['mail seznam.cz'],
        ];
    }

    #[Test]
    #[DataProvider('unusableInputProvider')]
    public function refusesAnythingThatIsNotAUsableHostname(string $input): void
    {
        self::assertNull(
            $this->extractor->extract($input),
            'The result becomes a grouping key and a display label, so unusable input must produce nothing at all.',
        );
    }
}
