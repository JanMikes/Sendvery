<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\SystemDnswlResolver;
use App\Value\Dns\DnswlListing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\DnsMock;

/**
 * The production whitelist resolver, exercised against symfony/phpunit-bridge's
 * DnsMock so no packet ever leaves the process.
 *
 * Mocked names follow the DNSxL convention every list shares: the address
 * written backwards under the list's zone, answered as `127.0.<category>.<trust>`.
 *
 * @see docs/18-forwarder-trust-verification-plan.md §4 (DEC-060 WP-F)
 */
final class SystemDnswlResolverTest extends TestCase
{
    private SystemDnswlResolver $resolver;

    protected function setUp(): void
    {
        DnsMock::register(SystemDnswlResolver::class);
        DnsMock::withMockedHosts([
            // Category 2 (email service provider), trust "high".
            '60.13.93.40.list.dnswl.org' => [['type' => 'A', 'ip' => '127.0.2.3']],
            // Listed, but dnswl says it has no confidence in the entry.
            '9.113.0.203.list.dnswl.org' => [['type' => 'A', 'ip' => '127.0.2.0']],
            // The rate-limit / refusal answer public resolvers get.
            '10.113.0.203.list.dnswl.org' => [['type' => 'A', 'ip' => '127.0.0.255']],
            // Something outside 127.0.0.0/8 entirely.
            '11.113.0.203.list.dnswl.org' => [['type' => 'A', 'ip' => '10.0.0.1']],
            // An answer with no address in it at all, then a real one.
            '12.113.0.203.list.dnswl.org' => [
                ['type' => 'A'],
                ['type' => 'A', 'ip' => '127.0.2.3'],
            ],
            // IPv6, nibble-reversed.
            '9.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.2.0.0.0.8.9.5.0.2.0.a.2.list.dnswl.org' => [
                ['type' => 'A', 'ip' => '127.0.2.2'],
            ],
        ]);

        $this->resolver = new SystemDnswlResolver();
    }

    protected function tearDown(): void
    {
        DnsMock::withMockedHosts([]);
    }

    #[Test]
    public function readsTheCategoryAndTrustLevelOfAListedHost(): void
    {
        $listing = $this->resolver->lookup('40.93.13.60');

        self::assertNotNull($listing);
        self::assertSame(DnswlListing::TRUST_HIGH, $listing->trustLevel);
        self::assertSame(2, $listing->category);
        self::assertTrue($listing->isTrusted());
    }

    #[Test]
    public function readsAListedIpv6Host(): void
    {
        self::assertSame(DnswlListing::TRUST_MEDIUM, $this->resolver->lookup('2a02:598:2::9')?->trustLevel);
    }

    #[Test]
    public function doesNotVouchForAnEntryTheListItselfHasNoConfidenceIn(): void
    {
        $listing = $this->resolver->lookup('203.0.113.9');

        self::assertNotNull($listing);
        self::assertSame(DnswlListing::TRUST_NONE, $listing->trustLevel);
        self::assertFalse(
            $listing->isTrusted(),
            'dnswl\'s "none" level is dnswl saying it does not stand behind the entry; reading it as an endorsement would invert its meaning.',
        );
    }

    #[Test]
    public function readsARefusedQueryAsNotListedRatherThanAsAnEndorsement(): void
    {
        self::assertNull(
            $this->resolver->lookup('203.0.113.10'),
            'dnswl answers 127.0.0.255 to resolvers over its limits, which a self-hosted install can reach without noticing. A rate limit must never soften a verdict.',
        );
    }

    #[Test]
    public function ignoresAnAnswerFromOutsideTheLoopbackRange(): void
    {
        self::assertNull($this->resolver->lookup('203.0.113.11'));
    }

    #[Test]
    public function keepsReadingPastAnAnswerThatCarriesNoAddress(): void
    {
        self::assertSame(
            DnswlListing::TRUST_HIGH,
            $this->resolver->lookup('203.0.113.12')?->trustLevel,
            'One unreadable record in a reply must not discard the readable ones beside it.',
        );
    }

    #[Test]
    public function reportsNothingForAnAddressThatIsNotListed(): void
    {
        self::assertNull($this->resolver->lookup('198.51.100.5'));
    }

    #[Test]
    public function reportsNothingForAnUnusableAddress(): void
    {
        self::assertNull($this->resolver->lookup('not an address'));
        self::assertNull($this->resolver->lookup(''));
    }
}
