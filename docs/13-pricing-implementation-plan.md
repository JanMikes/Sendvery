# Pricing Implementation Plan — Free / Personal / Pro / Business + AI

**Last updated:** 2026-05-22
**Related:** `docs/05-monetization.md` (canonical pricing), `docs/07-decisions-log.md` (DEC-052..DEC-057), `docs/12-fake-door-stripe.md` (cutover runbook).

This document is the build plan for executing the pricing-model decisions made on 2026-05-22. It assumes the current state described in `docs/12-fake-door-stripe.md` (fake-door + Stripe code intact for old 2-tier model). The plan is **phased, each phase shippable independently** so we can pause/rearrange without leaving the codebase broken.

---

## Status

> When a future session asks "what's left on the pricing work?", read this section first.

**Last touched:** 2026-05-22.

| Phase | Status | Notes |
|---|---|---|
| 0 — Test specifications | ✅ done | Tests embedded inside each Phase 1 step rather than landing separately. |
| 1 — Foundations | ✅ done | 2026-05-22. SubscriptionPlan expanded, BillingInterval added, PlanLimits is canonical matrix, StripePriceResolver takes `(plan, interval)` with AI gating, PlanEnforcement tracks monthly counters via team_usage + team_ai_usage. Test bootstrap also creates migration-only counter tables so integration tests see them. |
| 2 — AI stub infrastructure | ✅ done | 2026-05-22. `App\Services\Ai\AiInsightsService` interface + 5 result DTOs + `StubAiInsightsService` (honest placeholder copy) + `PlanGatedAiInsightsService` decorator (plan + on-demand quota gating, increment on explainReport success). `AiNotEnabledForPlan` + `AiQuotaExceeded` exceptions. `sendvery:usage:reset` console command + `PlanEnforcement::resetExpiredCounters()`. Bindings wired so the interface resolves to gated→stub; swap is one line when real AI ships. |
| 3 — Plan limit enforcement | ✅ done | 2026-05-22. Monthly report cap enforced at the central-inbox dispatch point (`ProcessReceivedReportEmailHandler` — over-cap reports go to quarantine with reason `PlanOverage`). `ProcessDmarcReportHandler` increments the counter on every parsed report. New `sendvery:dmarc:purge` command + `DmarcReportRepository::deleteOlderThanForTeam` honor per-team retention from `PlanLimits::getRetentionDays`. `SubscriptionPlan::nextTier()` helper drives upgrade-nudge copy in the AddDomain banner and the InviteTeammate flash. AI quota widget on billing page deferred to Phase 4's UI rewrite. |
| 4 — Pricing page UI rewrite | ☐ pending | New 4-card layout, two toggles, Stimulus controller, localStorage. Existing `PricingTable.html.twig` has placeholder "Business" card after rename — full rewrite still required. |
| 5 — Checkout & billing flow | ☐ pending | Webhook expansion for `customer.subscription.updated`, in-place plan/cadence/AI updates via `updateSubscription()`, billing settings page redesign. |
| 6 — Cutover | ☐ pending | No legacy data to migrate (see [[clean-slate-no-preexisting-stripe-subs]]). Stripe products + 12 price IDs in dashboard, flip CTAs, copy sweep, email leads. |
| 7 — Observability polish | ☐ pending | |

### What landed in Phase 3 (2026-05-22)

- `src/Value/SubscriptionPlan.php` — `nextTier(): ?self` helper. Maps Free→Personal, Personal→Pro (AI variants preserved), Pro→Business, etc. Returns null for Business / BusinessAi / Unlimited so callers can render an Enterprise contact-us nudge instead.
- `src/Value/Reports/QuarantineReason.php` — new `PlanOverage` case for over-cap reports.
- `src/MessageHandler/ProcessReceivedReportEmailHandler.php` — checks `PlanEnforcement::canParseReport` on every routed report; over-cap reports go to quarantine with reason `PlanOverage` instead of being parsed. Per `never-delete-user-data`, nothing is dropped.
- `src/MessageHandler/ProcessDmarcReportHandler.php` — increments `team_usage.reports_parsed_count` at the end of every successful parse, regardless of dispatch source. Manual imports and quarantine-releases also count.
- `src/Repository/DmarcReportRepository.php` — `deleteOlderThanForTeam(teamId, cutoff)` DQL DELETE for per-team retention.
- `src/Command/PurgeOldDmarcReportsCommand.php` — `sendvery:dmarc:purge` cron. Loops teams; reads `PlanLimits::getRetentionDays`; deletes parsed `DmarcReport` rows older than the cutoff. Skips teams on unlimited retention (Business, Unlimited).
- `templates/dashboard/domain_add.html.twig` — banner uses `nextTier` helper; falls back to "Contact us to discuss Enterprise" when there's no higher tier.
- `src/Controller/Team/InviteTeammateController.php` — cap flash references the specific next tier (or Enterprise contact for Business).
- `CLAUDE.md` — crontab list updated with `45 4 * * * sendvery:dmarc:purge`.
- Tests: SubscriptionPlanTest gains `nextTier` data provider; ProcessReceivedReportEmailHandlerTest gains a plan-overage case; ProcessDmarcReportHandlerTest asserts the counter increment; PurgeOldDmarcReportsCommandTest covers retention purge across plans. **1107 tests green** (up from 1096).

### What landed in Phase 2 (2026-05-22)

- `src/Services/Ai/AiInsightsService.php` — interface with 5 operations (digest, anomaly, on-demand explain, remediation, sender label).
- `src/Services/Ai/StubAiInsightsService.php` — canned placeholder copy ("AI Insights is being prepared — your account is ready").
- `src/Services/Ai/PlanGatedAiInsightsService.php` — decorator. `explainReport` enforces monthly quota + increments counter on success; other ops gated by `plan->hasAi()` only. Remediation + sender label pass through (caller controls dispatch).
- `src/Services/Ai/Result/*` — DTOs: `WeeklyDigestResult`, `AnomalyExplanationResult`, `OnDemandExplanationResult`, `RemediationResult`, `SenderLabelResult`, plus sub-DTOs `KeyMetric` and `SuggestedDnsRecord` (objects over arrays).
- `src/Services/Ai/Input/DnsCheckFailure.php` — minimal input DTO for remediation guidance.
- `src/Exceptions/AiNotEnabledForPlan.php` — carries the rejecting plan.
- `src/Exceptions/AiQuotaExceeded.php` — carries `used` and `limit` for accurate UI rendering.
- `src/Services/Stripe/PlanEnforcement.php` — new `resetExpiredCounters(): int` method (single UPDATE per table for expired rows).
- `src/Command/ResetMonthlyUsageCountersCommand.php` — `sendvery:usage:reset` cron. Idempotent; reports zero when nothing to reset.
- `config/services.php` — `AiInsightsService` aliased to `PlanGatedAiInsightsService` wrapping `StubAiInsightsService`. When real AI ships, only the `$inner` binding changes.
- Tests: 28 new tests covering stub canned data, decorator gating + quota burn, exceptions, result DTOs, and reset command behavior. **1096 tests green** (up from 1068).

### What landed in Phase 1 (2026-05-22)

- `src/Value/SubscriptionPlan.php` — 8 cases (Free, Personal, PersonalAi, Pro, ProAi, Business, BusinessAi, Unlimited) + helpers (`hasAi()`, `baseTier()`, `withAi()`, `withoutAi()`, `tierGroup()`).
- `src/Value/BillingInterval.php` — new enum with `stripeInterval()` helper.
- `migrations/Version20260524100000.php` — `team.billing_interval` column.
- `migrations/Version20260524200000.php` — `team_usage` + `team_ai_usage` tables.
- `src/Entity/Team.php` — `billingInterval` field + `getBillingInterval()` accessor.
- `src/Services/Stripe/PlanLimits.php` — canonical matrix: domains, seats, reports/mo, retention, AI quota, features (Free 100r/mo, Personal 1k, Pro 10k, Business 50k; Pro 3 seats; AI quotas 50/200/500).
- `src/Services/Stripe/StripePriceResolver.php` — `(plan, interval)` lookup mapping to 12 env vars, throws `AiNotYetPurchasable` (new exception class) when `SENDVERY_AI_PURCHASABLE=false` for AI variants.
- `src/Services/Stripe/PlanEnforcement.php` — added `canParseReport`, `incrementMonthlyReportCount`, `canUseOnDemandAi`, `incrementOnDemandAiUsage`, `getRemainingAiQuota`. Counters auto-reset per calendar month via `ensureCurrentPeriod()`.
- `src/Services/Stripe/SubscriptionManager.php` — `createCheckoutSession()` takes `BillingInterval`, persists interval in Stripe metadata, passes through to webhook.
- `src/Message/UpgradeTeamPlan.php` — added optional `billingInterval` field; handler persists it.
- `src/MessageHandler/DowngradeTeamPlanHandler.php` — clears `billingInterval` on downgrade.
- `src/Controller/Dashboard/UpgradePlanController.php` — accepts `?interval=monthly|annual`, broader `PURCHASABLE_PLANS` (all six paid + AI variants), catches `AiNotYetPurchasable` → redirects to `request_beta_access?interest=ai`.
- `src/Controller/RequestBetaAccessController.php` — dropdown shows only base tiers (AI variants captured via `interest=ai` query param).
- `config/services.php` — wires `$aiPurchasable` from `SENDVERY_AI_PURCHASABLE` env.
- `.env` + `.env.test` — added 12 `STRIPE_PRICE_*` vars + `SENDVERY_AI_PURCHASABLE` flag.
- Templates: `dashboard/billing.html.twig`, `dashboard/domain_add.html.twig`, `components/PricingTable.html.twig`, `team/settings.html.twig` — renamed Team→Business with stub prices (full Phase-4 UI is later).
- Tests updated to data-provider attributes (`#[DataProvider]`), all Team→Business renames applied across 6 test files; new `BillingIntervalTest`; `PlanLimitsTest` rewritten as pinned matrix; `StripePriceResolverTest` covers all 12 SKU combos + AI gating + env fallback + missing env error path.

### Known non-Phase-1 issues in working tree (pre-existing WIP)

These existed before Phase 1 and are unaffected by it — flag to the user if asked:

- `src/Results/DomainOverviewResult.php` — constructor expects `teamId` but `DomainOverviewResultTest` fixtures don't provide it. **2 unit test failures.**
- `src/Services/DashboardContext.php` — one phpstan error (`arrayValues.list`), one cs-fixer style nit (multi-line sprintf).
- Plus uncommitted in-progress files: `src/Controller/Team/SwitchActiveTeamController.php`, `src/Twig/TeamContextExtension.php`, multiple template/controller changes around team switching.

### Confirmed clarifications (2026-05-22)

- **No preexisting Stripe subscribers.** Phase 6.5 in the original plan ("Old plan mapping") is **not needed** — there's no data to migrate. The old `personal`/`team` enum values can be dropped cleanly. ([[clean-slate-no-preexisting-stripe-subs]])
- **Over-cap reports = quarantine, can be revisited later.** Auto-reprocessing on upgrade is a nice-to-have, not required. The user can see what was queued and decide. ([[never-delete-user-data]])
- **Plan downgrade with over-limit domains = freeze excess.** New `frozen_at` column on `monitored_domain`. User picks which to keep active. ([[never-delete-user-data]])
- **Refund policy = cancel-at-period-end, no self-serve refunds.** Manual via Stripe dashboard only when a customer reaches out. ([[refund-and-cancellation-policy]])
- **AI not purchasable at launch** — full plumbing ships, but `SENDVERY_AI_PURCHASABLE=false` gates the AI variants until real `AnthropicAiInsightsService` lands. ([[ai-stub-first-launch-posture]])

---

## Goals

1. Replace the existing 2-tier model (Personal/Team) with the **4-tier + AI variant** model from `docs/05-monetization.md`.
2. Build **AI gating, quota enforcement, and the full `App\Services\Ai` interface** — but ship with a **stub implementation** that returns canned/empty results. AI Insights variants are **not yet purchasable** at launch (DEC-057).
3. Build the **pricing page UX** (4 cards + AI toggle + billing-cadence toggle + Stimulus controller + localStorage persistence).
4. Build the **Stripe transition** from `/request-access` (fake door) to live Checkout, including new SKU catalog, webhook handling for AI/cadence switches, and dashboard self-serve flow.
5. Maintain **100% test coverage** throughout. Tests describe the new pricing requirements.

---

## Out of scope for this plan

- **Real AI implementation** (`AnthropicAiInsightsService`). Stubs ship; real impl is a separate later run that swaps one binding.
- **Domain extras** (per-domain Stripe quantity items). Deferred to Phase 2 per DEC-056.
- **Multi-currency** (USD only at launch).
- **Stripe Tax / EU OSS** (deferred until we cross the CZ VAT threshold).
- **Lifetime deals, coupon engine UI** (Stripe promo codes via dashboard are fine for manual issuance).

---

## Phase 0 — Read existing surface area & write the test specifications

Before writing code, codify the new requirements as failing tests. **Tests are the business specification (DEC-009).**

**Add tests describing:**
- `SubscriptionPlanTest` — every new case exists; `hasAi()`, `baseTier()`, `withAi()`, `withoutAi()`, `tierGroup()` behave correctly.
- `BillingIntervalTest` — enum exists with `Monthly`, `Annual`; mapping to Stripe `interval` strings.
- `PlanLimitsTest` — every (plan) → (max domains, max seats, max reports/mo, retention days, AI quota, features) tuple matches the matrix in `docs/05-monetization.md`. The TEST file IS the source of truth — if `docs/05-monetization.md` and `PlanLimitsTest` diverge, the test wins.
- `StripePriceResolverTest` — `(plan, interval)` → priceId lookup; rejects `Free`, `Unlimited`, and (when `SENDVERY_AI_PURCHASABLE=false`) the AI variants.
- `AiInsightsServiceTest` — interface contract is honored by the stub.
- `PlanGatedAiInsightsServiceTest` — gating throws on free / non-AI plans; quota counter increments + blocks at limit; reset cron resets counter.

This phase ends with **all new tests failing or unimplemented**, but the test file structure exists.

---

## Phase 1 — Foundations (no user-visible change)

**Goal:** the codebase understands the new plan/interval model. Old UI still routes through `/request-access`; no behavior changes for users.

### 1.1 Expand `SubscriptionPlan` enum

`src/Value/SubscriptionPlan.php`:

```php
enum SubscriptionPlan: string
{
    case Free = 'free';
    case Personal = 'personal';
    case PersonalAi = 'personal_ai';
    case Pro = 'pro';
    case ProAi = 'pro_ai';
    case Business = 'business';
    case BusinessAi = 'business_ai';
    case Unlimited = 'unlimited';

    public function hasAi(): bool
    {
        return match ($this) {
            self::PersonalAi, self::ProAi, self::BusinessAi, self::Unlimited => true,
            default => false,
        };
    }

    public function baseTier(): self
    {
        return match ($this) {
            self::PersonalAi => self::Personal,
            self::ProAi => self::Pro,
            self::BusinessAi => self::Business,
            default => $this,
        };
    }

    public function withAi(): self
    {
        return match ($this) {
            self::Personal => self::PersonalAi,
            self::Pro => self::ProAi,
            self::Business => self::BusinessAi,
            self::PersonalAi, self::ProAi, self::BusinessAi, self::Unlimited => $this,
            self::Free => throw new \LogicException('AI is not available on the Free tier — direct user to the contact form.'),
        };
    }

    public function withoutAi(): self { /* inverse of withAi() */ }

    public function tierGroup(): string
    {
        return match ($this) {
            self::Free => 'free',
            self::Personal, self::PersonalAi => 'personal',
            self::Pro, self::ProAi => 'pro',
            self::Business, self::BusinessAi => 'business',
            self::Unlimited => 'unlimited',
        };
    }
}
```

### 1.2 Add `BillingInterval` enum

`src/Value/BillingInterval.php`:

```php
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function stripeInterval(): string
    {
        return match ($this) {
            self::Monthly => 'month',
            self::Annual => 'year',
        };
    }
}
```

### 1.3 Migration: add `team.billing_interval`

Doctrine migration: add nullable `billing_interval VARCHAR(20) NULL` to `team`. Backfill existing Free/Unlimited teams to `NULL`; existing Personal/Team-on-old-model teams: leave NULL (they'll get one on next renewal — `null` means "not yet billed via Stripe").

Add to `Team` entity:

```php
#[ORM\Column(length: 20, nullable: true)]
public ?string $billingInterval = null;

public function getBillingInterval(): ?BillingInterval
{
    return $this->billingInterval !== null ? BillingInterval::from($this->billingInterval) : null;
}
```

### 1.4 Migration: add AI usage tracking

Doctrine migration: add table `team_ai_usage`:

```sql
CREATE TABLE team_ai_usage (
    team_id UUID PRIMARY KEY REFERENCES team(id) ON DELETE CASCADE,
    on_demand_count INT NOT NULL DEFAULT 0,
    period_started_at TIMESTAMP WITH TIME ZONE NOT NULL,
    period_ends_at TIMESTAMP WITH TIME ZONE NOT NULL
);
```

One row per team. The cron reset job touches one row per team per month. We could put this on `team` directly, but a separate table cleanly isolates the usage concern and makes the "reset" event a single UPDATE — easier to log and audit.

### 1.5 Update `PlanLimits` (now the canonical source of plan rules)

`src/Services/Stripe/PlanLimits.php` — expand:

```php
final readonly class PlanLimits
{
    public function getMaxDomains(SubscriptionPlan $plan): int { /* per matrix */ }
    public function getMaxTeamMembers(SubscriptionPlan $plan): int { /* per matrix */ }
    public function getMaxReportsPerMonth(SubscriptionPlan $plan): int { /* per matrix */ } // NEW
    public function getRetentionDays(SubscriptionPlan $plan): ?int { /* null=unlimited */ } // NEW
    public function getOnDemandAiQuota(SubscriptionPlan $plan): int { /* 0 if no AI */ } // NEW
    public function hasFeature(SubscriptionPlan $plan, string $feature): bool { /* updated */ }
}
```

Updated `hasFeature` matrix:
| feature | Free | Personal | PersonalAi | Pro | ProAi | Business | BusinessAi |
|---|---|---|---|---|---|---|---|
| `dns_monitoring` | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `alerts` | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `digest` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `blacklist_monitoring` | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `sender_inventory` | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `pdf_export` | – | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `ai_insights` | – | – | ✓ | – | ✓ | – | ✓ |
| `api_access` | – | – | – | ✓ | ✓ | ✓ | ✓ |
| `white_label_pdf` | – | – | – | – | – | ✓ | ✓ |

`Unlimited` short-circuits to `true` for everything (existing behavior).

### 1.6 Update `StripePriceResolver`

```php
public function getPriceId(SubscriptionPlan $plan, BillingInterval $interval): string
```

Map all 12 (plan, interval) tuples to env vars. Throw `LogicException` on `Free` (no price) and `Unlimited` (internal). Throw a tagged `AiNotYetPurchasableException` on AI variants when `SENDVERY_AI_PURCHASABLE=false` — the UpgradePlanController catches it and redirects to the AI-curious lead capture (DEC-057).

### 1.7 Update `PlanEnforcement`

Add methods:

```php
public function canParseReport(string $teamId, SubscriptionPlan $plan): bool
public function getMonthlyReportCount(string $teamId): int
public function incrementMonthlyReportCount(string $teamId): void
public function canUseOnDemandAi(string $teamId, SubscriptionPlan $plan): bool
public function incrementOnDemandAiUsage(string $teamId): void
public function getRemainingAiQuota(string $teamId, SubscriptionPlan $plan): int
```

Counters live in `team_ai_usage` (AI) and either a new `team_monthly_reports` column or a `team_usage` table (reports). The implementation uses a separate `team_usage` table mirroring `team_ai_usage` for consistency.

### 1.8 Tests

All Phase 0 tests now pass.

---

## Phase 2 — AI stub infrastructure

**Goal:** the full AI interface exists; gating works; quota enforces; everything is stubbed.

### 2.1 Interface and result DTOs

`src/Services/Ai/AiInsightsService.php`:

```php
interface AiInsightsService
{
    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult;
    public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult;
    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult;
    public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult;
    public function labelSender(string $ip, string $domain): SenderLabelResult;
}
```

Each return type is a `readonly final class` DTO in `src/Services/Ai/Result/` (per project conventions — DTOs live under their feature, not in a generic `Dto` directory):

- `WeeklyDigestResult { public string $summaryMarkdown; public array $keyMetrics; public array $recommendations; }`
- `AnomalyExplanationResult { public string $explanation; public string $severity; public string $recommendedAction; }`
- `OnDemandExplanationResult { public string $explanation; }`
- `RemediationResult { public string $instructionsMarkdown; public array $suggestedDnsRecords; }`
- `SenderLabelResult { public string $label; public float $confidence; }`

### 2.2 Stub implementation

`src/Services/Ai/StubAiInsightsService.php`:

```php
final readonly class StubAiInsightsService implements AiInsightsService
{
    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult
    {
        return new WeeklyDigestResult(
            summaryMarkdown: "**AI Insights is being prepared.** Your account is ready — once we flip the switch, you'll see a plain-English summary of your week's deliverability here.",
            keyMetrics: [],
            recommendations: [],
        );
    }
    // ... similar canned responses for the other methods
}
```

Stubs return real-looking-but-honest placeholder content. The dashboard can be built and tested against these.

### 2.3 Plan-gated decorator

`src/Services/Ai/PlanGatedAiInsightsService.php`:

```php
final readonly class PlanGatedAiInsightsService implements AiInsightsService
{
    public function __construct(
        private AiInsightsService $inner,
        private TeamRepository $teams,
        private PlanEnforcement $enforcement,
        private PlanLimits $limits,
    ) {}

    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult
    {
        $team = $this->teams->get($teamId->toString());
        if (!$team->getSubscriptionPlan()->hasAi()) {
            throw new AiNotEnabledForPlan($team->getSubscriptionPlan());
        }
        if (!$this->enforcement->canUseOnDemandAi($teamId->toString(), $team->getSubscriptionPlan())) {
            throw new AiQuotaExceeded(
                used: $this->enforcement->getMonthlyReportCount($teamId->toString()),
                limit: $this->limits->getOnDemandAiQuota($team->getSubscriptionPlan()),
            );
        }
        $result = $this->inner->explainReport($reportId, $teamId);
        $this->enforcement->incrementOnDemandAiUsage($teamId->toString());
        return $result;
    }

    // Other methods: similar gating, no quota increment (they're "free" features within AI plan).
}
```

Wire via `config/services.php` so `AiInsightsService` interface resolves to `PlanGatedAiInsightsService` wrapping `StubAiInsightsService`. When real AI ships, only the inner binding changes.

### 2.4 Exceptions

`src/Exceptions/AiNotEnabledForPlan.php` and `src/Exceptions/AiQuotaExceeded.php` — domain exceptions with structured data so the UI can render upgrade nudges or quota-used banners.

### 2.5 Usage reset cron

`src/Command/ResetMonthlyUsageCounters.php` — Symfony Console command. Loops teams, resets `team_ai_usage` and `team_usage` counters at start of new calendar month (compared via `period_ends_at < now`).

Add crontab entry in `~/www/spare.srv/deployment/crontab`:
```
0 0 * * * cd /opt/sendvery && docker compose run --rm worker bin/console sendvery:usage:reset
```

Wrap in `sentry-cli monitors run` for missed-run paging (project convention).

### 2.6 Tests

- Stub returns canned data.
- Decorator throws on no-AI plan.
- Decorator throws on over-quota.
- Decorator increments counter on success.
- Reset command resets correctly.
- `AiQuotaExceeded` carries usage/limit data.

---

## Phase 3 — Plan limit enforcement & retention

**Goal:** plan limits actively enforce — domain caps, monthly report caps, retention, AI quotas. UI shows informative limit-reached messages.

### 3.1 Domain count enforcement

Already exists in `PlanEnforcement::canAddDomain()`. Update the `AddDomainController` to use the **next-tier** plan in the "Domain limit reached" banner (Personal → Pro → Business → Enterprise contact).

### 3.2 Seat count enforcement

Already exists in `canAddTeamMember()`. Update the team invite UI similarly.

### 3.3 Monthly report cap

In `ProcessDmarcReportHandler` (or the central inbox parser), before parsing, check `canParseReport(teamId, plan)`. If over cap:
- Persist the raw envelope in a "quarantine for plan overage" table (similar to existing `QuarantinedDmarcReport` pattern). Per [[never-delete-user-data]], we never drop reports — they're queued and visible.
- Email the team owner: "You've hit your monthly report cap (X reports). N reports are queued — upgrade to unlock them."
- The user can see what's in quarantine and revisit on upgrade. **Auto-reprocessing on upgrade is a nice-to-have, not required** — making the queue visible is enough. If we add reprocessing later, the existing `sendvery:reports:reprocess` infrastructure can take an `--over-quota-only` flag.

This is less harsh than dropping reports and gives a natural upgrade trigger.

### 3.4 Retention enforcement

Update `src/Command/PurgeReportsCommand.php` (the `sendvery:reports:purge` cron) to read `PlanLimits::getRetentionDays(team.plan)` per team and delete older reports accordingly. Null = unlimited = no deletion.

### 3.5 AI quota enforcement

Already wired via Phase 2 decorator. UI surfaces:
- Dashboard widget: "AI: 47 of 200 explanations used this month".
- Quota-exceeded modal: "You've used all 200 AI explanations this month. They reset on YYYY-MM-DD. Upgrade to Business for 500/mo →"

### 3.6 Usage warning emails

A daily cron command checks teams that crossed 80% of any limit in the last 24h and sends a one-time warning email per limit per month. Avoid spam — gate on `last_warning_sent_at`.

---

## Phase 4 — Pricing-page UI rewrite

**Goal:** new 4-card layout with two toggles, Stimulus controller, localStorage persistence.

### 4.1 Stimulus controller

`assets/controllers/pricing_controller.js`:

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['card', 'price', 'strikethrough', 'billingNote',
                      'savingsChip', 'aiFeature', 'cta', 'aiToggle', 'billingButton'];
    static values = { billing: { type: String, default: 'annual' },
                       ai: { type: Boolean, default: false } };

    connect() {
        const stored = JSON.parse(localStorage.getItem('sendvery_pricing') || '{}');
        if (stored.billing) this.billingValue = stored.billing;
        if (stored.ai !== undefined) this.aiValue = stored.ai;
        this.updateAll();
    }

    setBilling(event) {
        this.billingValue = event.currentTarget.dataset.billing;
        this.persist();
        this.updateAll();
    }

    toggleAi(event) {
        this.aiValue = event.currentTarget.checked;
        this.persist();
        this.updateAll();
    }

    persist() {
        localStorage.setItem('sendvery_pricing',
            JSON.stringify({ billing: this.billingValue, ai: this.aiValue }));
    }

    updateAll() {
        this.cardTargets.forEach(card => this.updateCard(card));
        this.billingButtonTargets.forEach(btn => {
            btn.classList.toggle('btn-active', btn.dataset.billing === this.billingValue);
        });
    }

    updateCard(card) {
        const annual = this.billingValue === 'annual';
        const ai = this.aiValue;
        const key = (ai ? 'ai-' : '') + this.billingValue; // e.g. 'ai-annual'
        const price = card.dataset[`price${this.capitalize(key)}`];
        // ... update price, strikethrough, CTA href, AI feature visibility
    }
}
```

### 4.2 New `PricingTable.html.twig`

Card data attributes carry all the prices, savings, and AI quotas. The Stimulus controller does the swap. Server-rendered fallback (no JS) defaults to **annual + no AI**.

```twig
<div data-controller="pricing"
     data-pricing-billing-value="annual"
     data-pricing-ai-value="false">

    {# Toggles #}
    <div class="flex flex-col md:flex-row gap-4 items-center justify-center mb-8">
        <div class="join">
            <button data-pricing-target="billingButton"
                    data-action="pricing#setBilling"
                    data-billing="monthly"
                    class="btn btn-sm join-item">Monthly</button>
            <button data-pricing-target="billingButton"
                    data-action="pricing#setBilling"
                    data-billing="annual"
                    class="btn btn-sm join-item btn-active">
                Annual <span class="badge badge-success badge-xs ml-2">−2 months</span>
            </button>
        </div>
        <label class="label gap-2 cursor-pointer">
            <input type="checkbox"
                   data-pricing-target="aiToggle"
                   data-action="pricing#toggleAi"
                   class="toggle toggle-sm toggle-primary">
            <span>✨ Add AI Insights</span>
        </label>
    </div>

    {# Cards #}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-6xl mx-auto">
        {# Free, Personal, Pro, Business — each with data-* price attributes #}
    </div>

    {# Enterprise line #}
    <div class="text-center mt-8 text-base-content/60">
        Need more? <a href="{{ path('request_beta_access', {plan: 'enterprise'}) }}" class="link link-primary">Talk to us →</a>
    </div>
</div>
```

### 4.3 Card markup (Personal example)

```twig
<div class="card bg-base-200/50 border-2 border-primary shadow-lg relative"
     data-pricing-target="card"
     data-base-plan="personal"
     data-price-monthly="5.99"
     data-price-annual="4.99"
     data-strike-monthly=""
     data-strike-annual="5.99"
     data-price-ai-monthly="9.99"
     data-price-ai-annual="8.99"
     data-strike-ai-monthly=""
     data-strike-ai-annual="9.99"
     data-yearly-annual="59.88"
     data-yearly-ai-annual="107.88"
     data-savings-annual="12"
     data-savings-ai-annual="12">
    <div class="card-body">
        <h3 class="card-title text-lg">Personal</h3>
        <div class="mt-2">
            <span class="text-3xl font-bold" data-pricing-target="price">$4.99</span>
            <span class="text-base-content/50 text-sm">/mo</span>
            <span class="line-through text-base-content/40 text-sm ml-2" data-pricing-target="strikethrough">$5.99</span>
        </div>
        <p class="text-xs text-base-content/50" data-pricing-target="billingNote">Billed annually at $59.88</p>
        <div class="badge badge-success badge-sm mt-1" data-pricing-target="savingsChip">Save $12/yr</div>
        <ul class="mt-4 space-y-2 text-sm">
            <li>5 domains</li>
            <li>1,000 reports/mo</li>
            <li>1 seat</li>
            <li>1-year retention</li>
            <li>Blacklist monitoring</li>
            <li data-pricing-target="aiFeature" hidden>
                ✨ AI Insights · 50 on-demand/mo
            </li>
        </ul>
        <div class="card-actions mt-6">
            <a data-pricing-target="cta"
               href="{{ path('dashboard_billing_upgrade', {plan: 'personal', interval: 'annual'}) }}"
               class="btn btn-primary btn-sm w-full">Get Personal</a>
        </div>
    </div>
</div>
```

### 4.4 Free card AI invitation

When AI is toggled on, the Free card's CTA swaps to "Curious about AI? Tell us →" pointing to `request_beta_access` with `interestType=ai_curious`.

### 4.5 Tests

- Render pricing page, assert all 4 cards present.
- Assert annual is default visually (no JS in test = server-rendered default state).
- Twig regression test that data attributes carry the right values per tier.
- Stimulus controller — if there's a JS test harness; otherwise smoke-test via Symfony Panther E2E.

---

## Phase 5 — Checkout & billing flow

**Goal:** users can buy plans (when `SENDVERY_AI_PURCHASABLE=true`) via Stripe Checkout; can change plan, change cadence, toggle AI, cancel via dashboard.

### 5.1 `SubscriptionManager` updates

```php
public function createCheckoutSession(
    Team $team,
    SubscriptionPlan $plan,
    BillingInterval $interval,
): string
```

- Pass `'price' => $this->priceResolver->getPriceId($plan, $interval)`.
- Add `interval` to metadata so webhook can persist.
- Reject `Free` (no Stripe), `Unlimited` (internal), and `*Ai` (when `SENDVERY_AI_PURCHASABLE=false`).

Add `updateSubscription(Team $team, SubscriptionPlan $newPlan, BillingInterval $newInterval): void` for in-place plan/AI/cadence changes. Uses Stripe's `subscriptions.update` with `proration_behavior: 'create_prorations'`.

### 5.2 Webhook expansion

`StripeWebhookController` already handles `checkout.session.completed` and `customer.subscription.deleted`. Add:

- `customer.subscription.updated` → dispatch `UpgradeTeamPlan` (or new `ChangeTeamPlan`) when plan/interval changes. Detect change by comparing the new `items[0].price.id` to the resolver's mapping.
- `invoice.payment_failed` → mark team's subscription as `past_due`; after 7 days of failed payments, auto-downgrade to Free.
- `customer.subscription.trial_will_end` (future trial support) — log only for now.

### 5.3 `UpgradePlanController` rewrite

```php
#[Route('/app/settings/billing/upgrade/{plan}', name: 'dashboard_billing_upgrade', methods: ['GET'])]
public function __invoke(string $plan, Request $request): Response
{
    $interval = BillingInterval::tryFrom($request->query->get('interval', 'annual'))
        ?? BillingInterval::Annual;
    $targetPlan = SubscriptionPlan::tryFrom($plan);

    if ($targetPlan === null || in_array($targetPlan, [SubscriptionPlan::Free, SubscriptionPlan::Unlimited], true)) {
        // ... error
    }

    if ($targetPlan->hasAi() && !$this->aiPurchasable) {
        // DEC-057: redirect to AI-curious lead form
        return $this->redirectToRoute('request_beta_access', [
            'plan' => $targetPlan->baseTier()->value,
            'interest' => 'ai',
        ]);
    }

    $team = $this->teamRepository->get($this->dashboardContext->getTeamId());

    // If team already has a Stripe subscription, update in place
    if ($team->stripeSubscriptionId !== null) {
        $this->subscriptionManager->updateSubscription($team, $targetPlan, $interval);
        return $this->redirectToRoute('dashboard_billing', ['updated' => 1]);
    }

    // First subscription — new Checkout session
    $url = $this->subscriptionManager->createCheckoutSession($team, $targetPlan, $interval);
    return $this->redirect($url);
}
```

`$aiPurchasable` is bound from `%env(bool:SENDVERY_AI_PURCHASABLE)%` via `config/services.php`.

### 5.4 Billing settings page

`templates/dashboard/billing.html.twig` rewrite:
- Current plan card (plan name, interval, next renewal date, price).
- AI Insights status (on / off / not available on this tier).
- AI quota progress bar (X of Y used this month).
- "Change plan" button → opens modal with `<PricingTable>` component embedded (or links to `/pricing` with a `change_plan_for_team=X` query param).
- "Switch to annual" / "Switch to monthly" button — one click, calls `updateSubscription` with same plan, different interval.
- "Add AI Insights" toggle (or "Remove AI Insights") — same.
- "Manage payment method / invoices" → Stripe Customer Portal (existing).
- "Cancel subscription" → Stripe Customer Portal (existing).

### 5.5 Tests

- New Checkout session creation per (plan, interval).
- Webhook updates plan correctly on `customer.subscription.updated`.
- `updateSubscription` handles plan up/down + interval flip + AI toggle.
- AI variant rejected when `SENDVERY_AI_PURCHASABLE=false`.
- Billing page renders all the right info per plan state.

---

## Phase 6 — Cutover

**Goal:** flip live traffic from `/request-access` to Stripe Checkout. Existing beta-access leads notified.

### 6.1 Stripe production setup

Per `docs/12-fake-door-stripe.md` Steps 1–2: create products + prices, configure env, register webhook endpoint.

### 6.2 Flip pricing-page CTAs

Update `PricingTable.html.twig` (no more `request_beta_access` for paid tiers — see DEC-057 for the AI exception).

### 6.3 Marketing-copy sweep

Grep for old prices, update everywhere:

```bash
grep -rn '5\.99\|49\.99\|\$3\.99' templates/ README.md docs/01-vision-and-problem.md
```

Locations to update:
- `templates/marketing/*` hero, features, comparison
- `templates/learn/*` knowledge-base CTAs
- `templates/about/open_source.html.twig`
- `README.md`
- SEO meta descriptions in marketing layouts

### 6.4 Email existing beta leads

One-shot Symfony Console command:

```bash
docker compose exec app bin/console sendvery:beta-leads:launch-announce \
    --since=2026-05-14 \
    --coupon=LAUNCH20
```

Pulls `BetaAccessRequest` rows, sends a templated email with magic-link + optional Stripe promo code.

### 6.5 Old plan mapping (existing internal users)

**Not needed — clean slate.** Confirmed 2026-05-22: there are zero existing Stripe subscribers (see memory `clean-slate-no-preexisting-stripe-subs`). The old `personal`/`team` enum values can be removed entirely once the new code is deployed. If `Team.plan = 'team'` appears anywhere in dev/staging, that's test data — wipe and reseed.

### 6.6 Remove old fake-door artifacts

- Delete the "private beta" banner copy.
- Keep `/request-access` route alive but renamed/repurposed for Enterprise + AI-curious leads.
- Update its form's `interestType` dropdown to include `enterprise`, `ai_curious`, `self_hosted_support`.

### 6.7 Smoke tests in production

Stripe test-mode end-to-end via Stripe CLI; then a real $1 charge on a personal card to validate full flow.

---

## Phase 7 — Observability & polish

**Goal:** see what's happening, react to issues.

- Sentry breadcrumbs on all plan/quota events (plan change, AI quota exceeded, report cap hit, webhook received).
- Daily admin email: subscription health (new subs, churned, MRR, failed payments).
- Dashboard for active subscriptions (admin-only route).
- "Approaching limit" emails (80% triggers).
- Stripe failed-payment dunning emails (Stripe handles natively).
- AI cost monitoring per team — daily report of Anthropic spend (when real AI ships).

---

## Cross-cutting concerns & gotchas

This is the ultrathink section — everything I worried about while planning.

### Plan downgrades with over-limit state

If a user on Pro has 15 domains and downgrades to Personal (5 domain cap), what happens?

**Decision:** allow the downgrade, but **freeze** the excess domains in a "plan_downgraded — readonly" state. The team can see their data but can't add new reports for the frozen domains, and the dashboard shows a banner: "You have 10 domains over your Personal plan limit. Choose 5 to keep active, or upgrade to Pro."

Implementation: nullable `frozen_at` column on `monitored_domain`. Repository queries default to excluding frozen. A new domain-selection UI surfaces post-downgrade.

### Stripe subscription state vs. our `team.plan` state

These can diverge if a webhook is missed or fails. Implement a **nightly reconciliation cron**: `sendvery:billing:reconcile` queries Stripe for all subscriptions, compares to `team.plan` + `team.billing_interval`, alerts Sentry on mismatch.

### Failed-payment grace period

Stripe `invoice.payment_failed` → mark team subscription `past_due` (new column or status enum). Stripe retries 4 times over 3 weeks (configurable in Stripe dashboard). If all fail, `customer.subscription.deleted` fires → downgrade to Free. We email at: first failure, third failure, downgrade.

Grace-period behavior: keep all paid features active for first 7 days (don't punish for a card glitch). After day 7, restrict to Free-level features but keep data.

### AI variant purchasing when AI is not ready (DEC-057)

`SENDVERY_AI_PURCHASABLE=false` is the gate. Three places need it:
1. `StripePriceResolver` — refuse to return AI price IDs (throws).
2. `UpgradePlanController` — redirects AI requests to lead capture.
3. `PricingTable.html.twig` — CTA on AI-toggled cards points to lead capture, not checkout.

When real AI ships:
1. Set `SENDVERY_AI_PURCHASABLE=true`.
2. Swap the inner binding in `config/services.php` from `StubAiInsightsService` → `AnthropicAiInsightsService`.
3. Email the AI-curious lead list announcing availability.

### Pre-existing Stripe customers on the old model

If anyone subscribed via the old Stripe code before fake-door (unlikely but check), their subscriptions reference deprecated price IDs. The reconciliation cron flags these. Resolution: manual reach-out + migrate to closest new tier.

### Currency

USD only. Stripe will display prices in USD; some EU customers may see card-network conversion. This is fine for launch — note in FAQ.

### Tax (Stripe Tax)

OFF at launch (Jan is OSVČ, below CZ VAT threshold). Sticker prices are inclusive. Toggle Stripe Tax on later when threshold crossed; no code changes needed (Stripe Tax is purely a dashboard setting).

### Annual subscription cancellation

User on annual cancels mid-cycle. Stripe default: subscription continues until end of paid period, no refund. We mirror this: dashboard shows "Cancels on YYYY-MM-DD". Allow "undo cancel" until that date.

If user requests a prorated refund, that's a manual operation in the Stripe dashboard (no self-serve refund — keeps abuse vectors closed).

### Switching from annual to monthly mid-cycle

This is a Stripe `subscriptions.update` with proration. Result: user gets a credit for unused annual time, gets billed monthly going forward. We display a clear preview: "You'll receive a $XX.XX credit toward future months. Continue?"

### Switching from monthly to annual mid-cycle

Stripe charges the prorated annual amount immediately, credits current month's unused portion. Big up-front charge — show explicit confirmation: "You'll be charged $XX.XX now to cover the next 12 months. Continue?"

### AI quota reset edge cases

- New subscription mid-month: quota starts at full (50/200/500) for the partial month. Generous, but the cost is bounded by the monthly cap anyway.
- Plan upgrade mid-month (Pro → Business with AI): quota jumps to higher cap immediately; existing usage carries over (200 used → still 200 used, now out of 500).
- Plan downgrade mid-month: quota stays at usage if usage > new cap; user can't make new calls until reset. UI explains.

### Multi-team users

A user can belong to multiple teams. Each team has its own subscription + plan + quotas. The pricing page is for the **current dashboard team** — we pass `team_id` through Checkout metadata. No surprises here, already supported by `DashboardContext`.

### Self-hosted users and AI

AGPL self-hosters can run the full app for free. AI requires their own Anthropic API key. Add to `App\Services\Ai`: when `SENDVERY_SELF_HOSTED=true` AND `ANTHROPIC_API_KEY` is set, all teams get AI access regardless of plan. Document in `README.md`.

### Doctrine SQL filter for team scoping

Already in place per project conventions. New `team_usage` and `team_ai_usage` tables need the filter applied so cross-tenant leaks are impossible.

### Test data fixtures

`tests/Fixtures/` needs new team fixtures: one per (plan, interval) combination. The bootstrap caching pattern (`TestingDatabaseCaching.php`) means we add the new fixture data once and tests run fast.

### Migrations: zero downtime

All schema migrations are additive (new columns, new tables). No `DROP COLUMN`, no `RENAME`. The old `subscription_plan` enum values (`personal`, `team`) are kept as valid enum cases during transition; the data migration that renames `team` → `business` runs after deploy of new code.

### Rollback

If the cutover blows up: set `SENDVERY_STRIPE_LIVE=false` (new env var) and the pricing page reverts to `/request-access` CTAs. Existing Stripe subscriptions continue to bill normally — Stripe is its own system, not affected by our flag. Only new conversions stop.

---

## Test strategy

Per DEC-009, 100% line coverage is mandatory. Specific test infrastructure:

- **Unit tests** for every enum method, value object, DTO, exception.
- **`PlanLimitsTest`** is the source of truth for the matrix — if it changes, `docs/05-monetization.md` is wrong (or vice versa).
- **Integration tests** for `SubscriptionManager` using Stripe test-mode (the existing pattern from Stage 13).
- **Stripe webhook handler tests** with mocked Stripe events.
- **Symfony Panther E2E** for the pricing page Stimulus controller (one happy-path scenario).
- **`StubAiInsightsService`** tests assert canned-data structure (regression guard against accidental real-API calls in test mode).

The `App\Services\Ai` interface is also the test seam for everything downstream — anything that uses AI in production uses the stub in tests.

---

## Rollout checklist

Compiled here for the cutover day. Run top-to-bottom; each item independently revertible.

- [ ] All phases 0–5 merged + deployed (no user-visible behavior change yet — fake door still up).
- [ ] Stripe products + 12 prices created in **test mode**.
- [ ] `STRIPE_PRICE_*` env vars set in staging.
- [ ] End-to-end test in staging: subscribe → upgrade → downgrade → cancel → re-subscribe.
- [ ] Webhook listener verified against test-mode events.
- [ ] AI gating verified: `SENDVERY_AI_PURCHASABLE=false` correctly routes AI CTAs to lead capture.
- [ ] Stripe products + 12 prices created in **live mode**.
- [ ] `STRIPE_PRICE_*` env vars set in production.
- [ ] Webhook endpoint registered in Stripe live dashboard.
- [ ] Set `SENDVERY_AI_PURCHASABLE=false` in production.
- [ ] Flip `PricingTable.html.twig` CTAs from `request_beta_access` to `dashboard_billing_upgrade`.
- [ ] Sweep + update marketing copy with new prices.
- [ ] Email beta-access leads.
- [ ] Smoke test: subscribe with a real card.
- [ ] Set up Sentry alerts for Stripe exceptions.
- [ ] Schedule reconciliation cron.
- [ ] Tag release `v1.0.0-pricing-live`.
- [ ] Announce on social / Product Hunt / etc.

---

## Effort estimate

| Phase | Estimated effort |
|---|---|
| 0 — Tests as spec | 0.5 day |
| 1 — Foundations (enums, migrations, PlanLimits, StripePriceResolver) | 1 day |
| 2 — AI stub infrastructure | 1 day |
| 3 — Limit enforcement + retention | 1 day |
| 4 — Pricing page UI rewrite + Stimulus | 1.5 days |
| 5 — Checkout & billing flow updates | 1 day |
| 6 — Cutover (Stripe setup, copy sweep, lead email) | 0.5 day |
| 7 — Observability polish | 0.5 day |
| **Total** | **~7 days focused** |

This is realistic for a vibecoded project with 100% test coverage requirement. Real-time will be longer due to iteration, surprises, and the vibecode-then-review loop.
