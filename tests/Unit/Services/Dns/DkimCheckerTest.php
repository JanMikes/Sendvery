<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\DkimChecker;
use App\Services\Dns\DkimSelectorRegistry;
use App\Services\Dns\EmailProviderDetector;
use App\Services\OrganizationMapper;
use App\Value\Dns\DkimLookupOutcome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DkimCheckerTest extends TestCase
{
    #[Test]
    public function reportsKeyFoundWhenTxtContainsValidKey(): void
    {
        $dns = (new StubDns())
            ->withTxt('google._domainkey.example.com', 'v=DKIM1; k=rsa; p='.$this->fakePublicKey(2048));

        $checker = $this->checker($dns);

        $result = $checker->check('example.com', 'google');

        self::assertSame(DkimLookupOutcome::KeyFound, $result->outcome);
        self::assertTrue($result->keyExists);
        self::assertSame('google', $result->selector);
        self::assertContains('Google', $result->matchedProviders);
    }

    #[Test]
    public function reportsKeyRevokedWhenPTagIsEmpty(): void
    {
        $dns = (new StubDns())
            ->withTxt('s1._domainkey.example.com', 'v=DKIM1; k=rsa; p=');

        $checker = $this->checker($dns);

        $result = $checker->check('example.com', 's1');

        self::assertSame(DkimLookupOutcome::KeyRevoked, $result->outcome);
        self::assertTrue($result->keyExists);
    }

    #[Test]
    public function reportsCnameTargetMissingKeyWhenChainGoesToNxdomain(): void
    {
        // The exact bug we hit on myspeedpuzzling.com: CNAME exists, target returns nothing.
        $dns = (new StubDns())
            ->withCname('szn20251014._domainkey.myspeedpuzzling.com', 'szn20251014._domainkey.seznam.cz');

        $checker = $this->checker($dns);

        $result = $checker->check('myspeedpuzzling.com', 'szn20251014');

        self::assertSame(DkimLookupOutcome::CnameTargetMissingKey, $result->outcome);
        self::assertFalse($result->keyExists);
        self::assertSame('szn20251014._domainkey.seznam.cz', $result->cnameTarget);
        self::assertNotEmpty($result->issues);
        self::assertStringContainsString('seznam.cz', $result->issues[0]->message);
    }

    #[Test]
    public function reportsCnameTargetMissingKeyWhenChainResolvesToUnrelatedTxt(): void
    {
        // CNAME points at apex which has SPF/site-verification but no DKIM 'p='.
        $dns = (new StubDns())
            ->withCname('szn20251014._domainkey.example.com', 'example.com')
            ->withTxt('szn20251014._domainkey.example.com', 'v=spf1 include:_spf.google.com ~all')
            ->withTxt('szn20251014._domainkey.example.com', 'google-site-verification=xyz');

        $checker = $this->checker($dns);

        $result = $checker->check('example.com', 'szn20251014');

        self::assertSame(DkimLookupOutcome::CnameTargetMissingKey, $result->outcome);
        self::assertSame('example.com', $result->cnameTarget);
    }

    #[Test]
    public function reportsNoRecordWhenNothingExists(): void
    {
        $dns = new StubDns();

        $checker = $this->checker($dns);

        $result = $checker->check('example.com', 'nonexistent');

        self::assertSame(DkimLookupOutcome::NoRecord, $result->outcome);
        self::assertFalse($result->keyExists);
        self::assertNull($result->cnameTarget);
    }

    #[Test]
    public function reportsRecordsButNoDkimWhenTxtExistsWithoutPTag(): void
    {
        $dns = (new StubDns())
            ->withTxt('foo._domainkey.example.com', 'v=spf1 include:_spf.google.com ~all');

        $checker = $this->checker($dns);

        $result = $checker->check('example.com', 'foo');

        self::assertSame(DkimLookupOutcome::RecordsButNoDkim, $result->outcome);
    }

    #[Test]
    public function autoProbeUsesDetectedProvidersToFindKey(): void
    {
        // Domain uses Google Workspace (per MX), key lives at `google._domainkey`.
        $dns = (new StubDns())
            ->withMx('example.com', 'aspmx.l.google.com')
            ->withTxt('google._domainkey.example.com', 'v=DKIM1; k=rsa; p='.$this->fakePublicKey(2048));

        $checker = $this->checker($dns);

        $result = $checker->check('example.com');

        self::assertSame(DkimLookupOutcome::KeyFound, $result->outcome);
        self::assertSame('google', $result->selector);
        self::assertContains('Google', $result->detectedProviders);
    }

    #[Test]
    public function autoProbeReportsDetectedProvidersWhenNothingFound(): void
    {
        $dns = (new StubDns())
            ->withMx('example.com', 'aspmx.l.google.com');

        $checker = $this->checker($dns);

        $result = $checker->check('example.com');

        self::assertSame(DkimLookupOutcome::NoRecord, $result->outcome);
        self::assertContains('Google', $result->detectedProviders);
        self::assertStringContainsString('Google', $result->issues[0]->message);
    }

    #[Test]
    public function autoProbeStopsEarlyWhenCnameFoundAtKnownSelector(): void
    {
        // Selector probe hits a CNAME early — should return that result rather than continuing.
        $dns = (new StubDns())
            ->withMx('example.com', 'aspmx.l.google.com')
            ->withCname('google._domainkey.example.com', 'google._domainkey.elsewhere.com');

        $checker = $this->checker($dns);

        $result = $checker->check('example.com');

        self::assertSame('google', $result->selector);
        self::assertSame('google._domainkey.elsewhere.com', $result->cnameTarget);
    }

    private function checker(StubDns $dns): DkimChecker
    {
        $organizationMapper = new OrganizationMapper();
        $registry = new DkimSelectorRegistry();
        $detector = new EmailProviderDetector($dns, $organizationMapper);

        return new DkimChecker($dns, $detector, $registry);
    }

    private function fakePublicKey(int $bits): string
    {
        // Length-only stub used by estimateKeyBits() — base64-decoded length bucket
        // maps to bit-strength. 250 bytes ~ 2048-bit RSA bucket in our heuristic.
        $bytesByBits = [1024 => 150, 2048 => 250, 4096 => 500];
        $bytes = $bytesByBits[$bits] ?? 250;

        return base64_encode(str_repeat('A', $bytes));
    }
}
