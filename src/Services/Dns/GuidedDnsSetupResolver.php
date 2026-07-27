<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Results\Dns\DnsRecordRecommendation;
use App\Results\Dns\RuaScenarioResult;
use App\Results\DomainSetupStatus;
use App\Results\ProtocolSetupStatus;
use App\Services\ReportAddressProvider;
use App\Value\Dns\DmarcRuaInstruction;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\DnsRecordAction;
use App\Value\Dns\DnsRecordCategory;
use App\Value\Dns\GuidedDnsSetup;
use App\Value\Dns\GuidedSetupStep;
use App\Value\Dns\ManagedDeliveryContext;
use App\Value\Dns\ReportDeliveryOption;
use App\Value\Dns\SetupCaution;
use App\Value\Dns\SetupTier;
use App\Value\DnsCheckType;
use App\Value\DomainSetupDisplayMode;
use App\Value\ProtocolState;

/**
 * Assembles the ONE guided DNS setup surface both per-domain entry points
 * render — the domain detail page and `/app/domains/{id}/health`.
 *
 * Two responsibilities the existing pieces did not cover:
 *
 * 1. TIERING. It folds the flat five-row checklist into
 *    {@see SetupTier::ActionRequired} / {@see SetupTier::Later} /
 *    {@see SetupTier::Done}, with at most ONE step in the first tier. Four
 *    equally-red rows told the user nothing about ordering; one concrete "do
 *    this next" does.
 * 2. THE DELIVERY CHOICE. It always emits both report-delivery paths — the
 *    self-managed `_dmarc` TXT record and the managed CNAME — including when
 *    the team's plan cannot use the managed one, in which case the option is
 *    rendered with the reason rather than omitted.
 *
 * It owns no DNS knowledge of its own: record values come from
 * {@see DmarcRuaInstruction} and {@see DnsRecordRecommender}, per-protocol state
 * from {@see \App\Services\DomainSetupStatusResolver}, and everything about the
 * managed path from a {@see ManagedDeliveryContext} the caller resolves. That
 * keeps it a pure function of its inputs — which is what makes every branch of
 * the copy it owns cheap to pin down in tests. All copy lives here so the Twig
 * components stay props-only renderers.
 */
final readonly class GuidedDnsSetupResolver
{
    /**
     * A CNAME the customer never edits again can afford a short TTL — it makes
     * the "add it, we verify within minutes" loop feel immediate. Matches the
     * value the managed-DMARC card already advertises.
     */
    private const int MANAGED_CNAME_TTL = 300;

    private const int DEFAULT_TTL = 3600;

    /**
     * RFC 7489 §6.3 lets a receiver refuse to send reports to more than two
     * `rua=` addresses. Appending a third is not an error we can detect, it is a
     * silent loss — so it earns a warning rather than a block.
     */
    private const int RUA_PRACTICAL_ADDRESS_LIMIT = 2;

    /**
     * Order in which we hand out the single ActionRequired slot. Report delivery
     * comes first because nothing else on the product works without it: no
     * `rua=` pointing at us means no reports, no charts, no alerts. SPF before
     * DKIM because a missing SPF record is a one-line paste while a DKIM key
     * has to be generated at the sending platform. MX last — Sendvery does not
     * run the customer's inbound mail, so an MX finding is informational.
     */
    private const array STEP_PRIORITY = ['delivery', 'spf', 'dkim', 'mx'];

    public function __construct(
        private DnsRecordRecommender $recommender,
        private ReportAddressProvider $reportAddressProvider,
    ) {
    }

    /**
     * @param array<value-of<DnsCheckType>, ?DnsCheckResult> $latestByType the newest stored check per protocol
     */
    public function resolve(
        MonitoredDomain $domain,
        DomainSetupStatus $setupStatus,
        array $latestByType,
        ManagedDeliveryContext $managed,
        ?RuaScenarioResult $ruaScenario = null,
    ): GuidedDnsSetup {
        $deliveryOptions = $this->buildDeliveryOptions($domain, $managed);

        // "The resolver could not form a verdict yet" is exactly what
        // PanelOnly encodes, so reuse it rather than re-deriving pending-ness
        // from `$latestByType`: two independent definitions of "have we checked
        // this domain?" is how a page ends up with a green "monitoring active"
        // banner above an in-progress spinner. Every "you have no X record" line
        // would be a guess presented as fact in this window, so the surface
        // shows the in-progress state and nothing else.
        if (DomainSetupDisplayMode::PanelOnly === $setupStatus->displayMode) {
            return new GuidedDnsSetup(
                actionRequired: [],
                later: [],
                done: [],
                deliveryOptions: $deliveryOptions,
                checkInProgress: true,
                headline: 'Checking your DNS now',
                lede: sprintf(
                    'We queued the first DNS check for %s when you added it. This page updates itself the moment the results land — usually within a couple of minutes.',
                    $domain->domain,
                ),
            );
        }

        $rows = $this->indexRowsByName($setupStatus->protocols);
        $recommendations = $this->recommender->recommendForDomain($domain->domain, $latestByType);

        $steps = [
            'delivery' => $this->buildDeliveryStep(
                $domain,
                $rows,
                $latestByType,
                $managed,
                $ruaScenario->ruaAddressCount ?? 0,
            ),
            'spf' => $this->buildSimpleStep(
                key: 'spf',
                row: $rows['SPF'],
                defaultTitle: 'Publish an SPF record',
                doneTitle: 'SPF record is published',
                defaultWhy: 'SPF lists the servers allowed to send mail as your domain. Receivers use it to tell your mail apart from a forgery.',
                recommendation: $recommendations[DnsRecordCategory::Spf->value] ?? null,
                fallbackRecordType: 'TXT',
                recordName: '@',
                fallbackRecordFqdn: $domain->domain,
                currentValue: $latestByType[DnsCheckType::Spf->value]?->rawRecord,
            ),
            'dkim' => $this->buildSimpleStep(
                key: 'dkim',
                row: $rows['DKIM'],
                defaultTitle: 'Publish a DKIM key',
                doneTitle: 'DKIM key is published',
                defaultWhy: 'DKIM signs your outgoing mail so receivers can prove it was not altered in transit. The key comes from whatever platform sends your mail.',
                recommendation: $recommendations[DnsRecordCategory::Dkim->value] ?? null,
                fallbackRecordType: 'TXT',
                recordName: '<selector>._domainkey',
                fallbackRecordFqdn: '<selector>._domainkey.'.$domain->domain,
                currentValue: $latestByType[DnsCheckType::Dkim->value]?->rawRecord,
            ),
            'mx' => $this->buildSimpleStep(
                key: 'mx',
                row: $rows['MX'],
                defaultTitle: 'Check your MX records',
                doneTitle: 'MX records resolve',
                defaultWhy: 'MX records decide where mail TO your domain is delivered. Sendvery does not run your inbound mail, so we report what we see rather than handing you a value to paste.',
                recommendation: null,
                fallbackRecordType: 'MX',
                recordName: '@',
                fallbackRecordFqdn: $domain->domain,
                currentValue: $latestByType[DnsCheckType::Mx->value]?->rawRecord,
            ),
        ];

        return $this->tier($steps, $deliveryOptions, $domain->domain);
    }

    /**
     * Splits the steps into the three tiers and writes the surface headline.
     *
     * The ActionRequired slot goes to the highest-priority unfinished step, full
     * stop — not "the one we happen to have a literal value for". Otherwise a
     * domain whose only problem is a missing DKIM key (no computable value)
     * would show an empty "action required" tier and park the real work under
     * "waiting on you later", which is exactly the ambiguity this replaces.
     *
     * @param array<string, GuidedSetupStep> $steps           keyed by step key
     * @param list<ReportDeliveryOption>     $deliveryOptions
     */
    private function tier(array $steps, array $deliveryOptions, string $domainName): GuidedDnsSetup
    {
        $done = [];
        $unfinished = [];

        foreach (self::STEP_PRIORITY as $key) {
            $step = $steps[$key];
            if (ProtocolState::Configured === $step->state) {
                $done[] = $this->promote($step, SetupTier::Done);

                continue;
            }

            $unfinished[] = $step;
        }

        $lead = array_shift($unfinished);

        if (null === $lead) {
            return new GuidedDnsSetup(
                actionRequired: [],
                later: [],
                done: $done,
                deliveryOptions: $deliveryOptions,
                checkInProgress: false,
                headline: 'DNS setup complete',
                lede: sprintf(
                    'Report delivery, SPF, DKIM and MX all check out for %s. Nothing for you to do here — we keep watching and will tell you if anything changes.',
                    $domainName,
                ),
            );
        }

        $lead = $this->promote($lead, SetupTier::ActionRequired);
        $later = array_map(fn (GuidedSetupStep $step): GuidedSetupStep => $this->promote($step, SetupTier::Later), $unfinished);

        return new GuidedDnsSetup(
            actionRequired: [$lead],
            later: $later,
            done: $done,
            deliveryOptions: $deliveryOptions,
            checkInProgress: false,
            headline: $lead->hasCopyableRecord()
                ? sprintf('Add 1 DNS record — %s', $lead->recordType ?? 'TXT')
                : $lead->title,
            lede: $lead->hasCopyableRecord()
                ? 'Copy the value below into your DNS provider. We re-check automatically, and you can force a check any time.'
                : 'Here is what needs to happen next, and why.',
        );
    }

    private function promote(GuidedSetupStep $step, SetupTier $tier): GuidedSetupStep
    {
        return new GuidedSetupStep(
            key: $step->key,
            name: $step->name,
            title: $step->title,
            state: $step->state,
            tier: $tier,
            action: $step->action,
            statusLine: $step->statusLine,
            whyText: $step->whyText,
            recordType: $step->recordType,
            recordName: $step->recordName,
            recordFqdn: $step->recordFqdn,
            ttl: $step->ttl,
            currentValue: $step->currentValue,
            finalValue: $step->finalValue,
            kbSlug: $step->kbSlug,
            healthAnchor: $step->healthAnchor,
            offersDeliveryChoice: $step->offersDeliveryChoice,
            cautions: $step->cautions,
        );
    }

    /**
     * Report delivery: "is a DMARC record published?" and "does its rua= reach
     * Sendvery?" are one job from the user's point of view, so they are one
     * step. Splitting them (as the old five-row checklist did) invited the
     * misreading that two separate DNS records were needed.
     *
     * @param array<string, ProtocolSetupStatus>             $rows
     * @param array<value-of<DnsCheckType>, ?DnsCheckResult> $latestByType
     */
    private function buildDeliveryStep(
        MonitoredDomain $domain,
        array $rows,
        array $latestByType,
        ManagedDeliveryContext $managed,
        int $ruaAddressCount,
    ): GuidedSetupStep {
        $dmarcRow = $rows['DMARC'];
        $ruaRow = $rows['RUA destination'];

        $state = match (true) {
            ProtocolState::Configured !== $dmarcRow->state => $dmarcRow->state,
            default => $ruaRow->state,
        };
        $isDone = ProtocolState::Configured === $state;

        // Managed mode with an unverified CNAME: the record we want is the
        // CNAME, not a TXT record. Asking for a TXT record here would fight the
        // managed record the user already opted into (and DNS forbids both at
        // the same name anyway).
        $managedPending = !$isDone
            && DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode
            && null === $domain->cnameVerifiedAt;

        if ($managedPending) {
            return new GuidedSetupStep(
                key: 'delivery',
                name: 'DMARC reports',
                title: sprintf('Point _dmarc.%s at Sendvery with one CNAME', $domain->domain),
                state: $state,
                tier: SetupTier::Later,
                action: DnsRecordAction::AddNew,
                statusLine: "We can't see the CNAME yet — DNS changes can take a few minutes to show up.",
                whyText: 'This single record never changes. Sendvery hosts the DMARC policy behind it and advances you toward full enforcement, so you never edit DNS for DMARC again.',
                recordType: 'CNAME',
                recordName: '_dmarc',
                recordFqdn: '_dmarc.'.$domain->domain,
                ttl: self::MANAGED_CNAME_TTL,
                currentValue: null,
                finalValue: $managed->cnameTarget,
                kbSlug: 'what-is-dmarc',
                healthAnchor: 'health-dmarc',
                offersDeliveryChoice: true,
            );
        }

        $instruction = DmarcRuaInstruction::build(
            $latestByType[DnsCheckType::Dmarc->value]?->rawRecord,
            $this->reportAddressProvider->get(),
        );
        $hasRecord = null !== $instruction->currentRecord && '' !== $instruction->currentRecord;

        $title = match (true) {
            $isDone => 'DMARC reports are reaching Sendvery',
            $hasRecord => 'Add Sendvery to your DMARC record so reports reach us',
            default => 'Add one TXT record so Sendvery receives your DMARC reports',
        };

        return new GuidedSetupStep(
            key: 'delivery',
            name: 'DMARC reports',
            title: $title,
            state: $state,
            tier: SetupTier::Later,
            action: $isDone ? DnsRecordAction::NothingToDo : DnsRecordAction::forCurrentValue($instruction->currentRecord),
            statusLine: ProtocolState::Configured !== $dmarcRow->state ? $dmarcRow->statusLine : $ruaRow->statusLine,
            whyText: 'DMARC reports are how mail providers tell you who is sending email as your domain. This record points those reports at Sendvery so we can turn them into charts and alerts.',
            recordType: 'TXT',
            recordName: '_dmarc',
            recordFqdn: '_dmarc.'.$domain->domain,
            ttl: self::DEFAULT_TTL,
            currentValue: $instruction->currentRecord,
            finalValue: $isDone ? null : $instruction->finalRecord,
            kbSlug: $isDone ? null : 'what-is-dmarc',
            healthAnchor: 'health-dmarc',
            offersDeliveryChoice: !$isDone,
            cautions: $isDone || !$hasRecord ? [] : $this->extendCautions($ruaAddressCount, $managed),
        );
    }

    /**
     * Consequences of APPENDING Sendvery to an existing `rua=` list. They only
     * apply on that path: with no record to extend there is no list to overflow,
     * and once reports are arriving there is nothing left to warn about.
     *
     * @return list<SetupCaution>
     */
    private function extendCautions(int $ruaAddressCount, ManagedDeliveryContext $managed): array
    {
        $cautions = [];

        if ($ruaAddressCount >= self::RUA_PRACTICAL_ADDRESS_LIMIT) {
            $cautions[] = new SetupCaution(
                key: 'rua-address-limit-warning',
                text: sprintf(
                    'Your rua tag already has %d addresses. RFC 7489 lets receivers cap delivery to %d addresses — adding another may cause some ISPs to silently drop reports. Consider replacing one of your existing addresses with Sendvery\'s instead.',
                    $ruaAddressCount,
                    self::RUA_PRACTICAL_ADDRESS_LIMIT,
                ),
                tone: 'warning',
            );
        }

        $cautions[] = new SetupCaution(
            key: 'rua-authorization-warning',
            text: $managed->dnsAutomationConfigured
                ? 'Authorization records are published automatically when you add a domain, so nothing extra is needed from you here.'
                : "For Sendvery to receive reports, an authorization TXT record is needed on Sendvery's DNS for your domain. Check the authorization status card below.",
            tone: 'base-content/60',
        );

        return $cautions;
    }

    /**
     * SPF / DKIM / MX all share one shape: take the per-protocol verdict from
     * the setup resolver, then overlay the recommender's concrete record when it
     * has one. The overlay is a null-coalesce rather than a branch so a category
     * the recommender stays silent about still renders a sensible step.
     */
    private function buildSimpleStep(
        string $key,
        ProtocolSetupStatus $row,
        string $defaultTitle,
        string $doneTitle,
        string $defaultWhy,
        ?DnsRecordRecommendation $recommendation,
        string $fallbackRecordType,
        string $recordName,
        string $fallbackRecordFqdn,
        ?string $currentValue,
    ): GuidedSetupStep {
        $isDone = ProtocolState::Configured === $row->state;

        return new GuidedSetupStep(
            key: $key,
            name: $row->name,
            title: $isDone ? $doneTitle : ($recommendation->whatText ?? $defaultTitle),
            state: $row->state,
            tier: SetupTier::Later,
            action: $isDone ? DnsRecordAction::NothingToDo : DnsRecordAction::forCurrentValue($currentValue),
            statusLine: $row->statusLine,
            whyText: $recommendation->whyText ?? $defaultWhy,
            // The NAME we show is the short label the user types at their
            // provider. Cloudflare and most registrars append the zone
            // automatically, so pasting the fully qualified name yields
            // `_dmarc.example.com.example.com` — the single most common DNS
            // setup mistake. The FQDN travels alongside it for the providers
            // that do want the whole name.
            recordType: $recommendation->recordType ?? $fallbackRecordType,
            recordName: $recordName,
            recordFqdn: $recommendation->recordHost ?? $fallbackRecordFqdn,
            ttl: self::DEFAULT_TTL,
            currentValue: $currentValue,
            finalValue: $isDone ? null : $recommendation?->recommendedValue,
            kbSlug: $row->kbSlug,
            healthAnchor: $row->healthAnchor,
        );
    }

    /**
     * @return list<ReportDeliveryOption>
     */
    private function buildDeliveryOptions(MonitoredDomain $domain, ManagedDeliveryContext $managed): array
    {
        $managedAvailable = $managed->managedAvailable;
        $isManaged = DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode;

        $unavailableReason = match (true) {
            $managedAvailable => null,
            !$managed->dnsAutomationConfigured => "This Sendvery installation has no DNS provider connected, so it can't host DMARC records for anyone. The TXT record works everywhere.",
            null !== $managed->nextTier => sprintf('Managed DMARC is part of %s and up.', ucfirst($managed->nextTier->value)),
            default => 'Managed DMARC is not included in your current plan.',
        };

        return [
            new ReportDeliveryOption(
                mode: DmarcSetupMode::SelfTxt,
                label: "I'll add a TXT record",
                summary: 'You publish one `_dmarc` TXT record and keep control of it. Works on every plan and every DNS provider.',
                selected: !$isManaged,
                available: true,
                isPremium: false,
                unavailableReason: null,
                upgradeRoute: null,
                switchRoute: $isManaged ? 'dashboard_domain_switch_to_self_txt' : null,
                switchLabel: 'Manage the record myself',
                csrfTokenId: 'domain_managed_to_self',
            ),
            new ReportDeliveryOption(
                mode: DmarcSetupMode::ManagedCname,
                label: 'Let Sendvery manage it',
                summary: 'Point `_dmarc` at us once with a CNAME. We host the policy and move you to full enforcement safely — you never edit DNS for DMARC again.',
                selected: $isManaged,
                available: $managedAvailable,
                isPremium: true,
                unavailableReason: $unavailableReason,
                upgradeRoute: !$managedAvailable && $managed->dnsAutomationConfigured ? 'pricing' : null,
                switchRoute: $managedAvailable && !$isManaged ? 'dashboard_domain_enable_managed_dmarc' : null,
                switchLabel: 'Let Sendvery manage DMARC',
                csrfTokenId: 'domain_managed_enable',
            ),
        ];
    }

    /**
     * @param list<ProtocolSetupStatus> $protocols
     *
     * @return array<string, ProtocolSetupStatus>
     */
    private function indexRowsByName(array $protocols): array
    {
        $rows = [];
        foreach ($protocols as $protocol) {
            $rows[$protocol->name] = $protocol;
        }

        return $rows;
    }
}
