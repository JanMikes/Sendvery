# Managed DMARC (CNAME) + Auto-Ramp — Implementation Plan

**Last updated:** 2026-06-28
**Related:** `docs/02-architecture.md`, `docs/03-features-roadmap.md`, `docs/04-data-model-protocols.md`, `docs/05-monetization.md` (feature matrix), `docs/07-decisions-log.md` (DEC-058), `docs/13-pricing-implementation-plan.md` (entitlement plumbing this builds on).

This document is the build plan for **Managed DMARC** — a new, opt-in "let Sendvery run it for you" enforcement model that sits *alongside* the existing self-TXT default. A paid customer points one immutable CNAME at us; Sendvery hosts and **safely auto-ramps** their DMARC policy (`p=none → quarantine → reject`) inside its own Cloudflare zone, with readiness gates, advance notices, and rollback. The plan is **phased and each phase is independently shippable** so we can pause/rearrange without leaving the codebase broken. It reuses the existing Cloudflare RFC 7489 authorization plumbing, the CQRS/event conventions, the plan-entitlement service layer, and the DNS-check pipeline verbatim wherever possible.

### Locked product decisions (the non-negotiables this plan implements)

1. **CNAME-only managed model, additive.** Managed DMARC is a NEW selectable option *alongside* self-TXT. Self-TXT stays the simple default; managed-CNAME is the "let us run it for you" choice. **No NS delegation.**
2. **One immutable CNAME per domain, mutable hosted TXT.** Per customer domain the customer sets ONE CNAME `_dmarc.<domain>` → `<domain>._dmarc.sendvery.com` that **never changes**; Sendvery publishes and *mutates* a full-policy TXT at `<domain>._dmarc.sendvery.com` in its own zone. Only the hosted TXT content changes.
3. **RFC 7489 §7.1 authorization record still required.** The `<domain>._report._dmarc.sendvery.com` authorization TXT is still required under CNAME and is **already automated** on `DomainAdded`. Managed DMARC runs *in addition* to it (the hosted policy's `rua=` is `reports@sendvery.com`, a cross-domain destination that §7.1 must authorize).
4. **Policy control v1 = fully automatic ramp, additive across all three layers.** (1) Customer-controlled per-domain policy selector (`p`=none/quarantine/reject + `pct` + `sp`) that publishes instantly, with readiness hints; (2) one-click guided ramp with a readiness recommendation; (3) opt-in fully-automatic scheduled ramp gated on readiness thresholds, with a 48-hour advance notice, pause/opt-out, and safety rails (never tighten on thin data, detect alignment regressions, roll back).
5. **Paid plans only; self-hosted bypasses.** Availability gates on an entitlement flag (`managed_dmarc`) — paid plans only. Self-hosted operators bypass via the existing `Unlimited` staff-grant; instances with no Cloudflare credentials never see the option at all.
6. **Auto-drive is the premium hero — communicate it as such.** The whole managed-CNAME feature is paid, but the *automatic ramp* ("**auto-drive**": Sendvery moves you to full enforcement hands-off, safely) is the headline reason to choose managed and to upgrade. Every customer-facing surface (onboarding chooser, dashboard card, Free-plan upgrade nudge, public-checker CTA, pricing/monetization copy) leads with auto-drive as the premium differentiator and carries a `Premium` visual treatment on the auto-ramp control. The entitlement is built so auto-drive can later be split to a *higher* paid tier than manual managed control without a rewrite (§3.7).

---

## Status

> When a future session asks "what's left on managed DMARC?", read this section first.

**Last touched:** 2026-06-28 (plan written; nothing implemented yet).

| Phase / Task | Status | Notes |
|---|---|---|
| 0 — Plan doc + DEC-058 + test skeletons (TASK-174) | ⬜ todo | This document + the decision-log entry + failing test files. |
| 1 — Shared DNS foundations (TASK-175..176) | ⬜ todo | Centralize report-domain derivation, extract `CnameResolver`, value objects, `DmarcRecordSerializer`. |
| 2 — Entity + publisher + composer (TASK-177..178) | ⬜ todo | 13 new `MonitoredDomain` fields + migration; full-policy TXT publish path (upsert via GET→PATCH→POST); `ManagedDmarcPolicyComposer`. |
| 3 — Write side: commands/handlers/events + audit (TASK-179..180) | ⬜ todo | Tenant-scoped handlers, enforcement-preserving enable, single republish path, audit trail. |
| 4 — CNAME verification + managed-aware DNS pipeline (TASK-181..182) | ⬜ todo | Three-state CNAME check, live coexistence TXT check, alert suppression for managed domains. |
| 5 — Readiness engine + entitlement (TASK-183..184) | ⬜ todo | `GetDomainReadinessSignals` + `DmarcRampReadinessEvaluator` (strict auto thresholds); `managed_dmarc` feature. |
| 6 — Alerts + transactional emails (TASK-185..186) | ⬜ todo | New `AlertType` cases + severity; six dedicated managed email handlers/templates. |
| 7 — Dashboard card + onboarding chooser (TASK-187..188) | ⬜ todo | `<twig:ManagedDmarcCard>`, five write controllers, onboarding managed tab + three-state verify. |
| 8 — Crons (TASK-189..190) | ⬜ todo | `sendvery:dmarc:auto-ramp` + `sendvery:dmarc:sync-hosted-records`. |
| 9 — Downgrade freeze + demo seed (TASK-191..192) | ⬜ todo | Freeze auto-ramp on downgrade; managed sample data so demo surfaces aren't empty. |
| 10 — Docs finalize (TASK-193) | ⬜ todo | docs/02/03/04/05 + CLAUDE.md crons + DEC-058 confirm; full suite green at `--coverage-min=100`. |

---

## Goals

1. Ship **managed-CNAME DMARC** as a paid-plan option that never edits the customer's DNS again after the one immutable CNAME, while leaving self-TXT untouched as the free default.
2. Build the **three additive control layers** (instant manual selector → one-click guided advance → opt-in automatic scheduled ramp) over a single, idempotent republish path.
3. Make the auto-ramp **provably safe**: stricter-than-advisory readiness thresholds, thin-data gates, dwell time, 48h advance notice, regression detection, and instant rollback — none of which can be bypassed by a forged request.
4. **Never break a customer's live DMARC and never delete user data**: publish-before-CNAME, dangling-safe teardown, freeze-don't-loosen on downgrade.
5. Maintain **100% test coverage** throughout; tests describe the managed-DMARC business behaviour (DEC-009).

---

## Out of scope for this plan

- **NS delegation** (explicitly rejected — CNAME only).
- **Forensic/RUF ingestion.** There is no forensic entity/table; failure signals stay aggregate-only (`source_ip + count + policy_evaluated + disposition`). The readiness engine uses only aggregate data.
- **Per-domain intermediate `pct` ramp steps** (e.g. `quarantine pct=25`). `ManagedDmarcPolicy` carries `pct` so this is addable later without a migration, but v1 ramps at `pct=100` per tier.
- **Multi-value / external `rua` on the hosted record.** The hosted TXT's `rua` is Sendvery-only (DEC-058); customers who must keep an external report destination stay on self-TXT.
- **Self-serve hosted-zone configuration for self-hosters.** Self-hosters who want managed run on `Unlimited` with their own `CLOUDFLARE_*` env set; no UI for it.

---

## 1. Background & how it works

### The DNS layout (locked)

```
Customer zone (acme.com):
    _dmarc.acme.com.            CNAME   acme.com._dmarc.sendvery.com.     ← immutable, customer-owned, set once

Sendvery zone (sendvery.com, Cloudflare):
    acme.com._dmarc.sendvery.com.          TXT  "v=DMARC1; p=none; rua=mailto:reports@sendvery.com; adkim=r; aspf=r; fo=1"
                                                 ↑ MUTABLE — Sendvery ramps p=none→quarantine→reject here
    acme.com._report._dmarc.sendvery.com.  TXT  "v=DMARC1;"               ← RFC 7489 §7.1 authorization, ALREADY automated on DomainAdded
```

**Why both records exist.** Under CNAME delegation a receiver still treats the DMARC record as belonging to `acme.com`, so RFC 7489 §7.1 still requires `acme.com._report._dmarc.sendvery.com` to exist with `v=DMARC1` (because the hosted policy's `rua=` points at `reports@sendvery.com`, a different org than `acme.com`). That authorization record is produced today by `CloudflareDnsClient::publishAuthorizationRecord()` / `buildRecordName()` on the `DomainAdded` event and reconciled by `sendvery:dns:sync-authorization-records`. Managed DMARC adds the *second* (full-policy) record; it does not replace the authorization record.

**Why CNAME + mutable TXT is valid.** The customer's CNAME target *name* is fixed forever; Sendvery only mutates the TXT *content* at that target inside its own zone. This is the standard, widely-deployed DMARC-delegation pattern; subdomain/`sp` fallback still resolves through the CNAME. Because the hosted TXT lives in Sendvery's own controlled zone, there is **no third-party subdomain-takeover vector**.

**Per-domain, not per-team.** Every record, every policy, every ramp decision is per `MonitoredDomain`. Domain ownership stays system-wide unique (the existing `findAnyByName()` + functional index guard is preserved).

### The existing code this builds on (real change points)

- `src/Services/Dns/CloudflareDnsClient.php` — `final readonly implements DnsRecordPublisher`. The record name is hardcoded in `buildRecordName()` (line ~208) as `sprintf('%s._report._dmarc.%s', strtolower($customerDomain), $reportDomain)`; content is hardcoded `'v=DMARC1;'` with `ttl => 1` in `publishAuthorizationRecord()` (lines ~41-45). `listAuthorizationRecords()` filters on `'contains:._report._dmarc.%s'` (line ~160); `extractCustomerDomain()` strips the `'._report._dmarc.%s'` suffix (line ~190). Reusable verbatim: `dnsRecordsUrl()`, `apiRequest()` (Bearer GET/POST/DELETE), `findTxtRecord(name)`, `deleteRecordById(id)`, `isDuplicateError()` (81057), `isNotFoundError()` (81044), `getReportDomain()` (`@`-split of `SENDVERY_REPORT_ADDRESS`).
- `src/Services/Dns/DnsRecordPublisher.php` — the interface seam (3 auth methods keyed only on `customerDomain`). `FakeDnsRecordPublisher` is the in-memory test double (aliased `when@test`); `.env.test` sets `CLOUDFLARE_API_TOKEN=test-cf-token` + `CLOUDFLARE_ZONE_ID=test-cf-zone`, so `isConfigured()` is **true** in tests.
- `src/Value/Dns/DmarcRuaInstruction.php` — the only serializer today. `build(?currentRecord, reportAddress)` appends Sendvery's `rua` to an existing record or emits a fixed default `'v=DMARC1; p=none; rua=mailto:%s; fo=1; adkim=r; aspf=r'`. The canonical-order logic lives in `private static rebuildRecord(array $tags)` with order `['v','p','sp','rua','ruf','adkim','aspf','pct','fo','rf','ri']`, joined by `'; '`.
- `src/Services/Dns/DmarcRecordParser.php` → `ParsedDmarcRecord` exposes only `policy/rua/ruf/pct`. The richer `DmarcChecker::check()` → `DmarcCheckResult` adds `subdomainPolicy(sp)/adkim/aspf` but is network-touching and still has **no `fo`**. `RuaScenarioResolver` classifies `rua` state (`NoRecord` / `PointsAtSendvery` / `PointsAtExternal`).
- `src/Services/Dns/DmarcReportAuthorizationChecker.php` — read-side verifier; hardcodes `'%s._report._dmarc.%s'` (line ~52) and checks `str_starts_with(strtolower(trim($value)), 'v=dmarc1')`. The template for a hosted-policy verifier.
- `src/Services/Dns/DkimChecker.php::lookupCnameTarget()` (line ~73) — the CNAME-resolution pattern (spatie/dns, first non-empty target `rtrim('.')`).
- `src/Entity/MonitoredDomain.php` — `final class implements EntityWithEvents`, `use HasEvents`. Uses `domain` (not "name") + `createdAt` (not "addedAt"). `cloudflareAuthRecordId` (string(64) nullable, property-initialized `= null`) is the exact precedent for a sibling hosted-record-id column. `markDmarcVerified()` is the transition-guard precedent (emits only on a real change). Only `$team`/`$createdAt` are `readonly`; state fields are mutable public.
- CQRS triad precedent: `AddDomain` (command) → `AddDomainHandler` (`#[AsMessageHandler] readonly final`, persists, **never flushes**) → `DomainAdded` (event) → `PublishAuthorizationRecordWhenDomainAdded` (event handler mutates `cloudflareAuthRecordId`). Both `command_bus` and `event_bus` carry `doctrine_transaction` middleware; domain events are dispatched synchronously (only `GenerateAnomalyInsight`/`GenerateRemediationInsight` are async-routed).
- Entitlement: `PlanLimits::hasFeature()` (`Unlimited` short-circuits true; paid = `SubscriptionPlan::Free !== $plan`), `PlanEnforcement`, `GetTeamPlan::forTeam()`. `SubscriptionPlan::nextTier()` drives upgrade nudges.
- Readiness precedents: `DmarcPolicyAdvisorResult::forDomain($current,$passRate,$reportsCount)` (none→quarantine 90% & ≥3 reports; quarantine→reject 95%) and the stricter event-driven `RecommendPolicyUpgrade` (95%/30d; 99%/60d). `GetDomainDetail::getRecentActivity(...,$days=30)` is the canonical windowed pass-rate feed; `MonitoredDomain.firstReportAt` survives retention purge.

---

## 2. Customer-facing design

Two ingestion/enforcement models now coexist: **self-TXT** (simple default — customer owns `_dmarc`) and **managed-CNAME** (Sendvery hosts + auto-enforces). Everything below gates on `dnsAutomationConfigured` (`CloudflareDnsClient::isConfigured()`) **AND** `PlanEnforcement::canUseManagedDmarc($plan)`. Self-hosted instances (CLOUDFLARE_* unset) never see the managed option.

**Positioning — auto-drive is the premium hero.** Managed DMARC as a whole is paid, but the thing we *sell* is **auto-drive**: "set one CNAME and Sendvery automatically, safely moves you all the way to full enforcement (`p=reject`) — hands-off." Every managed surface leads with auto-drive as the reason to upgrade, and the auto-ramp control wears a small **`Premium`** badge (semantic `badge badge-primary`/`badge-secondary` token — never a hardcoded colour). Manual policy control and the guided one-click advance are framed as the *proof it's safe*; auto-drive is the *payoff*. Copy never implies any of it is free, and the marketing/pricing surfaces name auto-drive explicitly.

### 2.1 The option chooser

**Where it lives — onboarding step 3** (`templates/onboarding/ingestion.html.twig`, route `onboarding_ingestion`). Today option 1 = "Forward reports to us via DNS" (self-TXT, hidden `method=forward`), option 2 = collapsible IMAP `<details>` (`method=mailbox`), separated by the "or" divider. **Restructure option 1's card into a two-tab segmented control** (self-TXT | managed) so the chooser lives *inside* the existing card. The managed tab POSTs `method=managed` — add that branch to `OnboardingIngestionController.__invoke` (alongside the existing `forward`/`mailbox` branches). The controller must additionally pass `dnsAutomationConfigured` + `managedDmarcAvailable` (NEITHER is passed today). When not entitled, render the managed tab as a disabled pill with an inline upgrade nudge via `SubscriptionPlan::nextTier()`.

**Where it lives — dashboard.** The chooser collapses into the *state* of the new `<twig:ManagedDmarcCard>` (§2.2): if managed is off, the card *is* the "switch to managed" pitch with the same comparison copy.

**On-screen copy — chooser heading** (unchanged eyebrow): **"Get your DMARC reports flowing"**

Segmented control:

```
  ┌──────────────────────────────┬──────────────────────────────┐
  │  ● Add a record yourself     │  ○ Let Sendvery manage it     │
  │     (self-TXT) · default      │     (CNAME) · hands-off        │
  └──────────────────────────────┴──────────────────────────────┘
```

**Tab 1 — "Add a record yourself (self-TXT)"** (existing flow, unchanged — keeps `<twig:DnsRecordInstruction recordType="TXT" host="_dmarc.{{ domainName }}" ...>` and the lazy `dmarc-verify` turbo-frame):

> **You stay in control of your DNS.** Add one TXT record at `_dmarc.{{ domainName }}` and you're done. We collect and analyze your reports; you decide if and when to tighten enforcement.

**Tab 2 — "Let Sendvery manage it (CNAME)"**:

> **Set one CNAME once, then never touch DNS again.** You point `_dmarc.{{ domainName }}` at us with a single record that never changes. Sendvery hosts your DMARC policy and — when your real mail is consistently passing — safely ramps you from monitoring (`p=none`) to quarantine to full enforcement (`p=reject`), on your schedule or fully automatically.

### 2.2 "Which is good for you" decision guidance

Render as two `card-bordered` columns (`bg-base-200`, semantic tokens only) under the tabs.

```
  ┌─────────────────────────────────────┬─────────────────────────────────────┐
  │  Let Sendvery manage it (CNAME)      │  Add a record yourself (self-TXT)    │
  │  ───────────────────────────         │  ───────────────────────────         │
  │  Best if you want…                   │  Best if you…                        │
  │  • Hands-off enforcement —           │  • Must keep your own _dmarc TXT     │
  │    we move you to reject safely      │    record (compliance / change       │
  │  • To never edit your DMARC          │    control owns it)                  │
  │    record again                      │  • Already use another DMARC tool    │
  │  • Auto-ramp with safety rails       │    (rua already points elsewhere)    │
  │    and rollback                      │  • Have a DNS host that can't add    │
  │  • One CNAME, set-and-forget         │    a CNAME at _dmarc                 │
  │                                      │  • Prefer to control every policy    │
  │                                      │    change yourself                   │
  │  Requires: no existing _dmarc TXT    │  Works everywhere. Always free.      │
  │  (a CNAME can't coexist with one)    │                                      │
  └─────────────────────────────────────┴─────────────────────────────────────┘
```

Footnote under both: *"Either way, report collection and AI insights work identically. You can switch between them anytime."*

**Decision-guidance rule that resolves the `rua` blocker (DEC-058):** the "Already use another DMARC tool (rua already points elsewhere)" bullet is load-bearing. Managed DMARC makes Sendvery the *sole* `rua` destination on the hosted record — so a customer who must keep an external report destination should choose self-TXT (where we only *append* our `rua`). The managed-enable flow surfaces an explicit warning when it detects an external `rua` (§2.6 edge case a).

### 2.3 Managed tab body — the CNAME instruction

Reuse `<twig:DnsRecordInstruction>` verbatim with `recordType="CNAME"` (the component already documents CNAME support):

```twig
<twig:DnsRecordInstruction
    recordType="CNAME"
    host="_dmarc.{{ domainName }}"
    finalValue="{{ domainName }}._dmarc.{{ reportDomain }}"   {# e.g. acme.com._dmarc.sendvery.com #}
    whatText="Point your DMARC record at Sendvery. This single record never changes — we update the policy behind it for you."
    whyText="Lets us host and safely ramp your DMARC enforcement so you never edit DNS again."
    :alreadyConfigured="managedCnameVerified" />
```

`reportDomain` is the existing template var derived from `SENDVERY_REPORT_ADDRESS` (`sendvery.com`). **Add a copy button** — `DnsRecordInstruction` has none today (CSS `select-all` only); wire `clipboard_copy_controller` (`data-controller="clipboard-copy" data-clipboard-copy-text-value="..."`) next to the final-value box (the controller already exists in the repo).

Below it, a sibling lazy verify frame (new id so both self-TXT and managed frames can coexist):

```twig
<turbo-frame id="managed-cname-verify"
             src="{{ path('onboarding_ingestion_managed_verify') }}"
             loading="lazy">
  <span class="loading loading-dots"></span> Checking your CNAME…
</turbo-frame>
```

New route `onboarding_ingestion_managed_verify` clones `OnboardingIngestionVerifyController` but runs `ManagedDmarcCnameChecker` + a **live** coexisting-TXT check (§2.6a) and renders a **three-state** managed variant of `onboarding/_verify_panel.html.twig` driven by the same `dns_verify_poll` Stimulus controller.

### 2.4 The managed per-domain card (dashboard)

New component `<twig:ManagedDmarcCard>` slotted in `templates/dashboard/domain_detail.html.twig` **immediately after `<twig:DomainSetupStatus>`** and above the DKIM-selector card — the top status stack. It pairs with `<twig:DmarcPolicyExplainer :result="dmarcPolicyAdvice">` (which stays as the educational 3-tier progress); the new card is the *control surface*. `ShowDomainDetailController` already injects `dmarcPolicyAdvice`, `domainSetupStatus`, `dnsAutomationConfigured`, `domain.dmarcPolicy`, `reportDomain`; add `managedDmarcAvailable` (entitlement), a `ManagedDmarcCardResult` (assembled in the controller from the loaded entity + `RampReadinessResult`), and the policy-change `history` (§3 audit).

All writes mirror `SetDomainDkimSelectorController` exactly (CSRF token + UUID guard + `commandBus->dispatch(...)` + flash + redirect to `dashboard_domain_detail`).

| Route | Command | CSRF token |
|---|---|---|
| `dashboard_domain_enable_managed_dmarc` | `EnableManagedDmarc` | `domain_managed_enable` |
| `dashboard_domain_set_dmarc_policy` | `SetDmarcPolicy` (p/pct/sp) | `domain_dmarc_policy` |
| `dashboard_domain_advance_dmarc` | `AdvanceDmarcPolicy` (one-click guided) | `domain_dmarc_advance` |
| `dashboard_domain_set_auto_ramp` | `ConfigureDmarcAutoRamp` (opt-in/pause/opt-out/goal) | `domain_dmarc_autoramp` |
| `dashboard_domain_switch_to_self_txt` | `SwitchManagedDmarcToSelfTxt` | `domain_managed_to_self` |

### 2.5 Card states + microcopy

**State machine:**

| State | Trigger | What shows |
|---|---|---|
| **Not enabled** | `managedDmarcEnabledAt = null` | "switch to managed" pitch (§2.2 comparison) + Enable button |
| **Preparing** | enabled, `cloudflareHostedDmarcRecordId = null` | "Preparing your record…" — the synchronous publish failed/queued; retry before showing the CNAME (FIX for verification issue *l*) |
| **CNAME pending** | hosted record published, `cnameVerifiedAt = null` | CNAME instruction + self-polling verify + "remove existing TXT" guard if a `_dmarc` TXT exists |
| **Verified + active** | `cnameVerifiedAt` set, CNAME resolving to us | Policy selector + readiness hint + advance button + auto-ramp controls |
| **Error / dangling** | CNAME was verified but now missing/NXDOMAIN, OR hosted publish failing, OR conflicting TXT detected | Red banner, ramp frozen, fix instructions |

**ASCII — "Verified + active" (full card):**

```
┌─ Managed DMARC ───────────────────────────────────────── ● Active ──┐
│ Sendvery is hosting and enforcing DMARC for acme.com.                │
│ CNAME verified · _dmarc.acme.com → acme.com._dmarc.sendvery.com      │
│                                                                      │
│  Current policy                                                      │
│  ┌────────── none ──────────┬─── quarantine ───┬──── reject ────┐    │
│  │            ●━━━━━━━━━━━━━━━━━━━━━━○                   ○        │    │
│  │         monitoring        you are here          full enforce  │    │
│  └──────────────────────────┴──────────────────┴───────────────┘    │
│                                                                      │
│  ┌─ Set policy manually (publishes instantly) ──────────────────┐    │
│  │  Policy (p)    [ none ▾ ]   Subdomains (sp) [ same ▾ ]        │    │
│  │  Coverage (pct) [ 100 ▾ ]                  [ Publish now ]    │    │
│  └──────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  💡 Readiness                                                        │
│  98.7% of your real mail has passed DMARC for 30 days straight,      │
│  across 6 known senders. You're ready to move to quarantine.        │
│                                  [ ⤴ Advance to quarantine now ]     │
│                                                                      │
│  ┌─ Automatic ramp ───────────────────────── [ On ●─ ] ─────────┐    │
│  │ Goal: reject  ·  Sendvery advances you when each tier is safe │    │
│  │ Next step: none → quarantine, scheduled Thu 3 Jul, 09:00      │    │
│  │ We'll email you 48h before. Safety rails: never tighten on    │    │
│  │ thin data, auto-pause & roll back on alignment drops.         │    │
│  │              [ Pause ramp ]   [ Change goal ]   [ Turn off ]   │    │
│  └──────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  Recent changes: none→quarantine (auto-ramp) · 12 Jun by you · …     │
│  Switch back to managing the record yourself →                       │
└──────────────────────────────────────────────────────────────────────┘
```

**Microcopy by state:**

- **Not enabled** — Heading **"Hand off DMARC enforcement"**; body *"Set one CNAME and Sendvery hosts your DMARC policy — then safely moves you to full enforcement when your mail is ready. You never edit DNS again."*; button **"Let Sendvery manage DMARC"** → `EnableManagedDmarc`. If not entitled: disabled, helper *"Managed DMARC is available on {{ nextTier.label }} and up. Upgrade to enable →"*.
- **Preparing** — *"Preparing your managed DMARC record… this only takes a moment."* (shown while `cloudflareHostedDmarcRecordId` is null; auto-retries).
- **CNAME pending** — pill `● CNAME pending` (warning token); *"Add this CNAME at your DNS host. We're checking every few seconds — this can take up to an hour to propagate."* + the `DnsRecordInstruction recordType="CNAME"` + `managed-cname-verify` frame; helper *"We've already published your starting policy (`p=none`, monitor-only), so nothing changes for your senders yet."* (publish-first — see §6 safety).
- **Verified + active** readiness hint variants (driven by `RampReadinessResult`):
  - Thin data: *"We're still gathering data. We've seen {{ days }} of {{ minDays }} days of reports — auto-ramp stays paused until your traffic is well understood."*
  - Eligible none→quarantine: *"{{ passRate }}% of your real mail has passed DMARC for {{ days }} days straight, across {{ sources }} known senders. You're ready to move to quarantine."*
  - Eligible quarantine→reject: *"{{ passRate }}% aligned over {{ days }} days. Ready for full enforcement (reject)."*
  - Not yet eligible: *"Alignment is {{ passRate }}% — we want {{ threshold }}%+ held steady before tightening. We'll let you know the moment you're ready."*
- **Advance affordance** (layer 2): dynamic label **"⤴ Advance to {{ recommendedNextPolicy.value }} now"**, disabled with tooltip *"Not ready yet — see readiness above"* when `eligibleForNextTier=false`. Posts `AdvanceDmarcPolicy`; the handler **re-checks readiness server-side** (never trusts the button).
- **Auto-ramp** (layer 3): toggle-on *"Automatic ramp is on. We'll advance you toward reject one tier at a time, only when each step is safe, and email you 48 hours before every change."*; schedule line *"Next step: {{ from }} → {{ to }}, scheduled {{ pendingAdvanceAt|date }}."*; paused badge `● Paused` + *"Auto-ramp is paused. Your current policy stays live; we won't tighten until you resume."* + **"Resume ramp"**; goal selector *"Stop at: [ quarantine ▾ / reject ▾ ]"*; **"Turn off automatic ramp"** preserves current policy (never loosens).
- **Error / dangling** — *"⚠ We can't see your CNAME anymore."* / *"`_dmarc.acme.com` no longer points to Sendvery, so your DMARC policy isn't being served. Re-add the CNAME, or switch to managing the record yourself."* (ramp auto-pauses; advance/auto-ramp disabled). Conflicting TXT — *"⚠ There's still a `_dmarc` TXT record on acme.com. A CNAME can't coexist with it — remove the TXT below, then we'll finish setup."*

### 2.6 Edge-case UX

**(a) Customer already has a `_dmarc` TXT (CNAME can't coexist, RFC 1034).** Detect via a **live TXT lookup** at `_dmarc.<domain>` in the managed-verify route (not the possibly-stale cached `DnsCheckResult` — FIX for verification issue *c*). When present, **block enabling** and show the current TXT read-only via `DnsRecordInstruction` + *"acme.com already has a DMARC TXT record. A CNAME can't sit alongside it, so you'll need to delete this first… Delete this record, then add the CNAME above. We'll keep the same protection — your current policy is mirrored into the managed record."*

**Enforcement-preserving switchover (FIX for verification "silent downgrade" HIGH).** `EnableManagedDmarc` seeds the first hosted policy from the customer's **current live DMARC record** (parse `p/sp/pct` via `DmarcRecordParser`/`DmarcCheckResult`), and sets `autoRampStage` to the matching tier (already at `reject` → `Reject`/`Complete`). Only fall back to `ManagedDmarcPolicy::monitoring()` when no enforcing policy exists. **`rua` becomes Sendvery-only** (DEC-058); if the live record's `rua` points at a third party, show: *"Heads up: managed DMARC sends reports to Sendvery only. Your previous DMARC tool will stop receiving reports for acme.com once you switch. If you need to keep it, stay on the self-managed record."* The `managed-cname-verify` poll stays in a "waiting — remove the old TXT" state until the TXT is gone AND the CNAME resolves to us.

**(b) CNAME not yet propagated.** Stay in **CNAME pending** with `dns_verify_poll` auto-retrying (15s, ~20 attempts) + "Retry now". Copy: *"Not visible yet — DNS can take up to an hour. We'll keep checking and email you when it's live."* The hosted TXT was already published at enable time (publish-first), so the CNAME target always exists before the customer points at it.

**(c) Downgrade from paid (keep working / "never delete user data").** On downgrade to Free the managed entitlement is lost but **the hosted TXT and the CNAME keep resolving and enforcing** — we do NOT delete (would NXDOMAIN their `_dmarc`). Concretely: auto-ramp **freezes** (`autoRampPausedAt` set by the downgrade handler — §3/§9); current policy stays live; the card switches read-only with *"Managed DMARC is frozen on your current policy (`p=quarantine`). Your protection keeps working. Upgrade to change policy or resume auto-ramp →"*; manual selector/advance/auto-ramp disabled; **never auto-loosen**. Offered graceful exit: *"Prefer to take it back over? Switch to a self-managed record →"*.

**(d) Switching managed → self-TXT and back.** `SwitchManagedDmarcToSelfTxt` snapshots the **current hosted TXT content** into a `DnsRecordInstruction recordType="TXT"`: *"Here's your current policy as a normal TXT record. Replace the CNAME at `_dmarc.acme.com` with this TXT, and you'll own it directly — no change to your enforcement."* Order matters — add the TXT only after removing the CNAME. The verify poll confirms the TXT is live and the CNAME is gone before we tear down the hosted record + stop the ramp (no enforcement gap). **Back** reuses path (a). The `_report._dmarc` authorization record is unaffected by either switch.

**(e) Dangling CNAME / offboard.** Never tear down a hosted TXT while the CNAME still resolves to it. The `sendvery:dmarc:sync-hosted-records` reconcile loop classifies any hosted policy record whose owning domain is inactive/unmanaged as **dangling**: it does NOT delete; it fires the dangling alert/email and surfaces the Error/dangling card state. Teardown is safe only once the CNAME is gone (confirmed by `ManagedDmarcCnameChecker`). Make removing the CNAME an explicit step in offboarding/domain-removal: *"Before we stop hosting, remove `_dmarc.acme.com`'s CNAME so your DMARC doesn't go dark."*

### 2.7 Notifications / emails

Six touchpoints. **Each is a dedicated transactional email** modeled on `SendWeeklyDigestHandler` / `SendAlertEmailNotification` (`MailerInterface` + Twig HTML template under `templates/emails/managed_*.html.twig` + plain-text alternative + from-address) — NOT routed through the generic alert mailer, because `SendAlertEmailNotification` only emails for `AlertSeverity::Critical` and would silently drop the informational ones (FIX for verification HIGH). In-app `Alert` rows are created in parallel using new `AlertType` cases (§6); regression + dangling are `Critical` so they also flow through the existing critical-email path.

| Touchpoint | When | Subject | One-line body | In-app Alert |
|---|---|---|---|---|
| **CNAME verified** | `cnameVerifiedAt` set | `Managed DMARC is live for acme.com` | We can see your CNAME — Sendvery is now hosting your DMARC policy in monitor-only mode (`p=none`), so nothing changes for your senders yet. | email only |
| **Ready to advance** (guided mode only) | becomes `eligibleForNextTier`, auto-ramp off | `acme.com is ready for quarantine` | Your mail has passed DMARC consistently — advance to quarantine in one click whenever you're ready. | `ManagedDmarcReady` (info) |
| **Auto-advance 48h notice** | `autoRampScheduledAdvanceAt` set, 48h out | `Heads up: acme.com moves to quarantine in 48 hours` | Your DMARC enforcement will tighten on {{ date }} — click Pause if you need to hold off. | email only |
| **Advanced** | tier actually published | `acme.com is now at quarantine` | We've moved your DMARC policy to quarantine. Reject is the final step — we'll continue when your mail is ready. | `ManagedDmarcAdvanced` (info) |
| **Regression / rollback** | safety rail trips | `We paused DMARC enforcement on acme.com` | Alignment dropped to {{ passRate }}% ({{ sender }} started failing) — we held your ramp and won't tighten until it recovers. | `ManagedDmarcRegression` (**Critical**) |
| **Dangling CNAME / offboard** | verified CNAME disappears, or downgrade with CNAME still pointing at us | `Action needed: acme.com's DMARC record points to Sendvery but isn't managed` | Your `_dmarc` CNAME still points to us but managed DMARC is off — re-enable it or remove the CNAME so your DMARC keeps working. | `ManagedDmarcDangling` (**Critical**) |

The 48h-notice + rollback emails are emitted by the `sendvery:dmarc:auto-ramp` cron in one idempotent pass (compute pending advances, send 48h notices, execute due advances, detect regressions).

### 2.8 Public DMARC checker tool

Add a single soft CTA in the **results view only**, shown **only when the result is weak** (no DMARC, or `p=none`, or `rua` missing) — never on strong/`p=reject` records:

> **Don't want to manage this yourself?** Sendvery can host your DMARC and safely move you to full enforcement automatically — you set one CNAME and never edit DNS again. **[See managed DMARC →]**

One line, secondary styling, below the existing fix recommendations. Link to the pricing/managed explainer (not a one-click action — there's no authenticated domain context), and do **not** imply it's free. Consistent with the marketing-nav "no intrusive badges" rule.

---

## 3. Architecture

Next ticket numbers: **TASK-174+**, **DEC-058**, plan doc **docs/15-managed-dmarc-plan.md**.

### 3.1 Data model

**New enums / value objects (`src/Value/Dns/`):**

```php
enum DmarcSetupMode: string { case SelfTxt = 'self_txt'; case ManagedCname = 'managed_cname'; }

enum AutoRampStage: string {
    case Monitoring = 'monitoring';   // p=none
    case Quarantine = 'quarantine';   // p=quarantine pct=100
    case Reject     = 'reject';       // p=reject pct=100
    case Complete   = 'complete';     // terminal
    public function next(): ?self { /* Monitoring→Quarantine→Reject→Complete→null */ }
    public function previous(): ?self { /* Reject→Quarantine→Monitoring; for rollback target */ }
    public function targetPolicy(): ?ManagedDmarcPolicy { /* per stage; Complete/Monitoring map as documented */ }
    public static function fromPolicy(?DmarcPolicy $p): self { /* none→Monitoring, quarantine→Quarantine, reject→Reject, null→Monitoring */ }
}

enum CnameVerificationOutcome: string { case Verified='verified'; case PointsElsewhere='points_elsewhere'; case Missing='missing'; }

enum PolicyChangeSource: string { case Manual='manual'; case Guided='guided'; case AutoRamp='auto_ramp'; case Rollback='rollback'; case DowngradeFreeze='downgrade_freeze'; }

readonly final class ManagedDmarcPolicy {
    public function __construct(public DmarcPolicy $p, public ?DmarcPolicy $sp = null, public int $pct = 100) {}
    public static function monitoring(): self { return new self(DmarcPolicy::None); }
    public function equals(self $o): bool { /* p/sp/pct compare — drives idempotent republish */ }
}
```

`AutoRampStage::fromPolicy()` + `previous()` make the **published policy (`managedPolicyP`) the single source of truth for current stage** (FIX for verification "rollback inconsistency" MEDIUM *g*): the evaluator and card always derive the stage from `managedPolicyP`, and rollback resets `autoRampStage` to match. `pct` lives on `ManagedDmarcPolicy` so finer `pct`-steps are addable later without a migration.

**New fields on `MonitoredDomain`** — public mutable, **property-initialized** (NOT constructor args, exactly like `cloudflareAuthRecordId = null`, so existing domains construct unchanged). Declare DB defaults in the ORM mapping too (`options: ['default' => ...]`) so `doctrine:schema:validate` stays green (FIX for verification LOW):

```php
#[ORM\Column(type: 'string', length: 20, enumType: DmarcSetupMode::class, options: ['default' => 'self_txt'])]
public DmarcSetupMode $dmarcSetupMode = DmarcSetupMode::SelfTxt;
#[ORM\Column(length: 64, nullable: true)] public ?string $cloudflareHostedDmarcRecordId = null;
#[ORM\Column(type: 'string', length: 20, nullable: true, enumType: DmarcPolicy::class)] public ?DmarcPolicy $managedPolicyP = null;
#[ORM\Column(type: 'string', length: 20, nullable: true, enumType: DmarcPolicy::class)] public ?DmarcPolicy $managedPolicySp = null;
#[ORM\Column(type: 'integer', nullable: true)] public ?int $managedPolicyPct = null;
#[ORM\Column(type: 'boolean', options: ['default' => false])] public bool $autoRampEnabled = false;
#[ORM\Column(type: 'string', length: 20, nullable: true, enumType: AutoRampStage::class)] public ?AutoRampStage $autoRampStage = null;
#[ORM\Column(type: 'string', length: 20, nullable: true, enumType: AutoRampStage::class)] public ?AutoRampStage $autoRampScheduledStage = null;
#[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $autoRampScheduledAdvanceAt = null;
#[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $autoRampPausedAt = null;
#[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $managedDmarcEnabledAt = null;
#[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $cnameVerifiedAt = null;
#[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $lastPolicyChangeAt = null;   // dwell anchor
#[ORM\Column(type: 'datetime_immutable', nullable: true)] public ?\DateTimeImmutable $hostedDmarcTeardownAt = null; // offboard marker
```

**Guarded transition methods** (mirror `markDmarcVerified` — emit events only on a real change so idempotent cron re-runs don't duplicate):

```php
public function enableManagedDmarc(ManagedDmarcPolicy $seed, \DateTimeImmutable $now): void;   // mode=ManagedCname, seed policy, autoRampStage=fromPolicy(seed->p); records ManagedDmarcEnabled only if mode was SelfTxt
public function changeManagedPolicy(ManagedDmarcPolicy $policy, PolicyChangeSource $source, ?UuidInterface $actorUserId, \DateTimeImmutable $now): void; // sets p/sp/pct + lastPolicyChangeAt + autoRampStage=fromPolicy(p); records DmarcPolicyChanged(from,to,source,actor) ONLY if effective content differs
public function markCnameVerified(CnameVerificationOutcome $o, \DateTimeImmutable $now): void; // sets/clears cnameVerifiedAt; records CnameVerified on the null→set transition
public function enableAutoRamp(\DateTimeImmutable $now): void; public function disableAutoRamp(): void;
public function scheduleAutoRampAdvance(AutoRampStage $to, \DateTimeImmutable $at): void;
public function pauseAutoRamp(\DateTimeImmutable $now): void;       // clears schedule, sets autoRampPausedAt; records AutoRampPaused
public function disableManagedDmarc(\DateTimeImmutable $now): void; // mode=SelfTxt, autoRamp off, clears policy INTENT, sets hostedDmarcTeardownAt, KEEPS cloudflareHostedDmarcRecordId; records ManagedDmarcDisabled
```

`changeManagedPolicy` is the **single funnel** for set / advance / rollback / downgrade-freeze → one `DmarcPolicyChanged` event → one republish path → one audit row.

**New ORM audit entity `src/Entity/ManagedDmarcPolicyChange.php`** (FIX for verification "no audit log" HIGH) — immutable, written by a `DmarcPolicyChanged` event handler:

```
table managed_dmarc_policy_change:
  id uuid PK, monitored_domain_id uuid FK, team_id uuid,
  actor_user_id uuid NULL, source VARCHAR(20) (PolicyChangeSource),
  from_policy VARCHAR(40) NULL, to_policy VARCHAR(40), reason TEXT NULL,
  created_at datetime_immutable
```

Because it's an **ORM** entity, SchemaTool creates it in the test DB — **no `TestingDatabaseCaching::createMigrationOnlyTables` change needed**. Surfaced on the dashboard card ("Recent changes").

**Migration `migrations/Version20260628120000.php`** (monotonic after the latest existing `Version20260602000000`; follows the `Version20260601000000` template). `up()` does `ALTER TABLE monitored_domain ADD …` for the 13 columns (NOT-NULL-with-default for `dmarc_setup_mode`/`auto_ramp_enabled`, all others nullable) plus `CREATE TABLE managed_dmarc_policy_change (…)`; `down()` reverses. All additive — safe on existing rows; respects "never delete user data".

### 3.2 Services

**3.2a Centralize report-domain derivation.** Add `ReportAddressProvider::getReportDomain(): ?string` (the `strrpos('@')` slice) and refactor `CloudflareDnsClient::getReportDomain()`, `DmarcReportAuthorizationChecker::getReportDomain()`, and the new CNAME checker to call it (one source of truth → prod `sendvery.com`, test `sendvery.test`).

**3.2b `src/Value/Dns/DmarcRecordSerializer.php`** — new pure class. **Extract `DmarcRuaInstruction::rebuildRecord()` into it** (canonical order `['v','p','sp','rua','ruf','adkim','aspf','pct','fo','rf','ri']`, `'; '` separator) and have `DmarcRuaInstruction` delegate. **Keep an array-tag `rebuildRecord(array<string,string> $tags): string` method** so `DmarcRuaInstruction`'s merge path preserves arbitrary unknown customer tags (ri/rf/custom) and stays byte-identical (FIX for verification *j*). Add a typed `serialize(DmarcPolicy $p, ?DmarcPolicy $sp, int $pct, list<string> $ruaAddresses, ?DmarcAlignment $adkim = null, ?DmarcAlignment $aspf = null, ?string $fo = null): string` for the fully-controlled hosted policy (omit `pct` when 100, `sp` when null; `rua` = comma-joined `mailto:`+addr, no spaces; no `ruf`). Document the cosmetic ordering difference between the self-TXT default string (`…fo; adkim; aspf`) and the canonical array order (`…adkim; aspf; pct; fo`).

**3.2c `src/Services/Dns/ManagedDmarcPolicyComposer.php`** — autowirable; injects `ReportAddressProvider` + `DmarcRecordSerializer`. `compose(ManagedDmarcPolicy $policy): string` → serializes with `rua = [ReportAddressProvider::get()]` (**Sendvery-only**, per DEC-058), `adkim=r aspf=r fo=1`. This is the **expected content** both the publish event-handler and the sync-reconcile cron compute for content-drift comparison.

**3.2d Extend `DnsRecordPublisher` + `CloudflareDnsClient` for the full-policy TXT.** The policy content varies, so the new methods take the serialized content (caller composes it):

```php
public function publishPolicyRecord(string $customerDomain, string $policyContent): ?string;
public function removePolicyRecord(string $customerDomain): bool;
public function policyRecordExists(string $customerDomain): bool;
public function findPolicyRecord(string $customerDomain): ?CloudflareDnsRecord;   // for content-drift reconcile
```

In `CloudflareDnsClient`, **reuse `dnsRecordsUrl()`, `apiRequest()`, `findTxtRecord()`, `deleteRecordById()`, `isDuplicateError()`/`isNotFoundError()` verbatim** and add siblings:

- `buildPolicyRecordName(string $customerDomain): string` → `sprintf('%s._dmarc.%s', strtolower($customerDomain), $reportDomain)` (`_dmarc` infix, NOT `_report._dmarc`; same null-report-domain RuntimeException guard).
- `publishPolicyRecord()` — strict **upsert with a single-record invariant** (FIX for verification "second TXT" MEDIUM *i*): `findTxtRecord(name)` first; if absent → `POST`; if present and content matches → return id (no-op); if present and drifted → `PATCH {url}/{id}` with new content. **Never rely on `POST`→81057 to detect a changed-content duplicate** (Cloudflare allows multiple TXT at one name; two DMARC records = receiver permerror). Set an **explicit low `ttl => 1`** (Cloudflare "auto" / 300s) on every publish/PATCH so rollback propagates in minutes (FIX for verification TTL MEDIUM). Comment templated `'Managed DMARC policy for %s'`. If a duplicate set is ever detected, clean down to one record.
- `listPolicyRecords()` / `extractPolicyCustomerDomain()` — filter `contains:._dmarc.%s` **AND explicitly exclude any name containing `._report._dmarc`** (substring-collision: `acme.com._report._dmarc.sendvery.com` ends with `._dmarc.sendvery.com`). Suffix-strip uses `._dmarc.%s` *after* the `_report._dmarc` exclusion.

On any publish/PATCH/delete failure: `\Sentry\captureException()` + return null (id stays unset → retried by sync cron), exactly like the auth path but with observability added (FIX for verification "no Sentry" HIGH).

**3.2e `FakeDnsRecordPublisher`** — mirror the 4 new methods with a **separate in-memory map** `policyRecords[domain] => content`, id `'fake-cf-policy-'.md5($domain)`; honour `simulateFailure()/simulateSuccess()`; add `getPublishedPolicyContent(string $domain): ?string` for assertions. (Note: the Fake can't reproduce Cloudflare's duplicate-TXT behaviour, so the single-record invariant also needs a client-level/contract test — §5.)

**3.2f CNAME verification.** Extract `DkimChecker::lookupCnameTarget()` into `src/Services/Dns/CnameResolver.php` (`final readonly`, injects `Spatie\Dns\Dns`, `resolve(string $name): ?string`); `DkimChecker` delegates (DNS-mockable via the `App\Services` mock namespace). New `src/Services/Dns/ManagedDmarcCnameChecker.php` injects `CnameResolver` + `ReportAddressProvider`; `verify(string $customerDomain): CnameVerificationOutcome` resolves `_dmarc.<customer>` → `Verified` iff target (case-insensitive) `=== <customer>._dmarc.<reportDomain>`, `PointsElsewhere` if it resolves to something else, `Missing` if no CNAME. Wire into `DnsMonitor::check()` for managed domains (alongside the existing `DmarcReportAuthorizationChecker`) so the daily sweep keeps `cnameVerifiedAt` fresh; `CheckDomainDnsHandler` calls `markCnameVerified(...)`.

**3.2g Readiness engine.** New `src/Query/GetDomainReadinessSignals.php` → `src/Results/DomainReadinessResult.php` (`fromDatabaseRow`, team-scoped) returning, over a trailing window: aligned `passRate` (canonical fragment `SUM(CASE WHEN dkim_result='pass' OR spf_result='pass' THEN count ELSE 0 END)::float/NULLIF(SUM(count),0)*100`), `reportsCount`, `messageVolume`, `distinctSources` (`COUNT(DISTINCT source_ip)`), `daysOfData` (from `MonitoredDomain.firstReportAt`, survives purge), `authorizedFailureVolume` (recent failing volume from authorized `known_sender` rows — compose `GetTopFailingSenderForTeam`/`GetSenderActivity30Day` with `known_sender.is_authorized`). New `src/Services/Dns/DmarcRampReadinessEvaluator.php` → `src/Results/RampReadinessResult.php` consumes the entity (current stage *derived from `managedPolicyP`*, `cnameVerifiedAt`, `lastPolicyChangeAt`) + the signals; outputs `currentStage`, `ready: bool`, `recommendedNextPolicy: ?ManagedDmarcPolicy`, `eligibleForNextTier`, `list<string> blockingReasons`.

**Auto-ramp uses the stricter thresholds** (reconciling the two divergent existing sources): none→quarantine `passRate ≥ 95% && daysOfData ≥ 30 && reportsCount ≥ 3 && distinctSources ≥ 2 && authorizedFailureVolume == 0`; quarantine→reject `passRate ≥ 99% && daysOfData ≥ 60`. Plus gates: `cnameVerifiedAt != null`, dwell `now - lastPolicyChangeAt ≥ 7 days`, not paused. The **manual hint** (dashboard layer-1/2 nudges) keeps reusing `DmarcPolicyAdvisor` (softer 90/95). DEC-058 records the divergence (looser advice vs stricter auto-action) as intentional.

### 3.3 Commands / handlers / events

All commands `readonly final` (`src/Message/`); handlers `#[AsMessageHandler] readonly final` (`src/MessageHandler/`) injecting `EntityManagerInterface` + `ClockInterface`, **never flushing**; events `readonly final` (`src/Events/`). **Tenant safety (FIX for verification "cross-tenant write" HIGH *f*):** user-dispatchable handlers load via `MonitoredDomainRepository::findForTeams($message->domainId, [Uuid::fromString($message->teamId)])` and 404/throw on null (like `SetDomainDkimSelectorHandler`) — **never `get()`** for user-facing commands. `get()` is reserved for cron-only commands that carry no `teamId`. `AdvanceDmarcPolicy` is dispatched by both user and cron, so it keeps `teamId` and the cron passes the domain's own `team_id`.

| Command | Handler action |
|---|---|
| `EnableManagedDmarc(domainId, teamId, actorUserId)` | seed `ManagedDmarcPolicy` from the live DMARC record (enforcement-preserving; §2.6a), `$domain->enableManagedDmarc($seed, now)` → `ManagedDmarcEnabled`; if a CNAME is already live, also `markCnameVerified`. |
| `SetDmarcPolicy(domainId, teamId, actorUserId, DmarcPolicy p, ?DmarcPolicy sp, int pct)` | `changeManagedPolicy(new ManagedDmarcPolicy(p,sp,pct), Manual, actor, now)` → `DmarcPolicyChanged` (only if changed). Layer 1 + the rollback target. |
| `AdvanceDmarcPolicy(domainId, teamId, ?actorUserId, source)` | re-evaluate readiness server-side; if `eligibleForNextTier` → `changeManagedPolicy(recommendedNextPolicy, source, actor, now)`; else no-op. Layer 2 (Guided) AND the auto-ramp executor (AutoRamp). Idempotent. |
| `ConfigureDmarcAutoRamp(domainId, teamId, action, ?goalStage)` | enable/disable/pause/resume/set-goal → `enableAutoRamp`/`disableAutoRamp`/`pauseAutoRamp`; records `AutoRampEnabled`/`AutoRampDisabled`. |
| `ScheduleAutoRampAdvance(domainId, AutoRampStage to, \DateTimeImmutable at)` | cron-only (no teamId) → `scheduleAutoRampAdvance` → `AutoRampAdvanceScheduled`. |
| `PauseAutoRamp(domainId, string reason)` | cron/safety-only → `pauseAutoRamp` → `AutoRampPaused(reason)`. |
| `DisableManagedDmarc(domainId, teamId, actorUserId)` | `disableManagedDmarc(now)` → `ManagedDmarcDisabled(domainId, teamId, domainName, hostedRecordId)`. |

**Event handlers (DNS side-effects, mirroring `PublishAuthorizationRecordWhenDomainAdded`; reload via `em->find(...)` + null-guard; rely on `event_bus` transaction):**

| Event | Handler |
|---|---|
| `ManagedDmarcEnabled` | `PublishHostedDmarcRecordWhenManagedEnabled` — compose seed content, `publishPolicyRecord($domain->domain, $content)`, write `cloudflareHostedDmarcRecordId`. **Publish-first** (target exists before the CNAME → no NXDOMAIN window). Sentry on failure. |
| `DmarcPolicyChanged` | `UpdateHostedDmarcRecordWhenPolicyChanged` — recompose, `publishPolicyRecord()` (upsert/PATCH), refresh id. **Single republish path** for set/advance/rollback/freeze. Sentry on failure. |
| `DmarcPolicyChanged` | `RecordManagedDmarcPolicyChange` — persist the audit `ManagedDmarcPolicyChange` row. |
| `ManagedDmarcDisabled` | `RemoveHostedDmarcRecordWhenManagedDisabled` — re-verify CNAME; delete the hosted TXT **only if the CNAME no longer points at us**, else defer to the sync cron (dangling-safe). |
| `AutoRampAdvanceScheduled` | `NotifyTeamWhenAutoRampScheduled` — 48h advance-notice email (dedicated handler/template). |
| `AutoRampPaused` | `NotifyTeamWhenAutoRampPaused` — "we paused your ramp (reason)" email. |
| `DmarcPolicyChanged` | `NotifyTeamWhenPolicyAdvanced` — confirmation email when an enforcing tier is published. |

### 3.4 Queries / results (dashboard)

- `GetDomainReadinessSignals` + `DomainReadinessResult` (§3.2g).
- `src/Results/ManagedDmarcCardResult.php` — assembled **in `ShowDomainDetailController`** from the loaded `MonitoredDomain` + `RampReadinessResult`: `setupMode`, `managedPolicy(p/sp/pct)`, `autoRampEnabled`, `autoRampStage`, `scheduledStage`, `scheduledAdvanceAt`, `cnameVerifiedAt`, `lastPolicyChangeAt`, `hostedRecordPresent (bool)`, `ready`, `recommendedNextPolicy`, `blockingReasons`, `frozen (bool)` (downgrade), `pendingPublish (bool)` (Preparing state).
- New `GetManagedDmarcPolicyHistory` query → list of audit rows for the "Recent changes" panel.
- `ShowDomainDetailController` also passes `managedDmarcAvailable` (entitlement) + `dnsAutomationConfigured`.

### 3.5 Managed-aware DNS pipeline (FIX for verification "spurious alerts" HIGH)

The daily `sendvery:dns:check-all` runs `DmarcChecker` at `_dmarc.<domain>`; for managed domains the resolver follows the CNAME to the hosted TXT, so the check sees a record Sendvery itself owns. To avoid spurious customer alerts when *we* change the policy:

- Annotate the DMARC `DnsCheckResult.details` with `managed = true` (and/or add `DnsCheckType::ManagedCname` for the CNAME check) in `DnsMonitor`/`CheckDomainDnsHandler`.
- **Suppress/relabel `DnsRecordChanged` and policy-recommendation (`RecommendPolicyUpgrade`) alerts for managed domains** — Sendvery made the change; nagging the customer to "tighten" a domain we already manage is wrong.
- Surface managed status in `DomainSetupStatus`, `DmarcPolicyExplainer`, and the public checker so the pipeline narrates "managed by Sendvery" instead of "drift".

### 3.6 Cron

**`sendvery:dmarc:auto-ramp`** (`src/Command/AutoRampDmarcCommand.php`) — daily, idempotent, `#[AsCommand]`, `SymfonyStyle`, returns `Command::SUCCESS`. Guards `if (!$cloudflareClient->isConfigured()) { $io->info('…skipping'); return SUCCESS; }` (self-hosted bypass). For each domain where `dmarcSetupMode === ManagedCname && autoRampEnabled` **AND the team still has the `managed_dmarc` entitlement** (JOIN current plan — defense in depth for downgrades, FIX for verification *h/k*), **wrapped in per-domain try/catch** (capture to Sentry + continue, so one domain can't abort the sweep):

1. `DmarcRampReadinessEvaluator->evaluate()`.
2. **Regression / safety first.** CNAME lost, or `passRate` below the current tier's safe floor, or `authorizedFailureVolume` spiked → dispatch `PauseAutoRamp(reason)`. Hard regression at an enforcing tier with authorized mail being blocked → dispatch `AdvanceDmarcPolicy`-inverse rollback via `SetDmarcPolicy(previousTier, source=Rollback)` (loosening is instantly safe) + `PauseAutoRamp`. A failed rollback publish gets a **bounded immediate retry** (not a 24h wait) + Sentry + a Critical ops alert.
3. If `autoRampScheduledAdvanceAt` set and `now >= it`: re-check `ready`; still ready → `AdvanceDmarcPolicy(source=AutoRamp)`; else clear schedule + `PauseAutoRamp('readiness regressed before scheduled advance')`.
4. Else if `ready && schedule empty && dwell satisfied` → `ScheduleAutoRampAdvance(stage->next(), now + 48h)` (fires the 48h notice email).
5. Re-runs converge (schedule set once; advance only at fire-time after a fresh re-check).

Crontab (`~/www/spare.srv/deployment/crontab`, `## Sendvery` block, `sentry-cli monitors run`): `30 5 * * *` — monitor slug **`sendvery-dmarc-auto-ramp`** (05:30 — after 03:00 `dns:check-all` refreshes `cnameVerifiedAt`/snapshots, clear of the 04:xx purge window).

**`sendvery:dmarc:sync-hosted-records`** (`src/Command/SyncHostedDmarcRecordsCommand.php`) — a **sibling** (separate slug/concern; avoids the `_report._dmarc`/`_dmarc` collision in one loop). Mirrors create/reconcile/delete-stale but with **content-drift reconcile** (the auth sync only compares id, never content): per managed domain compute expected content (`ManagedDmarcPolicyComposer`), compare to `findPolicyRecord()` — missing → publish + store id (recovers dropped `ManagedDmarcEnabled` side-effects); content drift → re-publish (PATCH); id mismatch → reconcile. **Dangling-safe teardown:** for `SelfTxt` domains with a lingering `cloudflareHostedDmarcRecordId`/`hostedDmarcTeardownAt`, re-verify the CNAME and delete the hosted TXT **only once it no longer points at us**; past a max grace window still pointing → alert ops (never silently break live DMARC). `listPolicyRecords()` orphans with the CNAME gone → `deleteRecordById`. `isConfigured()` guard; per-domain try/catch + Sentry. Crontab `45 5 * * *` — slug **`sendvery-dmarc-sync-hosted-records`**. Append both lines to the CLAUDE.md "Crons" list.

**Deployment crontab is a SEPARATE git repo — edit *and push* it.** Both `sentry-cli monitors run …` lines go in `~/www/spare.srv/deployment/crontab` under the existing `## Sendvery` block. That path is a **different git repository from the dmarc app repo**, and the deployment host pulls *that* repo — so editing the file is not enough: the change must be **committed and `git push`ed inside `~/www/spare.srv`** as its own commit. This is an explicit, easy-to-forget step, re-stated in TASK-189/190. Mirror the existing entries' exact shape (the `## Sendvery` block already wraps each command in `docker compose run --rm worker bin/console …` under `sentry-cli monitors run --monitor-slug <slug>`).

### 3.7 Entitlement

Service-layer gate (NOT a voter):

- `PlanLimits::hasFeature()` add arm `'managed_dmarc' => SubscriptionPlan::Free !== $plan` (`Unlimited` short-circuits true at the top). Update `PlanLimitsTest` + `docs/05-monetization.md` (test wins on divergence; keep the `*Ai` `LogicException('Unreachable')` shape if you touch match arms).
- `PlanEnforcement::canUseManagedDmarc(SubscriptionPlan $plan): bool` → delegates to `hasFeature($plan, 'managed_dmarc')`.
- **Availability** = `cloudflareClient->isConfigured() && planEnforcement->canUseManagedDmarc($plan)`. No Cloudflare → managed physically unavailable regardless of plan.
- **Self-hosted bypass:** no `self_hosted` flag is invented. A self-hoster who configures their own Cloudflare zone runs their team on the existing **`Unlimited` staff-grant** (short-circuits `hasFeature` to true) — the same mechanism by which AGPL self-hosters get every paid feature free.
- Controllers gate like `AddDomainController` (resolve `GetTeamPlan->forTeam()` → `canUseManagedDmarc()` → on false render the `nextTier()` upgrade nudge). Commands additionally hard-refuse (PlanGatedAiInsightsService-style assert) as defense-in-depth so a forged POST can't enable managed on Free.
- **Premium positioning + forward-compatible split (auto-drive).** Auto-drive is the marketing hero (§2). The Free-plan upgrade nudge and the disabled auto-ramp control name auto-drive specifically (*"Upgrade to let Sendvery drive you to full enforcement automatically"*), and the active auto-ramp control carries a `Premium` badge. Keep a single `managed_dmarc` feature for v1, but route every check through `PlanEnforcement::canUseManagedDmarc()` and introduce a thin `PlanEnforcement::canUseDmarcAutoRamp()` (delegating to the same feature for now) so auto-drive can later be gated to a *higher* paid tier than manual managed control by changing only the `PlanLimits` arm + that one method — no call-site churn.

### 3.8 Lifecycle & safety rails (summary)

- **Enable** → publish hosted TXT **first** (p=none or the seeded enforcing tier; harmless) so the CNAME target exists; ensure the `_report._dmarc` auth record exists (already automated; sync backstops); show the immutable-CNAME instruction; `dns_verify_poll` → verify route runs `ManagedDmarcCnameChecker` → sets `cnameVerifiedAt`. Auto-ramp is gated on `cnameVerifiedAt`.
- **Before propagation** → whatever is live keeps governing DMARC; no tightening (gated); republishing identical content is a no-op (content compare) → idempotent.
- **Offboarding (dangling-safe)** → `DisableManagedDmarc` flips mode, turns off auto-ramp, sets `hostedDmarcTeardownAt`, **keeps** the hosted TXT; teardown only after the CNAME is gone. Hosted TXT lives in *our* zone → no takeover vector; only the Sendvery-owned record is ever deleted — never the customer's reports/domain/CNAME.
- **Regression** → soft → `PauseAutoRamp` + alert (no auto-loosen); hard → `SetDmarcPolicy(previousTier, Rollback)` + `PauseAutoRamp` (republish via `DmarcPolicyChanged`). Low TTL makes rollback propagate in minutes.
- **Idempotency** → every transition emits only on real change; `publishPolicyRecord` upserts via content compare; cron schedules once and advances only at fire-time after a fresh re-check.

---

## 4. Test plan (100% coverage)

`FakeDnsRecordPublisher` + `MockClock` (`getClock()`) + the real `IdentityProvider` seam; tests never hit a real external API; never assert raw Tailwind classes (only semantic daisyUI tokens for business rules). Test names describe behaviour.

**Unit (`tests/Unit`):**
- `DmarcRecordSerializerTest` — *"serializes a full reject policy in canonical tag order"*, *"omits pct when 100 and sp when null"*, *"joins multiple rua with mailto and no spaces"*, *"emits fo when supplied"*, *"DmarcRuaInstruction output is byte-identical after delegating to the array-tag serializer"*, *"preserves unknown customer tags through the array path"*.
- `ManagedDmarcCnameCheckerTest` (FakeDns) — verified / points-elsewhere / missing; case-insensitive + trailing-dot.
- `DmarcRampReadinessEvaluatorTest` — *"blocks advance on thin data even at high pass rate"*, *"requires a verified CNAME before recommending any tightening"*, *"enforces 7-day dwell"*, *"none→quarantine needs 95% over 30 days and ≥2 sources"*, *"quarantine→reject needs 99% over 60 days"*, *"flags regression when an authorized source starts failing"*, *"reject is terminal"*, *"derives current stage from the published policy"*.
- `AutoRampStageTest` / `ManagedDmarcPolicyTest` — ladder, `next()/previous()/fromPolicy()/targetPolicy()`, `equals()`.
- `PlanLimitsTest` (extend) — *"managed DMARC is available on every paid plan but not Free"*, *"Unlimited staff grant has managed DMARC"*.
- `MonitoredDomainTest` — *"changeManagedPolicy records DmarcPolicyChanged only when content actually changes"*, *"enableManagedDmarc seeds the policy from the live record"*, *"enableManagedDmarc is a no-op event-wise when already managed"*, *"disableManagedDmarc keeps the hosted record id for safe teardown"*, *"rollback resets autoRampStage to match the published policy"*.

**Integration / functional (`tests/Integration`):**
- Handlers (FakeDnsRecordPublisher, MockClock): `EnableManagedDmarcHandlerTest` *"publishes the seeded hosted TXT and stores its id"* + *"preserves an existing quarantine policy on switchover"*; `SetDmarcPolicyHandlerTest` *"publishing quarantine updates the hosted TXT content"* (assert `getPublishedPolicyContent`); `AdvanceDmarcPolicyHandlerTest` *"refuses to advance when not ready"* / *"advances to the recommended tier when ready"*; `DisableManagedDmarcHandlerTest` *"defers hosted-TXT deletion while the CNAME still points at us"* + *"deletes once the CNAME is gone"*; `PublishHostedDmarcRecordWhenManagedEnabledTest` *"id stays null and is retried by sync on publish failure"* (`simulateFailure`); `RecordManagedDmarcPolicyChangeTest` *"writes an audit row with actor and source"*.
- **Client contract test** (non-Fake, FIX for verification *i*): assert `publishPolicyRecord` with changed content issues a `PATCH` and leaves **exactly one** TXT at the policy name (GET→PATCH, never POST-on-change). Mock `HttpClientInterface` (defense in depth — never a real Cloudflare call).
- `AutoRampDmarcCommandTest` (CommandTester, MockClock): *"schedules a 48h advance with notice when a domain becomes ready"*, then **advance clock 48h** → *"executes the scheduled advance only if still ready"*, *"pauses the ramp on regression instead of tightening"*, *"rolls back and pauses on hard regression at an enforcing tier"*, *"skips a domain whose team lost the entitlement"*, *"continues past a failing domain"*, *"skips entirely when Cloudflare is not configured"* (override `CLOUDFLARE_*` to empty — `.env.test` sets them true by default).
- `SyncHostedDmarcRecordsCommandTest`: *"republishes a hosted TXT whose content has drifted"*, *"recreates a missing hosted TXT"*, *"deletes a stale hosted TXT once its CNAME is gone"*, *"never deletes a hosted TXT while the CNAME still points at us"*, *"does not cross-match _report._dmarc authorization records"*.
- Controllers (WebTestCase + PersonaBuilder + `loginUser`): *"Free plan sees an upgrade nudge instead of the managed toggle"*, *"Pro plan can enable managed DMARC"*, *"a forged POST for another team's domain is rejected"* (tenancy), CSRF enforcement on each write route, *"managed card is hidden when Cloudflare is unconfigured"*.
- Managed verify route: three-state functional test — *Verified* (success), *Missing* (keep polling), *PointsElsewhere* ("your _dmarc points to <other>"), plus *"blocks enable while a coexisting _dmarc TXT is live"*.
- Emails (mailer collector): assert each of the six managed touchpoints sends the expected message (subject + recipient) and that regression/dangling also create a Critical in-app `Alert`.
- Downgrade: `DowngradeTeamPlanHandlerTest` (extend) *"pauses auto-ramp on the team's managed domains and leaves the policy live"*.
- Demo seed: `SeedDemoDataCommandTest` (extend) *"seeds one managed, mid-ramp domain"*.

---

## 5. Ordered build sequence

Each task lists scope, files, acceptance criteria, and the **quality gates to run after every change**: `docker compose exec app vendor/bin/phpunit`, `docker compose exec app vendor/bin/phpstan`, `docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff`. Per CLAUDE.md these run on **every** task (not just the last); coverage stays at 100% incrementally, so every new command/controller/cron ships with its tests in the same task.

### TASK-174 — Phase 0: plan doc + DEC-058 + failing test skeletons
**Scope:** commit this plan as `docs/15-managed-dmarc-plan.md`; add **DEC-058** to `docs/07-decisions-log.md` above the marker; create the failing test files (names from §4). **Files:** `docs/15-managed-dmarc-plan.md`, `docs/07-decisions-log.md`, `tests/Unit/**`, `tests/Integration/**` skeletons. **AC:** doc + DEC committed; the full test list exists (red); phpstan/cs-fixer clean on skeletons.

### TASK-175 — Centralize report-domain + extract CnameResolver
**Scope:** `ReportAddressProvider::getReportDomain()`; refactor `CloudflareDnsClient`/`DmarcReportAuthorizationChecker` to use it; extract `DkimChecker::lookupCnameTarget` → `CnameResolver` (delegate). **Files:** `src/Services/ReportAddressProvider.php`, `src/Services/Dns/{CloudflareDnsClient,DmarcReportAuthorizationChecker,DkimChecker,CnameResolver}.php`. **AC:** existing DNS tests green; no behaviour change; duplication removed; gates green.

### TASK-176 — Value objects + serializer
**Scope:** `DmarcSetupMode`, `AutoRampStage` (+`next/previous/fromPolicy/targetPolicy`), `ManagedDmarcPolicy` (+`equals`), `CnameVerificationOutcome`, `PolicyChangeSource`, `DmarcRecordSerializer` (extract `rebuildRecord` keeping the array-tag path; add typed `serialize`); `DmarcRuaInstruction` delegates. **Files:** `src/Value/Dns/*`, `src/Value/Dns/DmarcRuaInstruction.php`. **AC:** serializer unit tests pass; `DmarcRuaInstruction` byte-identical (incl. unknown-tag preservation); gates green.

### TASK-177 — Entity fields + migration
**Scope:** 13 `MonitoredDomain` fields (property-initialized, ORM defaults declared) + guarded transition methods; `Version20260628120000` (columns + `managed_dmarc_policy_change` table). **Files:** `src/Entity/MonitoredDomain.php`, `migrations/Version20260628120000.php`. **AC:** `MonitoredDomainTest` transition/idempotency tests pass; schema builds in test DB; **`doctrine:schema:validate` green**; gates green.

### TASK-178 — Publisher extension + composer
**Scope:** 4 policy methods on `DnsRecordPublisher`; `CloudflareDnsClient` upsert (GET→PATCH→POST, single-record invariant, explicit `ttl=1`, `_report._dmarc` exclusion, Sentry on failure); `FakeDnsRecordPublisher` (separate map + `getPublishedPolicyContent`); `ManagedDmarcPolicyComposer` (Sendvery-only rua). **Files:** `src/Services/Dns/{DnsRecordPublisher,CloudflareDnsClient,FakeDnsRecordPublisher,ManagedDmarcPolicyComposer}.php`. **AC:** content-drift upsert + substring-collision exclusion covered; **client contract test asserts changed content updates in place leaving exactly one TXT**; gates green.

### TASK-179 — Commands / handlers / events + DNS side-effects
**Scope:** all §3.3 commands/handlers (tenant-scoped `findForTeams` for user commands; `get()` only for cron commands; enforcement-preserving enable seeding; actor/source threading; hard-refuse on non-paid) + events + publish/update/remove event handlers (publish-first; Sentry on failure; single republish path). **Files:** `src/Message/*`, `src/MessageHandler/*`, `src/Events/*`. **AC:** handler tests (FakeDnsRecordPublisher + MockClock) green; no `flush()`; tenancy-rejection test passes; gates green.

### TASK-180 — Audit trail
**Scope:** `ManagedDmarcPolicyChange` ORM entity + `RecordManagedDmarcPolicyChange` handler on `DmarcPolicyChanged`; `GetManagedDmarcPolicyHistory` query + result DTO. **Files:** `src/Entity/ManagedDmarcPolicyChange.php`, `src/MessageHandler/RecordManagedDmarcPolicyChange.php`, `src/Query/GetManagedDmarcPolicyHistory.php`, `src/Results/ManagedDmarcPolicyChangeResult.php` (migration table already in TASK-177). **AC:** audit row written with from/to/source/actor; history query returns ordered rows; gates green.

### TASK-181 — CNAME verification wiring
**Scope:** `ManagedDmarcCnameChecker` (three outcomes); integrate into `DnsMonitor::check()` + `CheckDomainDnsHandler` to set `cnameVerifiedAt`; add the live coexisting-TXT check used by the verify route. **Files:** `src/Services/Dns/{ManagedDmarcCnameChecker,DnsMonitor}.php`, `src/MessageHandler/CheckDomainDnsHandler.php`. **AC:** outcomes covered via FakeDns; `cnameVerifiedAt` flips on the sweep; gates green.

### TASK-182 — Managed-aware DNS pipeline + alert suppression
**Scope:** annotate DMARC `DnsCheckResult.details` with `managed=true` (and/or `DnsCheckType::ManagedCname`); suppress `DnsRecordChanged` + policy-recommendation alerts for managed domains; surface managed status in `DomainSetupStatus`/`DmarcPolicyExplainer`/public checker. **Files:** `src/Services/Dns/DnsMonitor.php`, the alert-creation handlers, `src/Value/DnsCheckType.php` (if added), `templates/components/{DomainSetupStatus,DmarcPolicyExplainer}.html.twig`. **AC:** *"no DnsRecordChanged alert when Sendvery changes a managed policy"* test passes; gates green.

### TASK-183 — Readiness engine
**Scope:** `GetDomainReadinessSignals` + `DomainReadinessResult`; `DmarcRampReadinessEvaluator` + `RampReadinessResult` (strict auto thresholds; cname/dwell/regression/distinct-source gates; stage derived from published policy). **Files:** `src/Query/GetDomainReadinessSignals.php`, `src/Results/{DomainReadinessResult,RampReadinessResult}.php`, `src/Services/Dns/DmarcRampReadinessEvaluator.php`. **AC:** evaluator tests cover every gate + thin-data/regression; gates green.

### TASK-184 — Entitlement
**Scope:** `managed_dmarc` in `PlanLimits` + `PlanLimitsTest`; `PlanEnforcement::canUseManagedDmarc`; `docs/05` matrix. **Files:** `src/Services/Stripe/{PlanLimits,PlanEnforcement}.php`, `docs/05-monetization.md`. **AC:** paid-vs-Free-vs-Unlimited tests pass; gates green.

### TASK-185 — AlertType cases + severity
**Scope:** add `ManagedDmarcRegression`, `ManagedDmarcDangling`, `ManagedDmarcAdvanced`, `ManagedDmarcReady` to `AlertType` (string-backed → no DB migration); severity map (regression/dangling = `Critical`; advanced/ready = informational); alert-list rendering. **Files:** `src/Value/AlertType.php` (+ severity mapper), alert-list template. **AC:** severity-mapping + rendering tests pass (semantic tokens only); gates green.

### TASK-186 — Notification emails
**Scope:** six dedicated email handlers + `templates/emails/managed_*.html.twig` (HTML + text alt, `MailerInterface`), wired to the §3.3 events / cron; create parallel in-app Alerts per §2.7. **Files:** `src/MessageHandler/Notify*` (managed), `templates/emails/managed_*.html.twig`. **AC:** mailer-collector tests assert each touchpoint sends; regression/dangling also create Critical Alerts; gates green.

### TASK-187 — Dashboard managed card + write controllers
**Scope:** `<twig:ManagedDmarcCard>` slotted after `<twig:DomainSetupStatus>`; `ManagedDmarcCardResult` + history assembled in `ShowDomainDetailController`; five controllers mirroring `SetDomainDkimSelectorController` (CSRF + UUID guard + plan gate) dispatching §3.3 commands; all card states incl. Preparing/Error/Frozen. **Files:** `templates/components/ManagedDmarcCard.html.twig`, `templates/dashboard/domain_detail.html.twig`, `src/Controller/Dashboard/{ShowDomainDetailController, EnableManagedDmarcController, SetDomainDmarcPolicyController, AdvanceDmarcPolicyController, ConfigureDmarcAutoRampController, SwitchManagedDmarcToSelfTxtController}.php`. **AC:** functional tests for gating, CSRF, instant publish, tenancy; no raw-Tailwind assertions; gates green.

### TASK-188 — Onboarding managed option + three-state verify
**Scope:** segmented self-TXT|managed control in option-1 card; `method='managed'` branch in `OnboardingIngestionController` (publish via command, not manual flush); pass `dnsAutomationConfigured`/`managedDmarcAvailable`; `onboarding_ingestion_managed_verify` route cloning the verify controller; three-state managed `_verify_panel` variant + live coexistence check; copy button via `clipboard_copy_controller`. **Files:** `templates/onboarding/{ingestion,_verify_panel}.html.twig` (+ managed variant), `src/Controller/Onboarding/{OnboardingIngestionController,OnboardingIngestionManagedVerifyController}.php`. **AC:** managed onboarding path + all three verify outcomes covered; skip-logic still correct for managed; gates green.

### TASK-189 — Auto-ramp cron
**Scope:** `AutoRampDmarcCommand` (`sendvery:dmarc:auto-ramp`): `isConfigured()` guard, entitlement re-check JOIN, per-domain try/catch + Sentry, schedule→advance→pause/rollback, bounded rollback retry. Crontab `30 5 * * *` + slug `sendvery-dmarc-auto-ramp`; CLAUDE.md crons list; **add the line to `~/www/spare.srv/deployment/crontab` and commit + `git push` that separate repo.** **Files:** `src/Command/AutoRampDmarcCommand.php`, CLAUDE.md, `~/www/spare.srv/deployment/crontab` (separate repo — must be pushed). **AC:** CommandTester tests across the 48h schedule, regression-pause, rollback, entitlement-skip, continue-past-failure, unconfigured-skip; gates green; the spare.srv crontab line is committed + pushed.

### TASK-190 — Hosted-records reconcile cron
**Scope:** `SyncHostedDmarcRecordsCommand` (`sendvery:dmarc:sync-hosted-records`): missing/drift/stale/dangling-safe teardown; `_report._dmarc` non-cross-match; `isConfigured()` guard; per-domain try/catch. Crontab `45 5 * * *` + slug `sendvery-dmarc-sync-hosted-records`; CLAUDE.md crons list; **add the line to `~/www/spare.srv/deployment/crontab` and commit + `git push` that separate repo.** **Files:** `src/Command/SyncHostedDmarcRecordsCommand.php`, CLAUDE.md, `~/www/spare.srv/deployment/crontab` (separate repo — must be pushed). **AC:** drift-republish, dangling-safe teardown, non-cross-match tests pass; gates green; the spare.srv crontab line is committed + pushed.

### TASK-191 — Downgrade freeze wiring
**Scope:** extend `DowngradeTeamPlanHandler` (or add `FreezeManagedDmarcWhenPlanDowngraded`) to `pauseAutoRamp` on the team's managed domains, flip the card read-only, dispatch the "managed frozen" notice — **never auto-loosen**. **Files:** `src/MessageHandler/DowngradeTeamPlanHandler.php` (or new handler). **AC:** functional test covers downgrade-while-mid-ramp (policy stays live, ramp paused); gates green.

### TASK-192 — Demo seed managed sample data
**Scope:** make one demo domain managed + verified + mid-ramp (e.g. `acme.example` at quarantine, auto-ramp on with a scheduled advance, `cnameVerifiedAt` set, a hosted record id); seed the FakeDnsRecordPublisher policy map in dev so the active card/readiness/auto-ramp controls populate. **Files:** `src/Command/SeedDemoDataCommand.php`. **AC:** demo surfaces render a populated managed card; seed test passes; gates green.

### TASK-193 — Docs finalize
**Scope:** fill `docs/15` "what landed" sections with test counts; add managed DMARC to `docs/03-features-roadmap.md`; document the 13 columns + audit table + enums in `docs/04-data-model-protocols.md`; note the two crons + Sentry monitors in `docs/02-architecture.md`; confirm `docs/05` + DEC-058. **Files:** `docs/{02,03,04,05,15}-*.md`, `docs/07-decisions-log.md`. **AC:** full suite green at `--coverage-min=100`; phpstan + cs-fixer clean.

---

## 6. Risks & open questions

- **R1 — Cloudflare duplicate-TXT footgun.** Cloudflare permits multiple TXT at one name; two DMARC records = receiver permerror = silently broken policy. Mitigated by the strict GET→PATCH→POST upsert + single-record invariant + a non-Fake client contract test (TASK-178). The `FakeDnsRecordPublisher` cannot reproduce this, so the contract test is the only guard — do not delete it.
- **R2 — Propagation vs. rollback speed.** Rollback safety depends on a low TTL (`ttl=1`/300s) and the daily cron cadence. A regression detected at 05:30 rolls back within the run + propagates in minutes, but a regression that appears mid-day waits up to ~24h for the next sweep. **Open question:** do we want an event-driven regression trip (e.g. on report ingestion) for enforcing tiers, or is the daily sweep acceptable for v1? (Plan assumes daily is acceptable; revisit with real data.)
- **R3 — Thin aggregate-only signal.** No forensic data; readiness rests on aggregate `policy_evaluated` + `known_sender.is_authorized`. If a customer hasn't authorized their senders, `authorizedFailureVolume` is uninformative. **Open question:** should auto-ramp require a minimum number of *authorized* senders, or treat "unknown but passing" as safe? (Plan currently gates on `distinctSources ≥ 2` + `authorizedFailureVolume == 0`; tune after launch.)
- **R4 — Retention purge bounds the window.** `sendvery:dmarc:purge` collapses `total_reports` to 0 after the plan's retention; the engine uses `firstReportAt` for true age but the 30/60-day pass-rate windows still need enough un-purged rows. Free isn't eligible (paid-only), and paid retention ≥ the windows, so this holds — but verify when adding shorter paid retention.
- **R5 — `reportDomain` only equals `sendvery.com` in prod.** The literal CNAME target is `…_dmarc.sendvery.com` only when `SENDVERY_REPORT_ADDRESS=reports@sendvery.com`; tests use `sendvery.test`. All copy/instructions must render `reportDomain` dynamically, never hardcode `sendvery.com`.
- **R6 — Switchover report continuity.** Enforcement-preserving enable carries `p/sp/pct` but moves `rua` to Sendvery-only (DEC-058). A customer who silently relied on an external `rua` loses it; the warning copy mitigates, but **open question:** do we want a one-time "your old tool will stop receiving reports — confirm" interstitial before enabling? (Plan shows a warning, not a hard interstitial.)
- **R7 — Two cron slugs, overlapping windows.** Auto-ramp (05:30) and sync (05:45) both mutate Cloudflare; they operate on disjoint record types but share rate limits. If the zone grows large, consider pagination/backoff (the existing `apiRequest` paginates lists already).

---

## 7. Proposed decision-log entry (stub for `docs/07-decisions-log.md`)

Insert above `*Add new decisions above this line*`:

```
### DEC-058: Managed DMARC — CNAME delegation, paid-only, additive auto-ramp
**Date:** 2026-06-28
**Status:** Decided
**Decision:** Offer "Managed DMARC" as a NEW, opt-in alternative to the self-TXT default
(no NS delegation). The customer sets ONE immutable CNAME `_dmarc.<domain>` →
`<domain>._dmarc.sendvery.com`; Sendvery publishes and MUTATES a full-policy TXT at that
target inside its own Cloudflare zone. The RFC 7489 `_report._dmarc` authorization record
is still required and stays automated — managed runs in addition to it. Policy control v1
is a fully-automatic ramp delivered as three ADDITIVE layers: (1) instant manual
selector (p/pct/sp), (2) one-click guided advance with a readiness recommendation,
(3) opt-in scheduled auto-ramp (none→quarantine→reject) with a 48h advance notice,
pause/opt-out, and safety rails (thin-data gates, regression detection, rollback).
Sub-decisions:
  (a) Hosted-record `rua` is Sendvery-ONLY. Switchover preserves enforcement strength
      (p/sp/pct carried forward) but does NOT keep an external rua — an external report
      destination cannot be authorized from a zone we don't control, and a second rua to a
      third party would silently fail. Customers who must keep an external rua stay on
      self-TXT; the managed-enable flow warns when it detects an external rua.
  (b) Auto-ramp uses STRICTER thresholds than the manual advisor (none→quarantine 95%/30d/
      ≥3 reports/≥2 sources/0 authorized-failures; quarantine→reject 99%/60d; plus verified-
      CNAME + 7-day dwell). The softer `DmarcPolicyAdvisor` (90/95) still drives manual
      "you could move up" hints. Looser advice vs. stricter automatic action is intentional.
  (c) Availability = paid plans only (`managed_dmarc` feature on PlanLimits, `Free !== plan`)
      AND `CloudflareDnsClient::isConfigured()`. Self-hosted operators who configure their own
      Cloudflare zone get managed via the existing `Unlimited` staff-grant; no `self_hosted`
      flag is invented. No Cloudflare → the option is hidden for everyone.
**Rationale:** CNAME delegation is the standard, low-risk way to host a customer's DMARC
without taking over their zone (the target lives in our zone — no subdomain-takeover vector).
Paid-only mirrors how every other convenience feature is gated. Stricter auto thresholds and
publish-before-CNAME / dangling-safe-teardown rails honor "never break a customer's live DMARC"
and "never delete user data." Sendvery-only rua is the only reliably-deliverable option.
**Alternatives considered:** NS delegation (rejected — takes over the whole zone, high blast
radius); multi-value rua incl. the customer's external address (rejected — fails silently
without a remote §7.1 authorization record); a single shared threshold set for advice and
automation (rejected — automation must be more conservative than a hint); a `self_hosted`
entitlement flag (rejected — `Unlimited` already covers it).
**Impact:** 13 new `MonitoredDomain` columns + a `managed_dmarc_policy_change` audit table
(`Version20260628120000`); new value objects/enums in `src/Value/Dns`; a full-policy publish
path on `DnsRecordPublisher`/`CloudflareDnsClient` (upsert via GET→PATCH→POST, low TTL,
`_report._dmarc` exclusion); `ManagedDmarcCnameChecker`; `DmarcRampReadinessEvaluator`;
five write controllers/commands; `<twig:ManagedDmarcCard>`; onboarding managed tab; six
transactional emails + four `AlertType` cases; two daily crons (`sendvery:dmarc:auto-ramp`,
`sendvery:dmarc:sync-hosted-records`) with Sentry monitors; downgrade-freeze wiring; demo seed;
`PlanLimits` `managed_dmarc` feature; docs 02/03/04/05/15.
---
```