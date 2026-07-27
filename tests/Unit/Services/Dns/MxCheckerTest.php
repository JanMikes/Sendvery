<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\FakeDns;
use App\Services\Dns\FakeSmtpProbe;
use App\Services\Dns\MxChecker;
use App\Value\Dns\IssueSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "Does this domain have working MX records?" — a verdict that gates a red
 * finding on the domain's setup surface, so every false negative here becomes a
 * user chasing a problem that does not exist.
 */
final class MxCheckerTest extends TestCase
{
    #[Test]
    public function mxHostsResolvingOverIpv4Pass(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'mx1.example.net.', 10)
            ->withA('mx1.example.net', '203.0.113.10');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertTrue($result->isPassing());
        self::assertSame('203.0.113.10', $result->records[0]->ip);
    }

    #[Test]
    public function anIpv6OnlyMailHostPassesInsteadOfBeingReportedAsBroken(): void
    {
        // A mail host that publishes only AAAA is perfectly deliverable. The
        // checker used to query A records exclusively, so it found no address,
        // and MxCheckResult::isPassing() — which requires at least one record to
        // resolve — failed the domain outright.
        $dns = (new FakeDns())
            ->withMx('example.com', 'mx1.example.net.', 10)
            ->withAaaa('mx1.example.net', '2001:db8::25');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertTrue($result->isPassing(), 'An IPv6-only mail host must not be reported as a broken MX.');
        self::assertSame('2001:db8::25', $result->records[0]->ip);
    }

    #[Test]
    public function ipv4IsPreferredWhenAHostPublishesBothFamilies(): void
    {
        // Most senders still reach the host over IPv4, so that is the address we
        // report and probe.
        $dns = (new FakeDns())
            ->withMx('example.com', 'mx1.example.net.', 10)
            ->withA('mx1.example.net', '203.0.113.10')
            ->withAaaa('mx1.example.net', '2001:db8::25');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertSame('203.0.113.10', $result->records[0]->ip);
    }

    #[Test]
    public function aHostWithNoAddressAtAllDoesNotPass(): void
    {
        $dns = (new FakeDns())->withMx('example.com', 'mx1.example.net.', 10);

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertFalse($result->isPassing());
        self::assertNull($result->records[0]->ip);
    }

    #[Test]
    public function aDomainWithNoMxRecordsCannotReceiveMailAndSaysSo(): void
    {
        $result = (new MxChecker(new FakeDns(), new FakeSmtpProbe()))->check('example.com');

        self::assertFalse($result->isPassing());
        self::assertFalse($result->hasRecords());
        self::assertSame(IssueSeverity::Warning, $result->issues[0]->severity);
        self::assertStringContainsString('No MX records found', $result->issues[0]->message);
    }

    #[Test]
    public function anAnswerWeCannotParseIsTreatedAsNoMxRatherThanAsAMalformedOne(): void
    {
        // Resolvers occasionally return answers that do not look like the type
        // they were asked for. Skipping them keeps the verdict honest — we saw
        // nothing usable — instead of surfacing a half-parsed record.
        $dns = (new FakeDns())->withMalformedRecord('example.com', 'MX', 'this is not an mx record');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertFalse($result->hasRecords());
        self::assertSame(IssueSeverity::Warning, $result->issues[0]->severity);
    }

    #[Test]
    public function recordsComeBackSortedByPriority(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'backup.example.net.', 20)
            ->withMx('example.com', 'primary.example.net.', 10)
            ->withA('primary.example.net', '203.0.113.10')
            ->withA('backup.example.net', '203.0.113.11');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertSame('primary.example.net', $result->records[0]->host);
        self::assertSame('backup.example.net', $result->records[1]->host);
    }

    #[Test]
    public function anMxLookupFailureIsReportedAsAQueryProblemNotAsAMissingRecord(): void
    {
        $dns = (new FakeDns())->throwOn('example.com', 'MX');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertFalse($result->hasRecords());
        self::assertSame(IssueSeverity::Critical, $result->issues[0]->severity);
        self::assertStringContainsString('Failed to query MX records', $result->issues[0]->message);
    }

    #[Test]
    public function anAddressLookupFailureLeavesTheRecordAddressless(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'mx1.example.net.', 10)
            ->throwOn('mx1.example.net', 'A')
            ->throwOn('mx1.example.net', 'AAAA');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertNull($result->records[0]->ip);
        self::assertFalse($result->isPassing());
    }

    #[Test]
    public function unreachableMxHostsBlameOurOwnEgressRatherThanTheDomain(): void
    {
        // Outbound port 25 is blocked on most cloud hosts, including ours. We
        // genuinely cannot tell "their server is down" from "we are firewalled",
        // so the finding stays informational.
        $dns = (new FakeDns())
            ->withMx('example.com', 'mx1.example.net.', 10)
            ->withA('mx1.example.net', '203.0.113.10');

        $result = (new MxChecker($dns, new FakeSmtpProbe()))->check('example.com');

        self::assertTrue($result->isPassing(), 'Reachability must not gate the DNS verdict.');
        self::assertSame(IssueSeverity::Info, $result->issues[0]->severity);
    }

    #[Test]
    public function aReachableHostWithoutStartTlsIsFlaggedAsPlaintextRisk(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'mx1.example.net.', 10)
            ->withA('mx1.example.net', '203.0.113.10');
        $probe = (new FakeSmtpProbe())->withReachable('203.0.113.10', tlsSupported: false);

        $result = (new MxChecker($dns, $probe))->check('example.com');

        self::assertTrue($result->isPassing());
        self::assertSame(IssueSeverity::Warning, $result->issues[0]->severity);
        self::assertStringContainsString('STARTTLS', $result->issues[0]->message);
    }

    #[Test]
    public function aReachableTlsCapableHostProducesNoFindingsAtAll(): void
    {
        $dns = (new FakeDns())
            ->withMx('example.com', 'mx1.example.net.', 10)
            ->withA('mx1.example.net', '203.0.113.10');
        $probe = (new FakeSmtpProbe())->withReachable('203.0.113.10');

        $result = (new MxChecker($dns, $probe))->check('example.com');

        self::assertTrue($result->isPassing());
        self::assertSame([], $result->issues);
    }
}
