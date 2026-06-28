<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\CnameResolver;
use App\Services\Dns\FakeDns;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Services\ReportAddressProvider;
use App\Value\Dns\CnameVerificationOutcome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagedDmarcCnameCheckerTest extends TestCase
{
    #[Test]
    public function verifiedWhenCnamePointsAtSendvery(): void
    {
        $dns = (new FakeDns())->withCname('_dmarc.acme.example', 'acme.example._dmarc.sendvery.test');

        self::assertSame(CnameVerificationOutcome::Verified, $this->checker($dns)->verify('acme.example'));
    }

    #[Test]
    public function pointsElsewhereWhenCnameResolvesToAnotherTarget(): void
    {
        $dns = (new FakeDns())->withCname('_dmarc.acme.example', '_dmarc.someotherprovider.com');

        self::assertSame(CnameVerificationOutcome::PointsElsewhere, $this->checker($dns)->verify('acme.example'));
    }

    #[Test]
    public function missingWhenNoCnameExists(): void
    {
        self::assertSame(CnameVerificationOutcome::Missing, $this->checker(new FakeDns())->verify('acme.example'));
    }

    #[Test]
    public function matchIsCaseInsensitiveAndIgnoresTrailingDot(): void
    {
        $dns = (new FakeDns())->withCname('_dmarc.acme.example', 'ACME.example._DMARC.Sendvery.test.');

        self::assertSame(CnameVerificationOutcome::Verified, $this->checker($dns)->verify('acme.example'));
    }

    #[Test]
    public function exposesTheExpectedImmutableTarget(): void
    {
        self::assertSame('acme.example._dmarc.sendvery.test', $this->checker(new FakeDns())->expectedTarget('acme.example'));
    }

    #[Test]
    public function flagsAConflictingTxtWhenTheCnameIsNotYetVerified(): void
    {
        $dns = (new FakeDns())->withTxt('_dmarc.acme.example', 'v=DMARC1; p=reject; rua=mailto:dmarc@acme.example');

        self::assertTrue($this->checker($dns)->hasConflictingDmarcTxt('acme.example'));
    }

    #[Test]
    public function reportsNoConflictOnceTheCnameIsVerified(): void
    {
        // A CNAME and a TXT cannot coexist — once the CNAME resolves to us, the
        // TXT lookup returns our hosted record via the CNAME, which is not a conflict.
        $dns = (new FakeDns())->withCname('_dmarc.acme.example', 'acme.example._dmarc.sendvery.test');

        self::assertFalse($this->checker($dns)->hasConflictingDmarcTxt('acme.example'));
    }

    #[Test]
    public function reportsNoConflictWhenThereIsNoTxt(): void
    {
        self::assertFalse($this->checker(new FakeDns())->hasConflictingDmarcTxt('acme.example'));
    }

    #[Test]
    public function degradesToMissingWhenTheReportAddressIsMalformed(): void
    {
        $dns = (new FakeDns())->withCname('_dmarc.acme.example', 'acme.example._dmarc.sendvery.test');
        $checker = new ManagedDmarcCnameChecker(new CnameResolver($dns), new ReportAddressProvider('no-at-sign'), $dns);

        self::assertSame(CnameVerificationOutcome::Missing, $checker->verify('acme.example'));
        self::assertNull($checker->expectedTarget('acme.example'));
    }

    #[Test]
    public function treatsAFailedTxtLookupAsNoConflict(): void
    {
        $dns = (new FakeDns())->throwOn('_dmarc.acme.example', 'TXT');

        self::assertFalse($this->checker($dns)->hasConflictingDmarcTxt('acme.example'));
    }

    private function checker(FakeDns $dns): ManagedDmarcCnameChecker
    {
        return new ManagedDmarcCnameChecker(
            new CnameResolver($dns),
            new ReportAddressProvider('reports@sendvery.test'),
            $dns,
        );
    }
}
