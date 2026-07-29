<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\BlacklistChecker;
use App\Value\BlacklistListingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\DnsMock;

final class BlacklistCheckerTest extends TestCase
{
    private BlacklistChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new BlacklistChecker();

        // NEVER hand DnsMock an empty map. `DnsMock::dns_get_record()` falls
        // straight through to the real resolver when nothing is mocked, so an
        // empty map silently turns this suite into eight live DNSBL queries per
        // test — against exactly the rate-limited third-party lists whose
        // rejection responses caused the bug under test.
        $this->mockLists();
    }

    /**
     * Mock every configured blocklist for one IP.
     *
     * A list mapped to `[]` is present-but-silent, which is how DnsMock spells
     * NXDOMAIN — the real "not listed" answer. Anything absent from the map
     * returns `false` (a resolver failure), so the map is always complete and
     * `$answers` only overrides individual lists.
     *
     * @param array<string, string> $answers dnsbl => A-record return code
     */
    private function mockLists(string $ip = '1.2.3.4', array $answers = []): void
    {
        $reversed = implode('.', array_reverse(explode('.', $ip)));

        $hosts = [];
        foreach ((new BlacklistChecker())->getDnsblList() as $dnsbl) {
            $lookup = $reversed.'.'.$dnsbl;
            $hosts[$lookup] = isset($answers[$dnsbl])
                ? [['type' => 'A', 'ip' => $answers[$dnsbl]]]
                : [];
        }

        DnsMock::withMockedHosts($hosts);
    }

    private function verdictFor(string $dnsbl, string $returnCode): BlacklistListingStatus
    {
        $this->mockLists('1.2.3.4', [$dnsbl => $returnCode]);

        foreach ($this->checker->check('1.2.3.4')->listings as $listing) {
            if ($listing->dnsbl === $dnsbl) {
                return $listing->status;
            }
        }

        self::fail("No verdict recorded for {$dnsbl}");
    }

    #[Test]
    public function aDocumentedListingCodeIsReportedAsListed(): void
    {
        self::assertSame(
            BlacklistListingStatus::Listed,
            $this->verdictFor('zen.spamhaus.org', '127.0.0.2'),
        );
    }

    #[Test]
    public function anIpNoBlocklistKnowsAboutIsReportedAsNotListed(): void
    {
        $result = $this->checker->check('1.2.3.4');

        self::assertFalse($result->isListed());
        self::assertSame(0, $result->unavailableCount());
        self::assertSame($result->totalChecked(), $result->answeredCount());
        self::assertFalse($result->isInconclusive());
    }

    /**
     * The production incident, as a test.
     *
     * Querying Spamhaus through a shared recursive resolver makes it answer
     * `127.255.255.254` ("Error: open resolver") for EVERY address. Reading
     * that as a listing sent a daily Critical alert about an IP Spamhaus's own
     * checker reported clean.
     */
    #[Test]
    #[DataProvider('rejectionCodes')]
    public function aQueryRejectedByTheBlocklistIsNotAListing(string $returnCode): void
    {
        self::assertSame(
            BlacklistListingStatus::CheckFailed,
            $this->verdictFor('zen.spamhaus.org', $returnCode),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function rejectionCodes(): iterable
    {
        yield 'open resolver / typing error' => ['127.255.255.252'];
        yield 'no or invalid DQS key' => ['127.255.255.254'];
        yield 'volume limit exceeded' => ['127.255.255.255'];
    }

    #[Test]
    public function aRejectedQueryNeverMarksTheWholeIpAsListed(): void
    {
        $this->mockLists('1.2.3.4', [
            'zen.spamhaus.org' => '127.255.255.254',
            'cbl.abuseat.org' => '127.255.255.254',
        ]);

        $result = $this->checker->check('1.2.3.4');

        self::assertFalse($result->isListed(), 'Two refused queries must not produce a blacklist alert.');
        self::assertSame(2, $result->unavailableCount());
        self::assertSame(6, $result->answeredCount());
        self::assertSame([], $result->listedOn());
    }

    #[Test]
    public function aResolverFailureIsNotReadAsCleanEither(): void
    {
        // Absent from the map => DnsMock returns false => SERVFAIL/timeout.
        DnsMock::withMockedHosts(['unrelated.test' => [['type' => 'A', 'ip' => '10.0.0.1']]]);

        $result = $this->checker->check('1.2.3.4');

        self::assertFalse($result->isListed());
        self::assertTrue($result->isInconclusive(), 'No list answered, so there is no all-clear to give.');
        self::assertSame(0, $result->answeredCount());
    }

    #[Test]
    public function anAnswerOutsideLoopbackSpaceIsTreatedAsAFailedCheckNotAListing(): void
    {
        // A resolver that synthesises records for NXDOMAIN (ISP "search help")
        // would otherwise make every IP look listed on every list.
        self::assertSame(
            BlacklistListingStatus::CheckFailed,
            $this->verdictFor('dnsbl.sorbs.net', '93.184.216.34'),
        );
    }

    #[Test]
    public function theRfc5782NotListedTestAddressIsNotReadAsAListing(): void
    {
        // 127.0.0.1 is reserved by RFC 5782 for the "must NOT be listed" entry,
        // so no list uses it as a listing code — but a looped-back query lands
        // here, and claiming a listing off it would be a false positive.
        self::assertSame(
            BlacklistListingStatus::CheckFailed,
            $this->verdictFor('bl.spamcop.net', '127.0.0.1'),
        );
    }

    #[Test]
    public function theRejectionReasonExplainsThatWeCouldNotCheck(): void
    {
        $this->mockLists('1.2.3.4', ['zen.spamhaus.org' => '127.255.255.254']);

        $reason = $this->checker->check('1.2.3.4')->unavailable()[0]->reason;

        self::assertNotNull($reason);
        self::assertStringContainsString('rejected our query', $reason);
    }

    #[Test]
    public function aListingRecordsTheReturnCodeAsEvidence(): void
    {
        $this->mockLists('1.2.3.4', ['zen.spamhaus.org' => '127.0.0.11']);

        self::assertSame('127.0.0.11', $this->checker->check('1.2.3.4')->listedOn()[0]->returnCode);
    }

    #[Test]
    public function checkHostOrIpReturnsNullForUnresolvableHost(): void
    {
        DnsMock::withMockedHosts([
            'definitely-not-a-real-host.invalid' => [],
        ]);

        self::assertNull($this->checker->checkHostOrIp('definitely-not-a-real-host.invalid'));
    }

    #[Test]
    public function checkHostOrIpAcceptsRawIpv4(): void
    {
        $this->mockLists('127.0.0.2');

        $result = $this->checker->checkHostOrIp('127.0.0.2');

        self::assertNotNull($result);
        self::assertSame('127.0.0.2', $result->ipAddress);
    }

    #[Test]
    public function checkHostOrIpResolvesDomainToIp(): void
    {
        $this->mockLists('127.0.0.10');
        $hosts = ['example.test' => [['type' => 'A', 'ip' => '127.0.0.10']]];
        foreach ($this->checker->getDnsblList() as $dnsbl) {
            $hosts['10.0.0.127.'.$dnsbl] = [];
        }
        DnsMock::withMockedHosts($hosts);

        $result = $this->checker->checkHostOrIp('example.test');

        self::assertNotNull($result);
        self::assertSame('127.0.0.10', $result->ipAddress);
    }

    #[Test]
    public function checkHostOrIpRejectsIpv6(): void
    {
        self::assertNull($this->checker->checkHostOrIp('::1'));
    }

    #[Test]
    public function everyConfiguredBlocklistIsQueried(): void
    {
        $result = $this->checker->check('1.2.3.4');

        self::assertSame(
            $this->checker->getDnsblList(),
            array_map(static fn ($l): string => $l->dnsbl, $result->listings),
        );
    }
}
