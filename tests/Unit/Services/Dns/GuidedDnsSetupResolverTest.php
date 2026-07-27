<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Results\Dns\RuaScenarioResult;
use App\Results\DomainSetupStatus;
use App\Results\ProtocolSetupStatus;
use App\Services\Dns\DnsRecordRecommender;
use App\Services\Dns\GuidedDnsSetupResolver;
use App\Services\ReportAddressProvider;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\DnsRecordAction;
use App\Value\Dns\GuidedDnsSetup;
use App\Value\Dns\GuidedSetupStep;
use App\Value\Dns\ManagedDeliveryContext;
use App\Value\Dns\RuaScenario;
use App\Value\Dns\SetupCaution;
use App\Value\Dns\SetupTier;
use App\Value\DnsCheckType;
use App\Value\DomainHealthFilter;
use App\Value\DomainSetupDisplayMode;
use App\Value\ProtocolState;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The guided DNS setup surface answers one question — "what should I do next?" —
 * and these tests pin down the answer it gives.
 *
 * The behaviour it replaced was a flat five-row checklist where every unfinished
 * record looked equally urgent, which a user summarised as "it is misleading and
 * i do not fully understand what should i do".
 */
final class GuidedDnsSetupResolverTest extends TestCase
{
    private const string REPORT_ADDRESS = 'reports@sendvery.test';

    #[Test]
    public function whileTheFirstCheckIsStillRunningNothingIsClaimedAboutAnyRecord(): void
    {
        // A domain added moments ago: the check is queued but has not landed.
        // Showing red "missing record" rows here would be inventing findings.
        $setup = $this->resolve(
            setupStatus: $this->pendingStatus(),
            latestByType: $this->noChecks(),
        );

        self::assertTrue($setup->checkInProgress);
        self::assertSame('Checking your DNS now', $setup->headline);
        self::assertSame([], $setup->actionRequired);
        self::assertSame([], $setup->later);
        self::assertSame([], $setup->done);
        self::assertFalse($setup->hasOutstandingWork());
        // The delivery choice is still offered — it is a decision the user can
        // make before we know anything about their DNS.
        self::assertCount(2, $setup->deliveryOptions);
    }

    #[Test]
    public function reportDeliveryClaimsTheSingleActionableSlotWhenEverythingIsOutstanding(): void
    {
        // Without reports reaching Sendvery nothing else in the product works,
        // so it outranks SPF, DKIM and MX for the one slot.
        $setup = $this->resolve(
            setupStatus: $this->statusFor(
                spf: ProtocolState::Missing,
                dkim: ProtocolState::Missing,
                dmarc: ProtocolState::Missing,
                mx: ProtocolState::Missing,
                rua: ProtocolState::Missing,
            ),
            latestByType: $this->noChecks(),
        );

        self::assertCount(1, $setup->actionRequired, 'Exactly one step is ever actionable now.');
        self::assertSame('delivery', $setup->actionRequired[0]->key);
        self::assertSame(SetupTier::ActionRequired, $setup->actionRequired[0]->tier);
        self::assertSame(['spf', 'dkim', 'mx'], $this->keys($setup->later));
        foreach ($setup->later as $step) {
            self::assertSame(SetupTier::Later, $step->tier, 'Deferred steps say so.');
        }
    }

    #[Test]
    public function theActionableStepHandsOverAPasteableRecordWhenWeCanComputeOne(): void
    {
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
        );

        $step = $setup->actionRequired[0];
        self::assertTrue($step->hasCopyableRecord());
        self::assertSame('TXT', $step->recordType);
        self::assertSame('_dmarc', $step->recordName, 'The NAME is the short label a DNS provider asks for.');
        self::assertSame('_dmarc.example.com', $step->recordFqdn, 'The fully qualified name travels alongside it.');
        self::assertSame(DnsRecordAction::AddNew, $step->action);
        self::assertNotNull($step->finalValue);
        self::assertStringContainsString('rua=mailto:'.self::REPORT_ADDRESS, $step->finalValue);
        self::assertSame('Add 1 DNS record — TXT', $setup->headline);
    }

    #[Test]
    public function anExistingDmarcRecordIsEditedRatherThanDuplicated(): void
    {
        // Telling someone to "add" a `_dmarc` TXT record when one already exists
        // is how a domain ends up with two and DMARC stops working entirely.
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Configured, rua: ProtocolState::Invalid),
            latestByType: $this->checks([
                DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:ops@elsewhere.example',
            ]),
        );

        $step = $setup->actionRequired[0];
        self::assertSame('delivery', $step->key);
        self::assertSame(DnsRecordAction::EditExisting, $step->action);
        self::assertSame('v=DMARC1; p=none; rua=mailto:ops@elsewhere.example', $step->currentValue);
        self::assertNotNull($step->finalValue);
        self::assertStringContainsString('ops@elsewhere.example', $step->finalValue, "The user's own address is kept.");
        self::assertStringContainsString(self::REPORT_ADDRESS, $step->finalValue, 'Ours is appended.');
        self::assertStringContainsString('Add Sendvery to your DMARC record', $step->title);
    }

    #[Test]
    public function extendingAFullRuaListWarnsThatReceiversMayCapDeliveryAtTwoAddresses(): void
    {
        // RFC 7489 lets a receiver refuse a third `rua=` address. Appending
        // silently is worse than saying so: the user would publish a record that
        // looks right and never delivers.
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Configured, rua: ProtocolState::Invalid),
            latestByType: $this->checks([
                DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:a@one.example,mailto:b@two.example',
            ]),
            ruaAddressCount: 2,
        );

        $keys = array_map(
            static fn (SetupCaution $caution): string => $caution->key,
            $setup->actionRequired[0]->cautions,
        );
        self::assertContains('rua-address-limit-warning', $keys);
        self::assertContains('rua-authorization-warning', $keys, 'Reports only arrive once an authorization record exists.');
    }

    #[Test]
    public function aRuaListWithRoomToSpareIsNotWarnedAboutTheAddressCap(): void
    {
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Configured, rua: ProtocolState::Invalid),
            latestByType: $this->checks([
                DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:a@one.example',
            ]),
            ruaAddressCount: 1,
        );

        $keys = array_map(
            static fn (SetupCaution $caution): string => $caution->key,
            $setup->actionRequired[0]->cautions,
        );
        self::assertNotContains('rua-address-limit-warning', $keys, 'Adding Sendvery as the second address is within the practical limit.');
    }

    #[Test]
    public function anInstallationThatPublishesAuthorizationRecordsItselfSaysSo(): void
    {
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Configured, rua: ProtocolState::Invalid),
            latestByType: $this->checks([
                DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:a@one.example',
            ]),
            managed: $this->managedAvailable(),
        );

        $authorization = array_values(array_filter(
            $setup->actionRequired[0]->cautions,
            static fn (SetupCaution $caution): bool => 'rua-authorization-warning' === $caution->key,
        ));
        self::assertCount(1, $authorization);
        self::assertStringContainsString('automatically', $authorization[0]->text);
    }

    #[Test]
    public function thereAreNoRuaCautionsWhenThereIsNoRecordToExtend(): void
    {
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
        );

        self::assertSame([], $setup->actionRequired[0]->cautions);
    }

    #[Test]
    public function aStepWeCannotComputeAValueForExplainsTheTaskInsteadOfPromisingARecord(): void
    {
        // DKIM keys are generated at the sending platform. Inventing a value
        // would be worse than describing the job.
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dkim: ProtocolState::Missing),
            latestByType: $this->checks([DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:'.self::REPORT_ADDRESS]),
        );

        $step = $setup->actionRequired[0];
        self::assertSame('dkim', $step->key);
        self::assertFalse($step->hasCopyableRecord());
        self::assertSame('<selector>._domainkey', $step->recordName);
        self::assertSame($step->title, $setup->headline, 'With no record to paste the headline states the task.');
        self::assertSame('Here is what needs to happen next, and why.', $setup->lede);
    }

    #[Test]
    public function aFullyConfiguredDomainIsToldThereIsNothingToDo(): void
    {
        $setup = $this->resolve(
            setupStatus: $this->statusFor(
                spf: ProtocolState::Configured,
                dkim: ProtocolState::Configured,
                dmarc: ProtocolState::Configured,
                mx: ProtocolState::Configured,
                rua: ProtocolState::Configured,
            ),
            latestByType: $this->checks([
                DnsCheckType::Spf->value => 'v=spf1 -all',
                DnsCheckType::Dkim->value => 'v=DKIM1; k=rsa; p=AAA',
                DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:'.self::REPORT_ADDRESS,
                DnsCheckType::Mx->value => '10 mx1.example.net',
            ]),
        );

        self::assertFalse($setup->hasOutstandingWork());
        self::assertSame('DNS setup complete', $setup->headline);
        self::assertSame(['delivery', 'spf', 'dkim', 'mx'], $this->keys($setup->done));
        foreach ($setup->done as $step) {
            self::assertSame(SetupTier::Done, $step->tier);
            self::assertSame(DnsRecordAction::NothingToDo, $step->action);
            self::assertNull($step->finalValue, 'A finished step must not still be offering a record to publish.');
        }
    }

    #[Test]
    public function aDomainOnTheManagedPathIsAskedForTheCnameNotForATxtRecord(): void
    {
        // DNS forbids a CNAME and a TXT record at the same name, so asking for
        // both would be asking for a broken zone.
        $domain = $this->domain();
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;

        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            domain: $domain,
            managed: $this->managedAvailable(),
        );

        $step = $setup->actionRequired[0];
        self::assertSame('CNAME', $step->recordType);
        self::assertSame('example.com._dmarc.sendvery.test', $step->finalValue);
        self::assertSame(300, $step->ttl, 'A record the user never edits again can propagate quickly.');
        self::assertStringContainsString('one CNAME', $step->title);
    }

    #[Test]
    public function bothDeliveryPathsAreAlwaysOfferedWithTheManagedOneMarkedPremium(): void
    {
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            managed: $this->managedAvailable(),
        );

        [$selfTxt, $managedCname] = $setup->deliveryOptions;

        self::assertSame(DmarcSetupMode::SelfTxt, $selfTxt->mode);
        self::assertTrue($selfTxt->selected, 'A domain not on the managed path is on the self-managed one.');
        self::assertTrue($selfTxt->available);
        self::assertFalse($selfTxt->isPremium);
        self::assertNull($selfTxt->switchRoute, 'Already here — nothing to switch to.');

        self::assertSame(DmarcSetupMode::ManagedCname, $managedCname->mode);
        self::assertFalse($managedCname->selected);
        self::assertTrue($managedCname->available);
        self::assertTrue($managedCname->isPremium);
        self::assertNull($managedCname->unavailableReason);
        self::assertSame('dashboard_domain_enable_managed_dmarc', $managedCname->switchRoute);
    }

    #[Test]
    public function aPlanWithoutManagedDmarcSeesTheOptionWithAnUpgradePathRatherThanNothing(): void
    {
        // The complaint was "it does not allow me at all" — an option that is
        // simply absent teaches the user nothing.
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            managed: new ManagedDeliveryContext(
                dnsAutomationConfigured: true,
                managedAvailable: false,
                nextTier: SubscriptionPlan::Personal,
                cnameTarget: 'example.com._dmarc.sendvery.test',
            ),
        );

        $managedCname = $setup->deliveryOptions[1];
        self::assertFalse($managedCname->available);
        self::assertNotNull($managedCname->unavailableReason);
        self::assertStringContainsString('Personal', $managedCname->unavailableReason, 'The upsell names the tier that unlocks it.');
        self::assertSame('pricing', $managedCname->upgradeRoute);
        self::assertNull($managedCname->switchRoute, 'An option the team cannot use must not offer a switch.');
    }

    #[Test]
    public function aTopTierPlanThatStillCannotUseManagedDmarcGetsAPlainExplanation(): void
    {
        // No higher tier to point at, so the copy must not dangle an upgrade.
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            managed: new ManagedDeliveryContext(
                dnsAutomationConfigured: true,
                managedAvailable: false,
                nextTier: null,
                cnameTarget: 'example.com._dmarc.sendvery.test',
            ),
        );

        self::assertSame(
            'Managed DMARC is not included in your current plan.',
            $setup->deliveryOptions[1]->unavailableReason,
        );
    }

    #[Test]
    public function aSelfHostedInstallWithNoDnsProviderExplainsWhyItCannotHostRecords(): void
    {
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            managed: ManagedDeliveryContext::unavailable(),
        );

        $managedCname = $setup->deliveryOptions[1];
        self::assertFalse($managedCname->available);
        self::assertNotNull($managedCname->unavailableReason);
        self::assertStringContainsString('no DNS provider connected', $managedCname->unavailableReason);
        self::assertNull($managedCname->upgradeRoute, 'Upgrading cannot fix a missing DNS provider, so no plans link.');
    }

    #[Test]
    public function aDomainAlreadyOnTheManagedPathCanHandTheRecordBack(): void
    {
        $domain = $this->domain();
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;

        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            domain: $domain,
            managed: $this->managedAvailable(),
        );

        [$selfTxt, $managedCname] = $setup->deliveryOptions;
        self::assertTrue($managedCname->selected);
        self::assertFalse($selfTxt->selected);
        self::assertSame('dashboard_domain_switch_to_self_txt', $selfTxt->switchRoute);
    }

    #[Test]
    public function aLeftoverDmarcTxtIsSurfacedAsTheRecordToDeleteBeforeTheCname(): void
    {
        // The complaint that forced this: the surface handed over the CNAME
        // without a word about the `_dmarc` TXT already sitting at the same
        // name. DNS forbids the two coexisting, so the pasted CNAME simply
        // never takes effect and the page keeps saying "we can't see it yet".
        $domain = $this->domain();
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;

        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Configured, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            domain: $domain,
            managed: $this->managedAvailable(conflictingDmarcTxt: 'v=DMARC1; p=quarantine; rua=mailto:me@example.com'),
        );

        $step = $setup->actionRequired[0];
        $prerequisite = $step->prerequisite;

        self::assertNotNull($prerequisite, 'The blocking record must be part of the model, not left for the user to discover.');
        self::assertSame(DnsRecordAction::DeleteExisting, $prerequisite->action);
        self::assertSame('TXT', $prerequisite->recordType);
        self::assertSame('_dmarc.example.com', $prerequisite->recordFqdn);
        self::assertSame('v=DMARC1; p=quarantine; rua=mailto:me@example.com', $prerequisite->currentValue);
        self::assertStringContainsString('Delete', $prerequisite->title);

        self::assertSame('CNAME', $step->recordType, 'The step still ends in the CNAME — the deletion is step one of two.');
        self::assertSame('example.com._dmarc.sendvery.test', $step->finalValue);
    }

    #[Test]
    public function aBlockedCnameIsAnnouncedAsTwoOrderedChangesInTheHeadline(): void
    {
        // Some users read the headline and switch straight to their DNS
        // provider's tab. "Add 1 DNS record" there loses the deletion entirely.
        $domain = $this->domain();
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;

        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            domain: $domain,
            managed: $this->managedAvailable(conflictingDmarcTxt: 'v=DMARC1; p=none'),
        );

        self::assertSame('Swap 1 DNS record — delete the TXT, add the CNAME', $setup->headline);
        self::assertStringContainsString('in this order', $setup->lede);
    }

    #[Test]
    public function withNothingInTheWayTheCnameIsStillASingleAddWithNoDeletionInvented(): void
    {
        $domain = $this->domain();
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;

        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            domain: $domain,
            managed: $this->managedAvailable(),
        );

        $step = $setup->actionRequired[0];
        self::assertNull($step->prerequisite, 'No record in the way means no deletion step.');
        self::assertSame('Add 1 DNS record — CNAME', $setup->headline);
    }

    #[Test]
    public function aHalfMigratedManagedDomainIsNotReportedAsFinished(): void
    {
        // Managed switched on, the customer's own TXT still published and still
        // naming us in `rua=`: every TXT-based check passes, so the surface used
        // to file report delivery under "done" and say nothing — while the
        // policy Sendvery hosts was not being served at all.
        $domain = $this->domain();
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;

        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Configured, rua: ProtocolState::Configured),
            latestByType: $this->checks([DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:'.self::REPORT_ADDRESS]),
            domain: $domain,
            managed: $this->managedAvailable(conflictingDmarcTxt: 'v=DMARC1; p=none; rua=mailto:'.self::REPORT_ADDRESS),
        );

        self::assertTrue($setup->hasOutstandingWork());
        self::assertSame(['delivery'], $this->keys($setup->actionRequired));
        self::assertNotContains('delivery', $this->keys($setup->done));
        self::assertNotNull($setup->actionRequired[0]->prerequisite);
    }

    #[Test]
    public function theCostOfSwitchingToTheManagedPathIsStatedBeforeTheSwitchNotAfter(): void
    {
        // A user with a working TXT record should learn that switching means
        // deleting it while they are still deciding — not on the next page load.
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Configured, rua: ProtocolState::Missing),
            latestByType: $this->checks([DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:other@example.com']),
            managed: $this->managedAvailable(),
        );

        [$selfTxt, $managedCname] = $setup->deliveryOptions;

        self::assertNotNull($managedCname->switchCaveat);
        self::assertStringContainsString('delete that TXT', $managedCname->switchCaveat);
        self::assertNull($selfTxt->switchCaveat, 'Already on the self-managed path — switching to it costs nothing.');
    }

    #[Test]
    public function switchingBackFromTheManagedPathNamesTheCnameThatHasToGo(): void
    {
        $domain = $this->domain();
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;

        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            domain: $domain,
            managed: $this->managedAvailable(),
        );

        [$selfTxt, $managedCname] = $setup->deliveryOptions;

        self::assertNotNull($selfTxt->switchCaveat);
        self::assertStringContainsString('CNAME', $selfTxt->switchCaveat);
        self::assertNull($managedCname->switchCaveat, 'Already on the managed path — nothing left to warn about.');
    }

    #[Test]
    public function aDomainWithNoDmarcRecordAtAllGetsNoSwitchingWarning(): void
    {
        $setup = $this->resolve(
            setupStatus: $this->statusFor(dmarc: ProtocolState::Missing, rua: ProtocolState::Missing),
            latestByType: $this->noChecks(),
            managed: $this->managedAvailable(),
        );

        self::assertNull($setup->deliveryOptions[1]->switchCaveat, 'Nothing published means nothing to delete.');
    }

    /**
     * @param array<value-of<DnsCheckType>, ?DnsCheckResult> $latestByType
     */
    private function resolve(
        DomainSetupStatus $setupStatus,
        array $latestByType,
        ?MonitoredDomain $domain = null,
        ?ManagedDeliveryContext $managed = null,
        int $ruaAddressCount = 0,
    ): GuidedDnsSetup {
        $resolver = new GuidedDnsSetupResolver(
            new DnsRecordRecommender(new ReportAddressProvider(self::REPORT_ADDRESS)),
            new ReportAddressProvider(self::REPORT_ADDRESS),
        );

        return $resolver->resolve(
            $domain ?? $this->domain(),
            $setupStatus,
            $latestByType,
            $managed ?? ManagedDeliveryContext::unavailable(),
            new RuaScenarioResult(RuaScenario::PointsAtExternal, 'a@one.example', null, $ruaAddressCount),
        );
    }

    private function managedAvailable(?string $conflictingDmarcTxt = null): ManagedDeliveryContext
    {
        return new ManagedDeliveryContext(
            dnsAutomationConfigured: true,
            managedAvailable: true,
            nextTier: null,
            cnameTarget: 'example.com._dmarc.sendvery.test',
            conflictingDmarcTxt: $conflictingDmarcTxt,
        );
    }

    /**
     * @param list<GuidedSetupStep> $steps
     *
     * @return list<string>
     */
    private function keys(array $steps): array
    {
        return array_map(static fn (GuidedSetupStep $step): string => $step->key, $steps);
    }

    private function domain(): MonitoredDomain
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Guided Team',
            slug: 'guided-team',
            createdAt: new \DateTimeImmutable('2026-07-01 09:00:00'),
        );
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable('2026-07-27 10:00:00'),
        );
        $domain->popEvents();

        return $domain;
    }

    /**
     * @return array<value-of<DnsCheckType>, null>
     */
    private function noChecks(): array
    {
        return [
            DnsCheckType::Spf->value => null,
            DnsCheckType::Dkim->value => null,
            DnsCheckType::Dmarc->value => null,
            DnsCheckType::Mx->value => null,
        ];
    }

    /**
     * @param array<value-of<DnsCheckType>, string> $rawRecords
     *
     * @return array<value-of<DnsCheckType>, ?DnsCheckResult>
     */
    private function checks(array $rawRecords): array
    {
        $domain = $this->domain();
        $checks = $this->noChecks();

        foreach ($rawRecords as $type => $rawRecord) {
            $check = new DnsCheckResult(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                type: DnsCheckType::from($type),
                checkedAt: new \DateTimeImmutable('2026-07-27 10:15:00'),
                rawRecord: $rawRecord,
                isValid: true,
                issues: [],
                details: [],
                previousRawRecord: null,
                hasChanged: false,
            );
            $check->popEvents();
            $checks[$type] = $check;
        }

        return $checks;
    }

    private function pendingStatus(): DomainSetupStatus
    {
        return new DomainSetupStatus(
            severity: DomainHealthFilter::Unverified,
            headline: 'irrelevant',
            ctaLabel: null,
            ctaRoute: null,
            ctaFragment: null,
            protocols: [],
            displayMode: DomainSetupDisplayMode::PanelOnly,
        );
    }

    private function statusFor(
        ProtocolState $spf = ProtocolState::Configured,
        ProtocolState $dkim = ProtocolState::Configured,
        ProtocolState $dmarc = ProtocolState::Configured,
        ProtocolState $mx = ProtocolState::Configured,
        ProtocolState $rua = ProtocolState::Configured,
    ): DomainSetupStatus {
        return new DomainSetupStatus(
            severity: DomainHealthFilter::Attention,
            headline: 'irrelevant',
            ctaLabel: null,
            ctaRoute: null,
            ctaFragment: null,
            protocols: [
                $this->row('SPF', $spf, 'health-spf', 'spf-record-guide'),
                $this->row('DKIM', $dkim, 'health-dkim', 'what-is-dkim'),
                $this->row('DMARC', $dmarc, 'health-dmarc', 'what-is-dmarc'),
                $this->row('MX', $mx, 'health-mx', 'mx-records-explained'),
                $this->row('RUA destination', $rua, 'health-dmarc', 'what-is-dmarc'),
            ],
            displayMode: DomainSetupDisplayMode::BannerAndPanel,
        );
    }

    private function row(string $name, ProtocolState $state, string $anchor, string $kbSlug): ProtocolSetupStatus
    {
        return new ProtocolSetupStatus(
            name: $name,
            state: $state,
            statusLine: sprintf('%s is %s', $name, $state->value),
            nextStep: ProtocolState::Configured === $state ? null : 'do the thing',
            kbSlug: ProtocolState::Configured === $state ? null : $kbSlug,
            healthAnchor: $anchor,
        );
    }
}
