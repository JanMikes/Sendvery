<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\Dns\RuaScenarioResult;
use App\Results\DnsHealthOverviewResult;
use App\Services\Dns\RuaMailboxMatcher;
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

    #[Test]
    public function pointsAtExternalWithMatchingMailboxRoutesRuaRowToSuccessTone(): void
    {
        // TASK-114 happy path: scenario PointsAtExternal AND the rua= address
        // routes to a connected mailbox. The 5th row flips from Invalid
        // (yellow warning) to Configured (green) and the copy names the
        // mailbox the matcher matched against — matching the green
        // "Ingesting via mailbox" badge on `/app/mailboxes` for the same
        // domain so the two surfaces stop telling opposite stories.
        $status = $this->resolver(matcherReturns: true)->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@acme.com'),
            null,
            'domain-id',
        );

        $rua = $this->ruaRow($status->protocols);
        self::assertSame(ProtocolState::Configured, $rua->state);
        self::assertStringContainsString('Routed to your connected mailbox', $rua->statusLine);
        self::assertStringContainsString('reports@acme.com', $rua->statusLine);
        self::assertNull($rua->nextStep);
        self::assertTrue($status->ruaRoutedToConnectedMailbox);
    }

    #[Test]
    public function pointsAtExternalWithMatchingMailboxOverridesHeadlineAndLede(): void
    {
        // Cross-surface consistency: the banner headline AND panel lede both
        // drop the "external inbox" / "choose where reports land" warnings
        // when the matcher confirms the rua= address routes to a mailbox
        // we're polling. Otherwise an operator would see green badges on
        // `/app/mailboxes` and still get warning copy on `/app/domains/{id}`.
        $status = $this->resolver(matcherReturns: true)->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@acme.com'),
            null,
            'domain-id',
        );

        self::assertSame(DomainSetupDisplayMode::BannerAndPanel, $status->displayMode);
        self::assertSame('Monitoring active — reports arriving via your connected mailbox', $status->headline);
        self::assertStringContainsString('your connected mailbox', $status->panelLede);
        self::assertStringContainsString('reports@acme.com', $status->panelLede);
        // Regression: the scenario-(c) "external inbox" warning copy must not
        // leak when the matcher said yes.
        self::assertStringNotContainsString('Configured for external inbox', $status->panelLede);
        self::assertStringNotContainsString('choose where reports land', $status->headline);
    }

    #[Test]
    public function pointsAtExternalWithoutDomainIdKeepsLegacyWarningCopy(): void
    {
        // Legacy call site (no domainId passed) — the matcher is never
        // consulted and the resolver falls back to the existing scenario-(c)
        // warning copy. Locks the back-compat path used by snapshot tests
        // + standalone unit tests.
        $status = $this->resolver(matcherReturns: true)->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@acme.com'),
        );

        self::assertFalse($status->ruaRoutedToConnectedMailbox);
        self::assertSame('DNS records in place — choose where reports land', $status->headline);
        $rua = $this->ruaRow($status->protocols);
        self::assertSame(ProtocolState::Invalid, $rua->state);
    }

    #[Test]
    public function pointsAtExternalWithDomainIdButNoMatchingMailboxKeepsWarningTone(): void
    {
        // Operator connected the WRONG inbox — the matcher returns false.
        // The yellow warning stays so the operator notices that the rua=
        // target isn't the connected mailbox.
        $status = $this->resolver(matcherReturns: false)->resolve(
            $this->healthyDns(),
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'reports@acme.com'),
            null,
            'domain-id',
        );

        self::assertFalse($status->ruaRoutedToConnectedMailbox);
        $rua = $this->ruaRow($status->protocols);
        self::assertSame(ProtocolState::Invalid, $rua->state);
        self::assertStringContainsString('Pointing at reports@acme.com', $rua->statusLine);
    }

    private function resolver(bool $matcherReturns = false): DomainSetupStatusResolver
    {
        $matcher = $this->createStub(RuaMailboxMatcher::class);
        $matcher->method('matchesConnectedMailbox')->willReturn($matcherReturns);

        return new DomainSetupStatusResolver(
            new ReportAddressProvider('reports@sendvery.com'),
            new DomainHealthClassifier(),
            $matcher,
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
