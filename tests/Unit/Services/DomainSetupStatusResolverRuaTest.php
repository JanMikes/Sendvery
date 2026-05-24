<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\Dns\RuaScenarioResult;
use App\Results\DnsHealthOverviewResult;
use App\Services\DomainHealthClassifier;
use App\Services\DomainSetupStatusResolver;
use App\Services\ReportAddressProvider;
use App\Value\Dns\RuaScenario;
use App\Value\DomainSetupDisplayMode;
use App\Value\ProtocolState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TASK-100: dedicated coverage for the new 5th "RUA destination" checklist row
 * and the BannerAndPanel display-mode override when the RUA scenario is
 * PointsAtExternal even with all four core protocols healthy.
 *
 * Lives as a sibling file to `DomainSetupStatusResolverTest` so the existing
 * test shape stays focused on the SPF/DKIM/DMARC/MX matrix while RUA-specific
 * scenarios get a dedicated home.
 */
final class DomainSetupStatusResolverRuaTest extends TestCase
{
    #[Test]
    public function ruaRowIsUnknownWhenScenarioIsNull(): void
    {
        // Legacy callers that don't pass a scenario get the row in Unknown
        // state — same shape as the four protocol rows in the unchecked
        // branch, so the panel renders consistently.
        $status = $this->resolver()->resolve($this->healthyDns(), null);

        $rua = $this->ruaRow($status->protocols);
        self::assertSame(ProtocolState::Unknown, $rua->state);
        self::assertSame('No DNS check yet', $rua->statusLine);
        self::assertNull($rua->nextStep);
        self::assertSame('health-dmarc', $rua->healthAnchor);
    }

    #[Test]
    public function ruaRowIsUnknownWhenDnsHealthIsUnchecked(): void
    {
        // Pre-first-check pending state: even with a real scenario passed in,
        // the row stays Unknown until the DNS cron has actually run — we
        // shouldn't claim anything about RUA before there's data.
        $status = $this->resolver()->resolve(
            null,
            new RuaScenarioResult(RuaScenario::NoRecord, null),
        );

        $rua = $this->ruaRow($status->protocols);
        self::assertSame(ProtocolState::Unknown, $rua->state);
        self::assertSame('No DNS check yet', $rua->statusLine);
    }

    #[Test]
    public function ruaRowIsMissingForNoRecordScenario(): void
    {
        $status = $this->resolver()->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::NoRecord, null),
        );

        $rua = $this->ruaRow($status->protocols);
        self::assertSame(ProtocolState::Missing, $rua->state);
        self::assertStringContainsString("isn't receiving reports", $rua->statusLine);
        self::assertNotNull($rua->nextStep);
        self::assertStringContainsString('rua=mailto:reports@sendvery.com', $rua->nextStep);
        self::assertSame('dmarc-quick-start', $rua->kbSlug);
    }

    #[Test]
    public function ruaRowIsConfiguredForPointsAtSendveryScenario(): void
    {
        $status = $this->resolver()->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
        );

        $rua = $this->ruaRow($status->protocols);
        self::assertSame(ProtocolState::Configured, $rua->state);
        self::assertStringContainsString('Pointing at Sendvery', $rua->statusLine);
        self::assertNull($rua->nextStep);
    }

    #[Test]
    public function ruaRowIsInvalidForPointsAtExternalScenario(): void
    {
        $status = $this->resolver()->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@acme.com'),
        );

        $rua = $this->ruaRow($status->protocols);
        self::assertSame(ProtocolState::Invalid, $rua->state);
        self::assertStringContainsString('reports@acme.com', $rua->statusLine);
        self::assertNotNull($rua->nextStep);
        self::assertStringContainsString('reports@acme.com', $rua->nextStep);
        self::assertStringContainsString('reports@sendvery.com', $rua->nextStep);
    }

    #[Test]
    public function pointsAtExternalForcesBannerAndPanelDisplayMode(): void
    {
        // Regression: even with all four protocols Configured, scenario (c)
        // must force BannerAndPanel so the user actually sees the RUA
        // decision row. Otherwise BannerOnly would hide the panel and the
        // scenario-(c) recommendation would never surface.
        $status = $this->resolver()->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@acme.com'),
        );

        self::assertSame(DomainSetupDisplayMode::BannerAndPanel, $status->displayMode);
        // TASK-101: scenario (c) headline is scenario-aware — saying "all
        // four records are in place" while the panel below shows a yellow
        // 5th row would have been a same-page contradiction.
        self::assertSame('DNS records in place — choose where reports land', $status->headline);
        self::assertStringContainsString('SPF, DKIM, DMARC and MX are all configured', $status->panelLede);
    }

    #[Test]
    public function pointsAtSendveryKeepsBannerOnlyDisplayMode(): void
    {
        // Counterpart: scenario (b) doesn't force the panel — all-green
        // really is all-green; BannerOnly stays the right choice.
        $status = $this->resolver()->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::PointsAtSendvery, 'reports@sendvery.com'),
        );

        self::assertSame(DomainSetupDisplayMode::BannerOnly, $status->displayMode);
    }

    private function resolver(): DomainSetupStatusResolver
    {
        return new DomainSetupStatusResolver(
            new ReportAddressProvider('reports@sendvery.com'),
            new DomainHealthClassifier(),
        );
    }

    private function healthyDns(): DnsHealthOverviewResult
    {
        return new DnsHealthOverviewResult(
            domainId: 'domain-id',
            domainName: 'example.com',
            spfVerifiedAt: new \DateTimeImmutable(),
            dkimVerifiedAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: new \DateTimeImmutable(),
            latestSnapshotGrade: 'A',
            latestSnapshotScore: 95,
            latestSpfScore: 100,
            latestDkimScore: 100,
            latestDmarcScore: 100,
            latestMxScore: 95,
            latestCheckedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * @param list<\App\Results\ProtocolSetupStatus> $protocols
     */
    private function ruaRow(array $protocols): \App\Results\ProtocolSetupStatus
    {
        foreach ($protocols as $protocol) {
            if ('RUA destination' === $protocol->name) {
                return $protocol;
            }
        }

        self::fail('RUA destination row not found in protocols list');
    }
}
