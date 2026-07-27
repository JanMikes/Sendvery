<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\EnvelopeRewriteRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DEC-060 WP-B — the mark a relay leaves when it replaces the return path.
 */
final class EnvelopeRewriteRegistryTest extends TestCase
{
    private EnvelopeRewriteRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new EnvelopeRewriteRegistry();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rewrittenEnvelopes(): iterable
    {
        yield 'Sender Rewriting Scheme, one hop' => ['SRS0=abc1=de=example.com=alice@gateway.example'];
        yield 'Sender Rewriting Scheme, two hops' => ['srs1=xyz=gateway.example==abc1=de=example.com=alice@second.example'];
        yield 'the hyphen separator some implementations use' => ['SRS0-abc1=de=example.com=alice@gateway.example'];
        yield 'Bounce Address Tag Validation' => ['prvs=1234567890=alice@gateway.example'];
        yield 'the tagged BATV form' => ['btv1==abc==alice@gateway.example'];
        yield 'a per-recipient bounce mailbox' => ['bounces+8675309-abc@mail.gateway.example'];
        yield 'the hyphenated bounce form' => ['bounce-8675309@mail.gateway.example'];
        // RFC 7489 defines <spf><domain> as the checked domain, so on a
        // conforming report only the host survives.
        yield 'a dedicated SRS host, which is all a conforming report keeps' => ['srs.gateway.example'];
        yield 'a dedicated bounce host' => ['bounces.gateway.example'];
        yield 'a VERP host' => ['verp.gateway.example'];
        yield 'mixed case' => ['SRS.Gateway.Example'];
        yield 'a trailing dot from a resolver' => ['bounce.gateway.example.'];
    }

    #[Test]
    #[DataProvider('rewrittenEnvelopes')]
    public function recognisesAReturnPathThatWasReplacedInTransit(string $envelopeSender): void
    {
        self::assertTrue($this->registry->looksRewritten($envelopeSender));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ordinaryEnvelopes(): iterable
    {
        yield 'nothing at all' => [''];
        yield 'whitespace' => ['   '];
        yield 'a plain domain' => ['example.com'];
        yield 'a plain address' => ['alice@example.com'];
        yield 'an email service provider' => ['sendgrid.net'];
        yield 'a regional provider host' => ['us-east-1.amazonses.com'];
        yield 'a marketing platform' => ['mail123.suw11.mcsv.net'];
        yield 'the label buried mid-name, where it says nothing' => ['mail.bounce.example.com'];
        yield 'a longer word starting with the same letters' => ['bouncer.example.com'];
        yield 'a bare label with no domain under it' => ['bounces'];
        yield 'the marker somewhere other than the start of the local part' => ['alice+srs0=abc@example.com'];
    }

    #[Test]
    #[DataProvider('ordinaryEnvelopes')]
    public function doesNotInventARewriteWhereThereIsNone(string $envelopeSender): void
    {
        self::assertFalse(
            $this->registry->looksRewritten($envelopeSender),
            'Every match here softens a verdict about a sender, so a loose match is a sender that gets away with more than it earned.',
        );
    }
}
