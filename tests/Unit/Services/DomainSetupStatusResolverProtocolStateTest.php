<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\Dns\DnsProtocolStateResult;
use App\Results\DnsHealthOverviewResult;
use App\Services\Dns\RuaMailboxMatcher;
use App\Services\DomainHealthClassifier;
use App\Services\DomainSetupStatusResolver;
use App\Services\ReportAddressProvider;
use App\Value\DnsCheckType;
use App\Value\DomainSetupDisplayMode;
use App\Value\ProtocolState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Per-protocol setup state must come from the stored DNS checks, which every
 * check path writes — not from the nightly health snapshot, which only the
 * 03:00 cron writes.
 *
 * The MX cases are the reason this exists. MX is the one protocol with no
 * `mx_verified_at` column to fall back on, so before this change a domain added
 * during the day reported "MX records not detected" until the next nightly
 * sweep — with valid, resolving MX records the whole time.
 */
final class DomainSetupStatusResolverProtocolStateTest extends TestCase
{
    #[Test]
    public function mxReadsAsConfiguredFromAPassingCheckEvenWithNoHealthSnapshotAtAll(): void
    {
        // The exact production shape: the first DNS check has run and MX passed,
        // but no domain_health_snapshot row exists yet because the nightly cron
        // has not come round. This is what every freshly added domain looks like.
        $status = $this->resolver()->resolve(
            $this->unscoredDnsHealth(),
            protocolStates: [
                DnsCheckType::Mx->value => $this->check(DnsCheckType::Mx, '10 mx1.example.net, 20 mx2.example.net', true),
            ],
        );

        $mx = $this->row($status, 'MX');
        self::assertSame(
            ProtocolState::Configured,
            $mx->state,
            'A passing MX check means the records are there, snapshot or no snapshot.',
        );
        self::assertStringNotContainsString(
            'not detected',
            $mx->statusLine,
            'Claiming MX is missing while a passing check says otherwise is a wrong-information bug.',
        );
        self::assertNull($mx->nextStep, 'There is nothing to ask the user to do about a healthy record.');
    }

    #[Test]
    public function oneStoredCheckIsEnoughToLeaveTheNothingCheckedYetState(): void
    {
        // Pending-ness must be decided by "has a check run?", not by "has the
        // snapshot cron run?" — otherwise the surface keeps promising results
        // that already exist.
        $status = $this->resolver()->resolve(
            $this->unscoredDnsHealth(),
            protocolStates: [
                DnsCheckType::Mx->value => $this->check(DnsCheckType::Mx, '10 mx1.example.net', true),
            ],
        );

        self::assertNotSame(
            DomainSetupDisplayMode::PanelOnly,
            $status->displayMode,
            'Once any check has run the domain is no longer waiting for its first check.',
        );
    }

    #[Test]
    public function aCheckThatFoundNoRecordReadsAsMissingSoTheUserIsToldToAddOne(): void
    {
        $status = $this->resolver()->resolve(
            $this->unscoredDnsHealth(),
            protocolStates: [
                DnsCheckType::Spf->value => $this->check(DnsCheckType::Spf, null, false),
                DnsCheckType::Mx->value => $this->check(DnsCheckType::Mx, '10 mx1.example.net', true),
            ],
        );

        $spf = $this->row($status, 'SPF');
        self::assertSame(ProtocolState::Missing, $spf->state);
        self::assertNotNull($spf->nextStep);
    }

    #[Test]
    public function aCheckThatFoundABrokenRecordReadsAsInvalidSoTheUserIsToldToFixIt(): void
    {
        // Missing vs Invalid is the difference between "add a record" and "edit
        // the record you have" — publishing a second SPF record instead of
        // fixing the first one breaks the domain outright.
        $status = $this->resolver()->resolve(
            $this->unscoredDnsHealth(),
            protocolStates: [
                DnsCheckType::Spf->value => $this->check(DnsCheckType::Spf, 'v=spf1 include:broken', false),
                DnsCheckType::Mx->value => $this->check(DnsCheckType::Mx, '10 mx1.example.net', true),
            ],
        );

        $spf = $this->row($status, 'SPF');
        self::assertSame(ProtocolState::Invalid, $spf->state);
        self::assertStringContainsString('present but failing', $spf->statusLine);
    }

    #[Test]
    public function anEmptyRawRecordCountsAsMissingRatherThanAsABrokenRecord(): void
    {
        $status = $this->resolver()->resolve(
            $this->unscoredDnsHealth(),
            protocolStates: [
                DnsCheckType::Dkim->value => $this->check(DnsCheckType::Dkim, '   ', false),
                DnsCheckType::Mx->value => $this->check(DnsCheckType::Mx, '10 mx1.example.net', true),
            ],
        );

        self::assertSame(ProtocolState::Missing, $this->row($status, 'DKIM')->state);
    }

    #[Test]
    public function aProtocolWithNoStoredCheckFallsBackToTheOlderDerivation(): void
    {
        // Partially seeded data must not make a protocol vanish from the
        // checklist — the fallback keeps the row meaningful.
        $verifiedAt = new \DateTimeImmutable('2026-07-01 09:00:00');

        $status = $this->resolver()->resolve(
            new DnsHealthOverviewResult(
                domainId: 'domain-id',
                domainName: 'example.com',
                spfVerifiedAt: $verifiedAt,
                dkimVerifiedAt: null,
                dmarcVerifiedAt: $verifiedAt,
                latestSnapshotGrade: null,
                latestSnapshotScore: null,
                latestSpfScore: null,
                latestDkimScore: null,
                latestDmarcScore: null,
                latestMxScore: null,
                latestCheckedAt: null,
            ),
            protocolStates: [
                DnsCheckType::Mx->value => $this->check(DnsCheckType::Mx, '10 mx1.example.net', true),
            ],
        );

        self::assertSame(ProtocolState::Configured, $this->row($status, 'SPF')->state, 'The SPF verification timestamp still counts when no check row exists.');
        self::assertSame(ProtocolState::Missing, $this->row($status, 'DKIM')->state);
        self::assertSame(ProtocolState::Configured, $this->row($status, 'MX')->state);
    }

    #[Test]
    public function everyProtocolPrefersItsStoredCheckOverTheSnapshotScore(): void
    {
        // A stale snapshot must never win: the check row is newer by
        // construction (it is written first) and is what the user just triggered.
        $verifiedAt = new \DateTimeImmutable('2026-07-01 09:00:00');

        $status = $this->resolver()->resolve(
            new DnsHealthOverviewResult(
                domainId: 'domain-id',
                domainName: 'example.com',
                spfVerifiedAt: $verifiedAt,
                dkimVerifiedAt: $verifiedAt,
                dmarcVerifiedAt: $verifiedAt,
                latestSnapshotGrade: 'A',
                latestSnapshotScore: 95,
                latestSpfScore: 100,
                latestDkimScore: 100,
                latestDmarcScore: 100,
                latestMxScore: 95,
                latestCheckedAt: $verifiedAt,
            ),
            protocolStates: [
                DnsCheckType::Spf->value => $this->check(DnsCheckType::Spf, null, false),
                DnsCheckType::Dkim->value => $this->check(DnsCheckType::Dkim, null, false),
                DnsCheckType::Dmarc->value => $this->check(DnsCheckType::Dmarc, null, false),
                DnsCheckType::Mx->value => $this->check(DnsCheckType::Mx, null, false),
            ],
        );

        foreach (['SPF', 'DKIM', 'DMARC', 'MX'] as $name) {
            self::assertSame(
                ProtocolState::Missing,
                $this->row($status, $name)->state,
                sprintf('%s must follow its latest check, not yesterday\'s snapshot score.', $name),
            );
        }
    }

    private function row(\App\Results\DomainSetupStatus $status, string $name): \App\Results\ProtocolSetupStatus
    {
        foreach ($status->protocols as $protocol) {
            if ($protocol->name === $name) {
                return $protocol;
            }
        }

        self::fail(sprintf('No "%s" row on the setup status.', $name));
    }

    private function check(DnsCheckType $type, ?string $rawRecord, bool $isValid): DnsProtocolStateResult
    {
        return new DnsProtocolStateResult(
            type: $type,
            checkedAt: new \DateTimeImmutable('2026-07-27 10:15:00'),
            rawRecord: $rawRecord,
            isValid: $isValid,
        );
    }

    /**
     * A domain the nightly snapshot cron has never touched: no grade, no scores,
     * no verification timestamps. Exactly what a domain added minutes ago looks
     * like.
     */
    private function unscoredDnsHealth(): DnsHealthOverviewResult
    {
        return new DnsHealthOverviewResult(
            domainId: 'domain-id',
            domainName: 'example.com',
            spfVerifiedAt: null,
            dkimVerifiedAt: null,
            dmarcVerifiedAt: null,
            latestSnapshotGrade: null,
            latestSnapshotScore: null,
            latestSpfScore: null,
            latestDkimScore: null,
            latestDmarcScore: null,
            latestMxScore: null,
            latestCheckedAt: null,
        );
    }

    private function resolver(): DomainSetupStatusResolver
    {
        $matcher = $this->createStub(RuaMailboxMatcher::class);
        $matcher->method('matchesConnectedMailbox')->willReturn(false);

        return new DomainSetupStatusResolver(
            new ReportAddressProvider('reports@sendvery.com'),
            new DomainHealthClassifier(),
            $matcher,
        );
    }
}
