<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Services\Dns\HealthSnapshotComposer;
use App\Value\DnsCheckType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class HealthSnapshotComposerTest extends TestCase
{
    private HealthSnapshotComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new HealthSnapshotComposer();
    }

    #[Test]
    public function allValidChecksProduceGradeAWithFullScore(): void
    {
        $result = $this->composer->compose(
            spf: $this->dnsResult(DnsCheckType::Spf, isValid: true),
            dkim: $this->dnsResult(DnsCheckType::Dkim, isValid: true),
            dmarc: $this->dnsResult(DnsCheckType::Dmarc, isValid: true),
            mx: $this->dnsResult(DnsCheckType::Mx, isValid: true),
        );

        self::assertSame(100, $result->spfScore);
        self::assertSame(100, $result->dkimScore);
        self::assertSame(100, $result->dmarcScore);
        self::assertSame(100, $result->mxScore);
        self::assertSame(100, $result->blacklistScore);
        self::assertSame(100, $result->score);
        self::assertSame('A', $result->grade);
    }

    #[Test]
    public function allInvalidWithDefaultBlacklistGetsGradeF(): void
    {
        // Only the 20% blacklist weight contributes -> 20 -> F.
        $result = $this->composer->compose(
            spf: $this->dnsResult(DnsCheckType::Spf, isValid: false),
            dkim: $this->dnsResult(DnsCheckType::Dkim, isValid: false),
            dmarc: $this->dnsResult(DnsCheckType::Dmarc, isValid: false),
            mx: $this->dnsResult(DnsCheckType::Mx, isValid: false),
        );

        self::assertSame(0, $result->spfScore);
        self::assertSame(0, $result->dkimScore);
        self::assertSame(0, $result->dmarcScore);
        self::assertSame(0, $result->mxScore);
        self::assertSame(100, $result->blacklistScore);
        self::assertSame(20, $result->score);
        self::assertSame('F', $result->grade);
    }

    #[Test]
    public function nullDnsResultIsTreatedAsZeroScore(): void
    {
        // All four DNS checks missing => same as all-invalid.
        $result = $this->composer->compose(null, null, null, null);

        self::assertSame(0, $result->spfScore);
        self::assertSame(0, $result->dkimScore);
        self::assertSame(0, $result->dmarcScore);
        self::assertSame(0, $result->mxScore);
        self::assertSame(100, $result->blacklistScore);
        self::assertSame(20, $result->score);
        self::assertSame('F', $result->grade);
    }

    #[Test]
    public function explicitZeroBlacklistAllInvalidGivesZeroScoreFGrade(): void
    {
        $result = $this->composer->compose(
            spf: null,
            dkim: null,
            dmarc: null,
            mx: null,
            blacklistScore: 0,
        );

        self::assertSame(0, $result->blacklistScore);
        self::assertSame(0, $result->score);
        self::assertSame('F', $result->grade);
    }

    #[Test]
    public function weightedFormulaSpotCheckValidDmarcAndSpfOnly(): void
    {
        // SPF + DMARC valid (100 each), DKIM + MX invalid (0), blacklist default 100.
        // Score = 100*0.25 + 100*0.20 + 0*0.20 + 0*0.15 + 100*0.20 = 65 -> grade C.
        $result = $this->composer->compose(
            spf: $this->dnsResult(DnsCheckType::Spf, isValid: true),
            dkim: $this->dnsResult(DnsCheckType::Dkim, isValid: false),
            dmarc: $this->dnsResult(DnsCheckType::Dmarc, isValid: true),
            mx: $this->dnsResult(DnsCheckType::Mx, isValid: false),
        );

        self::assertSame(65, $result->score);
        self::assertSame('C', $result->grade);
    }

    #[Test]
    public function gradeBandThresholdsAreA90B75C55D35F34(): void
    {
        // Bands and weights mirror DomainHealthScorer; per-protocol sub-scoring is
        // intentionally binary (isValid -> 100 / 0) for v1 — see TASK-042 notes.
        // 75-floor B: DMARC + MX + blacklist valid (full), SPF + DKIM invalid.
        // 100*0.25 + 0*0.20 + 0*0.20 + 100*0.15 + 100*0.20 = 60 -> too low.
        // Use DMARC + SPF + MX valid, DKIM invalid, blacklist 75:
        // 100*0.25 + 100*0.20 + 0*0.20 + 100*0.15 + 75*0.20 = 75 -> B exactly.
        $result = $this->composer->compose(
            spf: $this->dnsResult(DnsCheckType::Spf, isValid: true),
            dkim: $this->dnsResult(DnsCheckType::Dkim, isValid: false),
            dmarc: $this->dnsResult(DnsCheckType::Dmarc, isValid: true),
            mx: $this->dnsResult(DnsCheckType::Mx, isValid: true),
            blacklistScore: 75,
        );
        self::assertSame(75, $result->score);
        self::assertSame('B', $result->grade);

        // 55-floor C: SPF + DMARC valid (full), DKIM + MX invalid, blacklist 50.
        // 100*0.25 + 100*0.20 + 0*0.20 + 0*0.15 + 50*0.20 = 55 -> C exactly.
        $result = $this->composer->compose(
            spf: $this->dnsResult(DnsCheckType::Spf, isValid: true),
            dkim: $this->dnsResult(DnsCheckType::Dkim, isValid: false),
            dmarc: $this->dnsResult(DnsCheckType::Dmarc, isValid: true),
            mx: $this->dnsResult(DnsCheckType::Mx, isValid: false),
            blacklistScore: 50,
        );
        self::assertSame(55, $result->score);
        self::assertSame('C', $result->grade);

        // 35-floor D: DMARC valid only, blacklist 50.
        // 100*0.25 + 0*0.20 + 0*0.20 + 0*0.15 + 50*0.20 = 35 -> D exactly.
        $result = $this->composer->compose(
            spf: $this->dnsResult(DnsCheckType::Spf, isValid: false),
            dkim: $this->dnsResult(DnsCheckType::Dkim, isValid: false),
            dmarc: $this->dnsResult(DnsCheckType::Dmarc, isValid: true),
            mx: $this->dnsResult(DnsCheckType::Mx, isValid: false),
            blacklistScore: 50,
        );
        self::assertSame(35, $result->score);
        self::assertSame('D', $result->grade);

        // Just below D-floor: blacklist 50 only -> 50*0.20 = 10 -> F.
        $result = $this->composer->compose(null, null, null, null, blacklistScore: 50);
        self::assertSame(10, $result->score);
        self::assertSame('F', $result->grade);
    }

    private function dnsResult(DnsCheckType $type, bool $isValid): DnsCheckResult
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Composer Unit',
            slug: 'composer-unit',
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'composer-unit.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();

        return new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: null,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
    }
}
