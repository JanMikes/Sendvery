# Sendvery — CX & Feature Improvement Backlog

Single source of truth for the autonomous CX/feature improvement run.
One block per task, schema as documented in the orchestrator brief.

Tasks the user explicitly named are seeded first and prioritised over
discovered work. Status transitions: proposed → planned → in-progress →
in-review → done (or → blocked).

---

## TASK-001: Keep "DNS Health" nav link in-app and show user's domains' DNS health

- Status: done
- Shipped: 2026-05-22 (commits `3220a5f` impl + `d010bb1` post-review fixups)
- Area: dashboard
- Why: The current sidebar "DNS Health" link routes to `tools_domain_health`, the **public** anonymous lookup tool. A signed-in user clicking it leaves their dashboard and has to manually re-type a domain they already added. This is a confusing dead-end and feels like the app doesn't know who they are.
- Acceptance:
  - Sidebar "DNS Health" link points to a new in-app route (e.g. `dashboard_dns_health`) that stays inside `templates/dashboard/layout.html.twig`.
  - Default view lists all the team's monitored domains with their latest DNS health status (SPF, DKIM, DMARC, MX), summarised at a glance.
  - Clicking a domain drills into its full DNS-health detail (re-using existing `DashboardDomainHealthController` rendering where possible).
  - If the team has zero domains, the page shows an empty state with a clear "Add your first domain" CTA — not a public lookup form.
  - Sidebar "active" highlighting works for the new route.
  - No links from inside the dashboard point at the public `/tools/domain-health` tool.
  - 100% test coverage on any new controller / query.
- Notes:

### Architect plan (2026-05-22)

**Files to create:**
- `src/Query/GetDnsHealthOverview.php`
- `src/Results/DnsHealthOverviewResult.php`
- `src/Controller/Dashboard/DnsHealthOverviewController.php`
- `templates/dashboard/dns_health_overview.html.twig`
- `tests/Integration/Controller/DnsHealthOverviewTest.php`

**Files to modify:**
- `templates/dashboard/layout.html.twig` — line 105 (sidebar link)
- `templates/dashboard/domain_detail.html.twig` — line 32 (header action button)
- `templates/dashboard/domain_health.html.twig` — line 91 (empty-state CTA)

**Conventions confirmed:** Dashboard controllers are `final` (not readonly, AbstractController extension), single-action `__invoke()`, route name `dashboard_*`, URL `/app/*`. Queries are `readonly final`, inject `Connection`, return DTOs from `src/Results/` (`readonly final` with `fromDatabaseRow()` factory + array-shape docblock). Team scoping via `DashboardContext::getTeamIdStrings()` → `WHERE team_id IN (:teamIds)`.

**Data:** Neither `GetDomainOverview` nor `GetDomainVerificationStatus` covers per-domain verification + latest snapshot. Calling `GetDomainHealthHistory::latestForDomain()` per domain creates N+1. Solution: new `GetDnsHealthOverview` query with single `LEFT JOIN LATERAL` (PG16-supported):

```sql
SELECT
    md.id           AS domain_id,
    md.domain       AS domain_name,
    md.spf_verified_at,
    md.dkim_verified_at,
    md.dmarc_verified_at,
    dhs.grade       AS latest_snapshot_grade,
    dhs.score       AS latest_snapshot_score,
    dhs.spf_score   AS latest_spf_score,
    dhs.dkim_score  AS latest_dkim_score,
    dhs.dmarc_score AS latest_dmarc_score,
    dhs.mx_score    AS latest_mx_score,
    dhs.checked_at  AS latest_checked_at
FROM monitored_domain md
LEFT JOIN LATERAL (
    SELECT grade, score, spf_score, dkim_score, dmarc_score, mx_score, checked_at
    FROM domain_health_snapshot
    WHERE monitored_domain_id = md.id
    ORDER BY checked_at DESC
    LIMIT 1
) dhs ON true
WHERE md.team_id IN (:teamIds)
ORDER BY md.domain ASC
```

**`DnsHealthOverviewResult` fields:** `domainId: string`, `domainName: string`, `spfVerifiedAt: ?\DateTimeImmutable`, `dkimVerifiedAt: ?\DateTimeImmutable`, `dmarcVerifiedAt: ?\DateTimeImmutable`, `latestSnapshotGrade: ?string`, `latestSnapshotScore: ?int`, `latestSpfScore: ?int`, `latestDkimScore: ?int`, `latestDmarcScore: ?int`, `latestMxScore: ?int`, `latestCheckedAt: ?string`. Helpers: `isSpfVerified()`, `isDkimVerified()`, `isDmarcVerified()`, `hasSnapshot()`, `snapshotGradeColor()` (`A=text-success`, `B=text-info`, `C=text-warning`, else `text-error`).

**Controller:** `DnsHealthOverviewController` — `#[Route('/app/dns-health', name: 'dashboard_dns_health')]`, injects `DashboardContext` + `GetDnsHealthOverview`, renders `dashboard/dns_health_overview.html.twig` with `['domains' => $domains]`. Empty state handled by template — no controller-side redirect.

**Template:** Extends `dashboard/layout.html.twig`. Empty-state uses `<twig:EmptyState title="No domains yet" message="Add your first domain to start monitoring its DNS health." actionUrl="{{ path('dashboard_domain_add') }}" actionLabel="Add your first domain" />`. Non-empty: `grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4` of cards, each with domain name, optional grade badge (if `hasSnapshot()`), four status pills (SPF/DKIM/DMARC via `isXxxVerified()`; MX via `latestMxScore`: ≥80=success, 50-79=warning, <50=error, null=ghost "No data"), and "View details" link to `path('dashboard_domain_health', {id: domain.domainId})`.

**Sidebar (`layout.html.twig` lines 105-109):** Replace `href="{{ path('tools_domain_health') }}"` with `href="{{ path('dashboard_dns_health') }}"`; replace hardcoded inactive class with `{{ current_route == 'dashboard_dns_health' ? 'bg-primary text-primary-content' : 'text-base-content/70 hover:bg-base-300 hover:text-base-content' }}`. SVG icon unchanged.

**`domain_detail.html.twig` line 32:** Replace `path('tools_domain_health') ~ '?domain=' ~ domain.domainName` with `path('dashboard_domain_health', {id: domain.domainId})`. Label can stay "DNS Health Check" or become "Health Score".

**`domain_health.html.twig` line 91:** Remove the "Run Health Check" CTA button entirely. Replace with static muted text: "Health snapshots are generated automatically during the nightly DNS check."

**Tests (`tests/Integration/Controller/DnsHealthOverviewTest.php`):** 9 methods — `pageReturns200`, `pageShowsDomainName`, `pageShowsEmptyState` (via `withoutDomain()` persona), `pageShowsMultipleDomains` (via `addExtraDomain()`), `pageShowsGradeWhenSnapshotExists` (persist `DomainHealthSnapshot` via EntityManager), `pageShowsNoDataFallbackWithoutSnapshot`, `redirectsAnonymousToLogin`, `sidebarHighlightsDnsHealthWhenActive` (assert `bg-primary` class on the DNS Health `<a>`), `noDashboardPageLinksToPublicTool` (regression guard — no `/tools/domain-health` href in rendered sidebar). `RouteSmokeTest` auto-covers the new GET route.

**Security:** `WHERE md.team_id IN (:teamIds)` mandatory — team IDs from `DashboardContext` only.

**Build phases:** 1) DTO + Query, PHPStan clean. 2) Controller, confirm via `debug:router`. 3) Template, daisyUI v5 classes verified. 4) Sidebar + 2 existing template fixes. 5) Tests; phpunit + phpstan + cs-fixer all green.

---

## TASK-002: Dashboard overview needs a clear "what to do next" + "are we healthy?" surface

- Status: done
- Shipped: 2026-05-23 (commit `d9c0962`)
- Area: dashboard
- Why: User explicitly says: *"the dashboard has no sense of next step, current status, or whether things are OK."* Today the overview shows stats and trends but doesn't tell the user, in one glance, whether their setup is healthy and what the single most-important next action is. New users especially get lost.
- Acceptance:
  - Top of `templates/dashboard/overview.html.twig` shows a single **prominent health summary** ("All domains healthy" / "1 domain needs attention" / "Setup not finished") with an unmistakable colour cue (success / warning / error).
  - Directly below it: a **Next Action card** that picks the single highest-value step for this team right now (e.g. "Add your first domain", "Verify DNS for example.com", "Connect a mailbox to start receiving reports", "Review 3 critical alerts"). Card has a single primary CTA button.
  - Logic for "next action" lives in a testable service (e.g. `NextActionResolver`) returning a typed result object — not template-inline logic.
  - Empty / new-user state is graceful: instead of zeros and empty charts, show the onboarding next-action prominently and hide noisy empty widgets.
  - 100% test coverage on the resolver, including all branches (no domains / unverified / no reports yet / has alerts / fully healthy).
- Notes:

### Architect plan (2026-05-22)

**Architecture decision:** Pure-computation resolvers. They receive already-fetched data as method parameters (no query injection, no clock, no DB) — controller assembles inputs, resolver applies priority logic, returns typed result. Trivially unit-testable.

**Files to create:**
- `src/Value/NextAction.php` — backed enum: `AddDomain`, `VerifyDns`, `WaitForReports`, `ReviewAlerts`, `ConnectMailbox`, `AllHealthy`
- `src/Results/NextActionResult.php` — `readonly final` DTO: `NextAction $actionKey`, `string $title`, `string $description`, `string $ctaLabel`, `string $ctaRoute`, `array<string,string> $ctaRouteParams`, `string $severity` (success|warning|error|info)
- `src/Results/HealthSummaryResult.php` — `readonly final` DTO: `string $headline`, `string $severity` (success|warning|error), `int $domainsHealthyCount`, `int $domainsAttentionCount`, `int $domainsUnverifiedCount`, `int $domainsTotalCount`
- `src/Services/NextActionResolver.php` — `readonly final` service, no deps. Method: `resolve(array $domains, ?DomainVerificationStatusResult $verificationStatus, ?DomainVerificationSeverity $verificationSeverity, int $unreadCriticalAlertCount, int $quarantineCount, bool $hasMailbox): NextActionResult`
- `src/Services/HealthSummaryResolver.php` — `readonly final`, no deps. Method: `resolve(array $domains, ?DomainVerificationStatusResult $verificationStatus, ?DomainVerificationSeverity $verificationSeverity): HealthSummaryResult`
- `tests/Unit/Services/NextActionResolverTest.php` — pure unit tests, construct DTOs directly, no fixtures
- `tests/Unit/Services/HealthSummaryResolverTest.php` — pure unit tests

**Files to modify:**
- `src/Query/GetAlerts.php` — add `countUnreadCriticalForTeams(array $teamIds): int` with `WHERE team_id IN (:teamIds) AND is_read = false AND severity = 'critical'` (match existing `countUnreadForTeams` convention, including the `if ([] === $teamIds) return 0;` guard).
- `src/Controller/Dashboard/DashboardOverviewController.php` — extract `$verificationSeverity` into a local var (currently inlined at line ~102), inject `NextActionResolver`, `HealthSummaryResolver`, `MailboxConnectionRepository`. Compute `$hasMailbox = [] !== $this->mailboxRepository->findByTeam($teamId);`, `$unreadCriticalAlertCount = $this->getAlerts->countUnreadCriticalForTeams($teamIds);`, then call both resolvers. Pass `nextAction` + `healthSummary` to render.
- `templates/dashboard/overview.html.twig` — see template section below.
- `tests/Integration/Controller/DashboardPagesTest.php` — add 8 integration test methods (see test section).

**Priority chain (NextActionResolver, most urgent first — tie-breaks deterministic):**
1. `count($domains) === 0` → `AddDomain` (severity error). Title: "Add your first domain". CTA: "Add domain" → `dashboard_domain_add`.
2. `$verificationSeverity === Critical` → `VerifyDns` (severity error). Title: `sprintf('Verify DNS for %s', $verificationStatus->domainName)`. CTA: "Re-check DNS" → `dashboard_domain_reverify` with `{id: $verificationStatus->domainId}`. **Wins over alerts** — alerts are noise without working DMARC.
3. `$verificationSeverity === Warning || $verificationSeverity === Info` → `WaitForReports` (severity warning). Title: "Waiting for your first report". CTA: "Check DNS setup" → `dashboard_domains` (use `dashboard_domains` until TASK-001's `dashboard_dns_health` is merged, then update to `dashboard_dns_health`).
4. `$unreadCriticalAlertCount > 0` → `ReviewAlerts` (severity error). Title: `sprintf('Review %d critical alert%s', $n, $n === 1 ? '' : 's')`. CTA: "View alerts" → `dashboard_alerts`.
5. `!$hasMailbox && every domain has totalReports === 0` → `ConnectMailbox` (severity info). Title: "Connect a mailbox". CTA: "Connect mailbox" → `dashboard_mailboxes_add`. Suppressed if ANY domain has reports (central inbox is working).
6. Default → `AllHealthy` (severity success). Title: "Everything looks good". CTA: "View reports" → `dashboard_reports`.

**HealthSummaryResolver logic:**
- Domain is **healthy** when `passRate >= 90.0`; **attention** when `passRate < 90.0`.
- `$domainsUnverifiedCount`: 1 if `$verificationSeverity === Critical && $verificationStatus->dmarcVerifiedAt === null`, else 0. (Per-domain unverified count for multi-domain teams is a v2 enhancement once TASK-001's `GetDnsHealthOverview` lands.)
- Headlines:
  - `domainsTotalCount === 0` → "Setup not finished" / error
  - `domainsUnverifiedCount === domainsTotalCount && domainsTotalCount > 0` → "Setup not finished" / error
  - `domainsAttentionCount === 0 && domainsUnverifiedCount === 0` → "All domains healthy" / success
  - `domainsAttentionCount === 1` → "1 domain needs attention" / warning
  - `domainsAttentionCount > 1` → `sprintf('%d domains need attention', $n)` / warning

**Template change (`overview.html.twig`):**
- At very top of `{% block content %}`: `{% set isEmptyState = (nextAction.actionKey.value == 'add_domain') %}`
- Insert **Health Summary Banner** (rounded card, full-width header bar with `bg-success`/`bg-warning`/`bg-error`, headline + small counts row showing healthy/attention/unverified counts).
- Insert **Next Action Card** (card with `border-success/30`/`border-warning/30`/`border-error/30`/`border-info/30` border, action icon in tinted square, "Next step" eyebrow, title, description, `btn btn-sm btn-success`/`btn-warning`/`btn-error`/`btn-info btn-outline` CTA linking to `path(nextAction.ctaRoute, nextAction.ctaRouteParams)`).
- Wrap the existing verification banner (current lines 6–53), stats grid, trend chart, alerts block, and bottom two-column domain-health grid in `{% if not isEmptyState %}...{% endif %}`. This hides "0 domains / 0 reports / 0% pass rate" zero-state noise for new users.
- Icons per action key (inline SVG, daisyUI v5 compatible): globe (add_domain), shield-check (verify_dns), mail (connect_mailbox), bell (review_alerts), clock (wait_for_reports), checkmark-circle (all_healthy).
- daisyUI v5 only — no `dark:` prefix.

**Unit tests (NextActionResolver) — 15 cases:**
- `resolveAddDomainWhenNoDomains`
- `resolveVerifyDnsWhenDmarcNeverVerified`
- `resolveVerifyDnsWhenDmarcGoneMissing` (consecutiveFailures ≥ 3)
- `resolveVerifyDnsWinsOverAlerts` (Critical + alerts > 0)
- `resolveWaitForReportsWhenDmarcPublishedButNoReports` (Warning)
- `resolveWaitForReportsWhenSettlingWindowActive` (Info)
- `resolveWaitForReportsWinsOverConnectMailbox`
- `resolveReviewAlertsWhenCriticalUnread`
- `resolveConnectMailboxWhenNoMailboxAndNoReports` (every domain has totalReports = 0)
- `resolveAllHealthyWhenDomainHasReports`
- `resolveAllHealthyDoesNotRequireMailbox` (central inbox case)
- `resolveVerifyDnsContainsDomainNameInTitle`
- `resolveVerifyDnsCtaRouteParamsContainDomainId`
- `resolveReviewAlertsCountAppearsInTitle` (n=3)
- `resolveReviewAlertsUsesSingularForOne` (n=1, "Review 1 critical alert" — no trailing 's')

**Unit tests (HealthSummaryResolver) — 8 cases:**
- `resolveSetupNotFinishedWhenNoDomains`
- `resolveSetupNotFinishedWhenDomainUnverified`
- `resolveAllHealthyWhenSingleDomainAbove90`
- `resolveAllHealthyWhenMultipleDomainsAllAbove90`
- `resolveOneNeedsAttentionWhenPassRateBelow90`
- `resolveTwoNeedAttentionPlural`
- `resolveCountsAreCorrect` (3 domains: 2 healthy + 1 attention)
- `resolveUnverifiedCountOne`

**Integration tests (add to `DashboardPagesTest`):**
- `overviewShowsHealthSummaryBanner` — happy path, expect "All domains healthy" in body
- `overviewShowsNextActionCard` — happy path, expect ".card" and "Next step" eyebrow text
- `overviewShowsAddDomainForNewUser` — `withoutDomain()` persona, expect "Add your first domain"
- `overviewHidesStatsForNewUser` — `withoutDomain()` persona, expect no "DMARC Pass Rate" stat card visible
- `overviewShowsVerifyDnsNextAction` — domain with `dmarcVerifiedAt = null`, expect "Verify DNS for"
- `overviewShowsWaitForReportsNextAction` — domain verified ≥ 48h ago + no firstReportAt
- `overviewShowsReviewAlertsNextAction` — persist `Alert` entity (severity=critical, is_read=false), expect "Review" + "critical alert"
- `overviewAllHealthyShowsFullStats` — happy path, expect "DMARC Pass Rate" stat card present

**Edge cases (explicit):**
- One unverified domain + one healthy domain: `VerifyDns` wins; health summary shows "Setup not finished" (unverified count > 0).
- DMARC working but central inbox delivering reports → `hasMailbox=false` but totalReports > 0 → `AllHealthy` (suppress ConnectMailbox).
- `$hasMailbox` uses `MailboxConnectionRepository::findByTeam()` (existing). For v1 ORM hydration cost is fine — typical team has 0-1 mailboxes.
- Existing verification banner stays inside `{% if not isEmptyState %}` — it has a POST form ("Re-check now") that complements the next-action card's GET link.

**Build phases:** 1) Enum + DTOs, PHPStan green. 2) Two resolvers + unit tests, PHPStan + phpunit green. 3) `GetAlerts::countUnreadCriticalForTeams`. 4) Controller wiring. 5) Template + visual verify in browser. 6) Integration tests, 100% coverage gate. Final: phpunit, phpstan, cs-fixer all green; commit; push.

---

## TASK-003: Homepage hero misses the "what is this product" 5-second test

- Status: done
- Shipped: 2026-05-23 (commit `4e53b7d` + stale-price follow-up)

### Architect plan (2026-05-23)

**Scope:** ONLY the hero section (lines 31-53 of `templates/homepage/index.html.twig`) + `{% block meta_description %}` (line 4). Logo bar at lines 64-72 is TASK-004's territory. Sections 4+ below the fold stay untouched.

**Chosen copy (Candidate C — preserves the brand voice from `docs/09-design-and-branding.md`):**
- New kicker pill (above H1, replacing "Open source · AGPL-3.0"): `DMARC Monitoring · AI Insights · Open Source` with the green pulse dot pattern preserved.
- H1: **unchanged** ("Your domain sends email every day. / Do you know who else is?") — the spoofing hook is on-brand and memorable. The 5-second-test fix is in the kicker + subhead, not the headline.
- Subhead: `Sendvery automatically parses your <strong>DMARC reports</strong>, monitors your <strong>DNS health</strong> continuously, and explains everything with <strong>AI-powered insights</strong> — in plain English, not XML. Free for 1 domain. No DMARC PhD required.` Three `<strong>` spans bold-scan the three product capabilities.
- Primary CTA: `Get started free` → `path('auth_login')` (was "Check your domain now" → scroll-to). The funnel CTA must be primary above the fold.
- Secondary CTA: `Check your domain` → `#dns-checker` scroll-to. Preserves Stimulus action `{{ stimulus_action('scroll-to', 'scroll', 'click') }} data-scroll-to-target-value="dns-checker"` verbatim.
- Trust badge row (below CTAs, replacing the mislabeled "View on GitHub" button): three inline `text-sm text-base-content/50` spans with green checkmark SVG: "Open source · AGPL-3.0", "1 domain free forever", "Self-hostable". Plus a tertiary text link "See the source →" pointing at `https://github.com/janmikes/sendvery` (`target="_blank" rel="noopener"`).
- Hidden TODO comment above CTAs for future analytics wiring; `data-track="hero-cta-primary"` and `data-track="hero-cta-secondary"` attributes added now (forward-compatible stubs).

**New meta description (replacing `Free DMARC, SPF, DKIM checker tools…From $5.99/mo.`):**
`DMARC monitoring with AI-powered insights. Automatically parse DMARC reports, monitor DNS health, and get plain-English explanations. Open source, self-hostable. Free for 1 domain. From $4.99/mo.`

(Lead with category, name the three things it does, anchor price to current Personal annual = $4.99/mo. `og:description` auto-inherits via base.html.twig.)

**Visual structure:** keep centered single-column layout. The live DNS checker widget (Section 2) IS the product demo, so no right-column screenshot/illustration (would compete or duplicate). `<section class="relative overflow-hidden bg-gradient-to-br from-base-100 via-base-100 to-primary/5 py-24 lg:py-36">` wrapper preserved.

**Files to modify:**
- `templates/homepage/index.html.twig` — lines 4 (meta) + 31-53 (hero section). Nothing else.
- `tests/Integration/Controller/MarketingPagesTest.php` — add 9 new assertions; existing `homepageContainsHeroSection` (line 44, H1 unchanged) stays as-is.

**Tests to add (9):**
- `heroContainsPrimaryCtaToAuthLogin` — `section:first a.btn-primary` href contains `/login`, text "Get started free"
- `heroKickerContainsProductCategory` — kicker text contains "DMARC Monitoring" (copy-drift guard — the load-bearing addition)
- `heroSubheadMentionsDmarc` — first `<p>` in hero contains "DMARC"
- `heroSubheadMentionsDnsHealth` — contains "DNS health"
- `heroSubheadMentionsAiInsights` — contains "AI-powered insights"
- `metaDescriptionContainsCategoryAndPricing` — meta description contains "DMARC monitoring" + "$4.99"
- `heroDoesNotContainOldInternalGitHubLink` — no `a[href*="about_open_source"]` in hero section (regression guard)
- `heroTrustBadgesPresent` — hero body contains "Open source", "1 domain free forever", "Self-hostable"
- `heroSeeTheSourceLinkPointsAtGithub` — anchor in hero with text "See the source" has href starting with `https://github.com/`

**No controller change, no new components, no `HeroSection.html.twig` switch (homepage hero is inlined per existing pattern — leave that alone).**

**Coordination:** TASK-004 (logo bar) will touch lines 64-72; this task touches only lines 1-53. Clean merge boundary.

**Critical details:**
- `path('auth_login')` confirmed = `/login` from TASK-005 work.
- `og:description` auto-inherits from `meta_description` block (base.html.twig).
- Existing Organization JSON-LD (lines 6-26) untouched — `homepageHasStructuredData` test continues to pass.
- daisyUI v5 only, no `dark:` prefix.
- Test assertion approach uses `->text()` on stripped HTML, so `<strong>` wrappers don't break "contains DMARC" checks.
- Area: marketing
- Why: The current homepage hero in `templates/homepage/index.html.twig` opens with *"Your domain sends email every day. Do you know who else is?"* — a clever spoofing hook, but a first-time visitor learns nothing about **what Sendvery actually is** above the fold. The product category ("DMARC monitoring with AI insights, open source") is buried 8+ sections down. Conversion-optimised SaaS homepages name the category in the H1 or sub-headline so the visitor decides in under 5 seconds whether they're in the right place.
- Acceptance:
  - H1 still has a strong hook, but the sub-headline (or a small kicker line above the H1) explicitly states the product category: something like *"Continuous DMARC, SPF & DKIM monitoring with AI-powered insights. Open source. From $4.99/mo."*
  - The sub-headline mentions all three: **what** (DMARC / email auth monitoring), **how it's different** (AI insights + open source), and **price anchor** (from $X/mo) — at least the first two must be present in the first viewport on a 1280×800 desktop and on iPhone 14 width.
  - The secondary CTA "View on GitHub" currently points to `path('about_open_source')` (an internal page), which is misleading — change it to either the real `https://github.com/janmikes/sendvery` link **or** rename it to "See the source" / "Learn how" so the label matches its destination.
  - The "Open source · AGPL-3.0" pill stays as a tertiary trust signal, but is no longer the only category cue.
  - No regression to existing structured data, OG tags, or the in-page scroll-to-checker behaviour.
- Notes:

---

## TASK-004: Homepage "Trusted by the founder's own companies" logo bar is a conversion killer as written

- Status: done
- Shipped: 2026-05-23 (commit `7882623`)
- Variant: hybrid Option A — kept three founder-owned companies, reframed copy as "Already running on real production domains", linked each name to its site, dropped 40% opacity, added small honest footnote
- Area: marketing
- Why: The current logo strip in section 3 of the homepage labels itself literally as *"Trusted by the founder's own companies"* and then renders three text strings at 40% opacity (TheDevs.cz, SpeedPuzzling.com, FajneSklady.cz). The honesty is admirable but the framing tells a visitor "we have no real customers" which is the opposite of the trust signal the section is supposed to deliver. There are also no actual logo images — just opacity-40 text. Either commit to real visual logos OR replace the section with a more compelling trust signal until real customer logos exist.
- Acceptance:
  - Either:
    - **(A)** Replace text strings with real SVG/PNG logos for the three founder-owned companies, drop the apologetic copy, and reframe the heading neutrally — e.g. *"Already running on real production domains"* — with each logo linking to the corresponding company site (and a small *"Built by the founder of these"* footnote so it's still honest); **or**
    - **(B)** Replace the whole section with a "By the numbers" or "Why developers choose Sendvery" trust block: live counters (X domains protected, Y DMARC reports parsed last month, Z GitHub stars), or a single confident founder quote/photo block with the "I was deleting my own DMARC reports" origin story.
  - Whichever variant ships, the section reads as a *positive* trust signal, not a disclosure of "we have no customers yet".
  - Mobile layout works (single column or 2-col grid for logos, not a horizontal strip that overflows).
- Notes:

---

## TASK-005: Pricing page is a thin wrapper around the pricing component — no comparison table, no FAQ, no objection-handling

- Status: done
- Shipped: 2026-05-23 (commit `f123e1d`)
- Area: marketing
- Why: `templates/about/pricing.html.twig` renders the `<twig:PricingTable />` and a one-line Enterprise blurb — that's the entire page. There is no plan-feature comparison matrix, no pricing-specific FAQ, no "what's included in every plan", no annual-vs-monthly explainer, no refund/cancellation policy mention. Visitors who clicked "Pricing" are the highest-intent traffic and we're giving them the least content. Competitors (EasyDMARC, dmarcian) all have full comparison tables + 8-12 question FAQs on their pricing pages — they out-rank us for "dmarc monitoring pricing" partly because of this content depth.
- Acceptance:
  - Add a **plan-comparison table** below the pricing cards (rows: Domains, Reports/mo, Retention, Seats, DMARC + DNS, Real-time alerts, Blacklist, Sender inventory, Email HTML, API + webhooks, White-label PDF, AI Insights, Support). The data already lives in `docs/05-monetization.md` — port it. Render as a responsive HTML table that collapses to per-tier cards on mobile.
  - Add a **pricing-specific FAQ** with at least 8 questions covering: *Can I cancel anytime?* (link cancel-at-period-end policy), *Do you offer refunds?* (no, per memory `refund-and-cancellation-policy`), *Can I switch plans later?*, *What happens if I exceed limits?* (freeze, never delete — per memory `never-delete-user-data`), *Do you offer annual discounts?*, *Is there a free trial?*, *What payment methods do you accept?*, *Do you charge VAT?* (link to per-doc-05 sticker policy), *Why is self-hosting free?*, *How does the AI add-on work?*.
  - Add a small "**Why annual?**" callout explaining the "2 months free" math in plain English, since the savings story is the actual marketing headline (per `docs/05-monetization.md`).
  - Page must include a final CTA section with two routes ("Start free" → auth login, "Talk to us" → mailto Enterprise) — currently the page ends abruptly after the Enterprise blurb.
  - Update `<meta description>` to mention the annual savings ($12 / $48 / $120 per year) — the current copy says "from $0/mo" which buries the lede.
- Notes:

### Architect plan (2026-05-23)

**Strategy:** All-static, all-template. No controller change, no PHP data class. `ai_available` is already a Twig global from `PricingFlagsExtension`. Comparison values are hard-coded matching `docs/05-monetization.md`.

**TASK-012 coordination:** `templates/components/PricingTable.html.twig` is **OFF LIMITS** (TASK-012 owns the Free-CTA change there). All work lives in `templates/about/pricing.html.twig` + two new components.

**Files to create:**
- `templates/components/PricingComparisonTable.html.twig` — feature matrix
- `templates/components/PricingFaq.html.twig` — 10-question FAQ
- `tests/Integration/Controller/PricingPageTest.php` — 11 integration tests

**Files to modify:**
- `templates/about/pricing.html.twig` — restructure: hero header, `<twig:PricingTable />`, "Why annual?" callout, `<twig:PricingComparisonTable />`, `<twig:PricingFaq />`, final CTA section. Update `meta_description` block to lead with annual savings ($12 / $48 / $120 / yr).

**Tier names (match `PricingTable.html.twig` exactly):** Free, Personal, Pro, Business. Enterprise contact is `jan.mikes@sendvery.com` (confirmed at `PricingTable.html.twig:275`).

**Comparison matrix (from `docs/05-monetization.md`):**

| Feature | Free | Personal | Pro | Business |
|---|---|---|---|---|
| Domains | 1 | 5 | 20 | 50 |
| Reports/mo | 100 | 1,000 | 10,000 | 50,000 |
| Retention | 30 days | 1 year | 2 years | Unlimited |
| Seats | 1 | 1 | 3 | 10 |
| DMARC + DNS | ✓ | ✓ | ✓ | ✓ |
| Real-time alerts | — | ✓ | ✓ | ✓ |
| Blacklist | — | ✓ | ✓ | ✓ |
| Sender inventory | — | ✓ | ✓ | ✓ |
| Email HTML | — | ✓ | ✓ | ✓ |
| API + webhooks | — | — | ✓ | ✓ |
| White-label PDF | — | — | — | ✓ |
| AI Insights (when `ai_available`) | Contact us | ✓ 50/mo | ✓ 200/mo | ✓ 500/mo |
| Support | Community | Email | Priority | Priority + SLA |

When `ai_available` is false, all paid AI cells render `—` and a "coming soon" badge appears next to the row label. Support values derived from the "What you pay for" prose — **flag for product review**.

**Annual savings (exact):** Personal $12/yr ($4.99 vs $5.99), Pro $48/yr ($19.99 vs $23.99), Business $120/yr ($49.99 vs $59.99).

**`PricingComparisonTable.html.twig` markup:**
- Desktop variant (`hidden md:block`): `<table class="table table-zebra w-full">`, `<tbody>` grouped by separator rows with `bg-base-300/20` and uppercase tracking-wide labels: Limits / Features / Advanced / Support. Boolean checks use inline SVG checkmark (`text-success`), no-values use `<span class="text-base-content/30">—</span>`.
- Mobile variant (`md:hidden space-y-6`): four stacked `card bg-base-200/50 border border-base-300` cards, one per tier; `card-body p-4`, header `card-title text-base`, body is a `<dl>` with `dt`/`dd` flex-between rows.

**`PricingFaq.html.twig` markup:**
- `<div class="space-y-3 max-w-3xl mx-auto">` wrapper
- Each entry: `<div class="collapse collapse-plus bg-base-200/50 border border-base-300"><input type="checkbox" name="pricing-faq-N"><div class="collapse-title font-semibold">Q</div><div class="collapse-content text-base-content/70 text-sm"><p>A</p></div></div>`
- **Checkbox NOT radio** — users compare multiple answers; differs from `FaqAccordion.html.twig` which is radio-based.
- 10 questions (full answer copy per architect plan in agent output; key callouts):
  1. Cancel anytime? — period-end cancel, no immediate cutoff (per memory `refund-and-cancellation-policy`)
  2. Refunds? — no self-serve refunds, manual via Stripe only
  3. Switch plans later? — yes, prorated on upgrade, next-renewal on downgrade
  4. Exceed limits? — freeze ingestion, never delete (per memory `never-delete-user-data`)
  5. Annual discounts? — 2 months free
  6. Free trial? — no separate trial, Free plan is permanent
  7. Payment methods? — cards via Stripe; Apple/Google Pay where available; no invoicing on self-serve
  8. VAT? — currently no VAT (OSVČ below threshold); Stripe Tax will handle when needed
  9. Self-host free? — AGPL-3.0; hosted plans sell managed infra not a license
  10. AI Insights? — Claude-backed; quotas 50/200/500; phrasing deliberately vague re. rollout so it doesn't age badly (per memory `ai-stub-first-launch-posture`)

**"Why annual?" callout** — inline in `pricing.html.twig`, between the `<twig:PricingTable />` and the comparison table. `alert bg-success/10 border border-success/20 text-sm` daisyUI v5 alert with a calendar SVG, heading "Annual billing = 2 months free, every year", body listing all three savings figures with separators.

**Final CTA section** — `<twig:SectionContainer bgClass="bg-base-200/30">` with headline "Ready to protect your domain?", body "Start free with 1 domain and no credit card. Upgrade when you need more — cancel anytime.", two buttons: "Start free" (`btn btn-primary btn-lg` → `path('auth_login')`) and "Talk to us" (`btn btn-ghost btn-lg` → `mailto:jan.mikes@sendvery.com?subject=Enterprise%20inquiry`), plus fine print enterprise mailto.

**Page structure (overwriting current 19-line `pricing.html.twig`):**
1. `<twig:SectionContainer>`: hero h1 + subtitle, `<twig:PricingTable />`, "Why annual?" callout
2. `<twig:SectionContainer bgClass="bg-base-200/20">`: "Compare all features" h2, `<twig:PricingComparisonTable />`
3. `<twig:SectionContainer>`: "Pricing FAQ" h2, `<twig:PricingFaq />`
4. `<twig:SectionContainer bgClass="bg-base-200/30">`: Final CTA

**Important:** Do NOT use `{% block content %}{% endblock %}` *inside* `<twig:SectionContainer>` (per CLAUDE.md TwigPreLexer warning). Content auto-maps to the default block.

**Meta description (before → after):**
- Before: `Sendvery pricing: self-host free forever. Hosted plans from $0/mo. 5 domains for $5.99/mo. AI insights add-on $3.99/mo. Simple, transparent pricing.`
- After: `Sendvery pricing: Free forever for 1 domain. Personal from $4.99/mo (save $12/yr on annual). Pro from $19.99/mo (save $48/yr). Business from $49.99/mo (save $120/yr). Self-host free, always.`

**Tests (`PricingPageTest.php`, 11 methods, all GET `/pricing` anonymous):**
1. `pageReturns200`
2. `comparisonTableDesktopVariantIsPresent` — `.table.table-zebra` count ≥ 1
3. `comparisonTableMobileVariantIsPresent` — body contains `md:hidden` adjacent to a tier name
4. `comparisonTableContainsAllTierNames` (Free, Personal, Pro, Business each ≥ 2x)
5. `comparisonTableContainsAllFeatureRows` (13 row labels)
6. `faqSectionIsPresent` — `.collapse-plus` count ≥ 8
7. `faqContainsAllExpectedQuestions` (10 substring checks)
8. `startFreeCtaPointsToAuthLogin` — `a[href*="/login"]` count ≥ 1 + CTA text "Start free"
9. `talkToUsCtaIsMailtoLink` — `a[href^="mailto:jan.mikes@sendvery.com"]` count ≥ 1
10. `metaDescriptionContainsAnnualSavings` — meta description contains `$12`, `$48`, `$120`
11. `whyAnnualCalloutIsPresent` — body contains "2 months free" and "Annual billing"

**Controller change:** **None.**

**Build phases:** 1) PricingComparisonTable. 2) PricingFaq. 3) pricing.html.twig overhaul + meta. 4) Tests. 5) phpunit + phpstan + cs-fixer green. 6) Browser smoke-test `/pricing` (desktop + narrow viewport for mobile cards, FAQ open/close, CTA links).

---

## TASK-006: Free checker tools don't convert — the post-result CTA is the only conversion hook and it's weak on cold traffic

- Status: done
- Shipped: 2026-05-23
- Area: marketing
- Why: A user who lands on `/tools/spf-checker` from Google has very high intent ("my SPF is broken right now"). Today the checker form shows results, then `_StartMonitoringCta.html.twig` renders a single "Start monitoring example.com →" button that goes straight to magic-link login. That's a big leap from "I just looked up a record" to "give me your email and account". There is **no soft conversion** (email me this scan, schedule re-checks, share results) and no email capture below the result besides the global beta-page link. We're leaving a lot of high-intent traffic on the table because the only CTA is "create an account".
- Acceptance:
  - On every tool result (SPF, DKIM, DMARC, MX, email-auth, blacklist, domain-health), add a **soft-conversion micro-form** directly under the result card: *"Email me this report + alerts if anything changes"* — a single email field + button that creates a lightweight `BetaSignup` row tagged with the scanned domain and tool, and triggers the existing beta-confirmation email flow, then collapses into a "Check your inbox" confirmation.
  - The hard-CTA "Start monitoring" stays where it is (this adds a parallel softer option, doesn't replace it).
  - The micro-form is a single Twig component reusable across all tool results (e.g. `components/MonitorEmailMeMicro.html.twig`), takes `domain` and `source` ("spf-result" / "dkim-result" / etc.) as props, and posts to a Turbo-frame-capable endpoint so the page doesn't reload.
  - Source tracking lands in `BetaSignup.source` so we can A/B which tool drives the most signups.
  - 100% test coverage on the new submission endpoint, including the dedup path (existing email re-submits same domain → idempotent).
- Notes:

---

## TASK-007: Knowledge base has only 3 articles; categories look empty and undermine the SEO-first GTM

- Status: done
- Shipped: 2026-05-23
- Area: marketing
- Why: `/learn` lists exactly 3 articles, hard-coded in `KnowledgeBaseIndexController::GUIDES`: *What is DMARC*, *SPF Record Guide*, *Email Authentication Explained*. The category grid renders, but with only two articles in "Email Authentication Basics" and one in "DNS & Records", each category looks like a placeholder. There is no DKIM article (despite a DKIM tool page), no MX article, no blacklist article, no "DMARC p=none → p=quarantine → p=reject migration guide", no Gmail/Yahoo 2024 sender-requirements article (huge organic search demand). For an SEO-first product whose go-to-market is *"rank for long-tail email-auth keywords"* (per `docs/00-project-overview.md`), this is the biggest growth lever and the cheapest to fix.
- Acceptance:
  - Add at least **4 new evergreen articles** to `templates/knowledge_base/articles/` and to `KnowledgeBaseIndexController::GUIDES`:
    1. *"What is DKIM and How Does It Work?"* — pairs with `/tools/dkim-checker`
    2. *"Gmail & Yahoo Bulk Sender Requirements (2024+): What You Need to Comply"* — high-volume search keyword
    3. *"How to Move from p=none to p=reject: A Step-by-Step DMARC Migration Guide"* — solves the #1 DMARC user pain
    4. *"MX Records Explained: How Email Routing Works"* — pairs with `/tools/mx-checker`
  - Each article follows the existing `_article_layout.html.twig` shell: TOC, related-tools sidebar, structured data, related guides at the bottom, beta-signup CTA at the end.
  - Each article is at minimum ~1500 words (the existing 3 articles are 162–243 lines of Twig markup — match or exceed that).
  - Each new article has a unique `<title>` and `<meta description>` targeting the primary keyword from `docs/09-design-and-branding.md`.
  - All 4 are added to `SitemapController::ROUTES` with appropriate priority/changefreq.
  - The KB index page collapses to single-column-per-category gracefully when a category has only 1 article (current grid looks broken in that case).
- Notes:

---

## TASK-008: Static default OG image gives every page identical social-share previews — wasted distribution

- Status: done
- Shipped: 2026-05-23
- Area: marketing
- Why: `templates/base.html.twig` sets `og:image` to a single static `images/og-default.webp` for every page. When a knowledge-base article, a tool page, or a public domain-health report (`/health/{hash}`, see `PublicDomainHealthController`) is shared on LinkedIn, Slack, Twitter, or quoted in a blog post, the preview card is generic Sendvery branding instead of the article title / tool name / actual domain grade. Public domain-health reports in particular are designed to be shareable URLs — and the current OG image makes them look like generic homepage links. Per-page OG images dramatically increase click-through from social.
- Acceptance:
  - Implement a small **dynamic OG image generator route** (e.g. `/og/{type}/{slug}` rendering a 1200×630 PNG via PHP GD or Imagine, cached on disk) that produces a branded card for:
    - Each tool page (title + Sendvery logo + tool-category badge)
    - Each knowledge-base article (article title + category + brand)
    - Each public domain-health share (`/health/{hash}`) — domain name + big A–F grade in the brand colour + score
  - `templates/base.html.twig` already exposes an `{% block og_image %}` override — each of the above templates overrides it to point at the generated image URL.
  - Public domain-health reports become high-value share artifacts: tweet a `/health/{hash}` URL and the unfurled card shows the grade.
  - The generator route is testable (returns 200, correct content type, identical bytes for identical inputs); 100% test coverage for the route + image-cache logic.
  - Fallback: if generation fails, `og:image` falls back to the existing static `og-default.webp` — no broken previews.
- Notes:

### Architect plan (2026-05-23)

**Key decisions:**
- **PHP GD** (confirmed in `ghcr.io/thedevs-cz/php:8.5` base image, both `gd` + `imagick` present). GD chosen over Imagick — simpler, no external binary dep, sufficient for 3 static card layouts.
- **Self-hosted Inter TTF** committed to `assets/fonts/OgImage/Inter-Bold.ttf` + `Inter-Regular.ttf` + `LICENSE` (OFL-1.1).
- **Logo asset**: optional `assets/images/og-logo.png` (240×60 transparent). If absent, painter falls back to drawing "Sendvery" wordmark text. Ship without logo first; design asset can land later without code change.
- **Cache** at `var/og_cache/{type}/{md5(SCHEMA_VERSION:slug)}.png`. Immutable Cache-Control header (30 days). Bump `SCHEMA_VERSION` constant to invalidate.

**Architecture:**
- `src/Value/OgImageType.php` enum: `Tool/Kb/Health` with string-backed values.
- `src/Value/OgImageContent.php` (readonly final): `title, subtitle, badgeText: string`, `badgeRgbR/G/B: int`.
- `src/Value/ToolRegistry.php`: `public const array TOOLS` with 8 entries (dmarc-checker, spf-checker, dkim-checker, mx-checker, email-auth-checker, domain-health, blacklist-checker, dns-monitoring). Mirrors `KnowledgeBaseIndexController::GUIDES` pattern.
- `src/Exceptions/OgImageContentNotFoundException.php` (final, extends `\RuntimeException`).
- `src/Services/OgImage/{Tool|Kb|Health}OgImageContentResolver.php` — readonly final. Each `resolve(string $slug): OgImageContent`. Tool reads `ToolRegistry::TOOLS`. KB reads `KnowledgeBaseIndexController::GUIDES`. Health injects `GetDomainHealthHistory` (existing) — `findByShareHash` + `getDomainNameByShareHash`. Grade→RGB map: A=green, B=blue, C=amber, D/F=red.
- `src/Services/OgImage/GdOgImagePainter.php` (readonly final, ctor `string $projectDir`). Single `paint(OgImageContent, string $cacheFilePath): void`. 1200×630 canvas, near-white bg, 6px teal accent bar left, logo top-left (if file exists else text fallback), title centred (Inter Bold 52pt, wrapped via `imagettfbbox` at ~900px), subtitle below in slate-600 (Inter Regular 28pt), badge rectangle top-right with grade-colour fill + white text. Atomic write via `.tmp` + `rename`.
- `src/Services/OgImage/OgImageRenderer.php` (readonly final). `SCHEMA_VERSION = 'v1'`. `render(OgImageType $type, string $slug): string` — checks cache file, dispatches to resolver + painter on miss, returns absolute path.
- `src/Controller/OgImageController.php` — `#[Route('/og/{type}/{slug}', requirements: ['type' => 'tool|kb|health', 'slug' => '[a-zA-Z0-9_-]+'], methods: ['GET'])]`. Try renderer; on `OgImageContentNotFoundException` or any `\Throwable`: log warning/error + `$this->redirect($this->assetHelper->getUrl('images/og-default.webp'))` (302). On success: `BinaryFileResponse` with `Content-Type: image/png` + `Cache-Control: public, max-age=2592000, immutable`.

**Template overrides:**
- 8 tool templates: `templates/tools/{slug}.html.twig` add `{% block og_image %}{{ absolute_url(path('og_image', {type: 'tool', slug: '<slug>'})) }}{% endblock %}`.
- 3 KB article templates: `templates/knowledge_base/articles/{slug}.html.twig` — same with `type: 'kb'`.
- `templates/public/domain_health.html.twig` — `type: 'health', slug: snapshot.shareHash`. Guard with `{% if snapshot.shareHash %}` (falls back to parent default when null).

**Service wiring (`config/services.php` — create if absent, or modify):** bind `$projectDir` to `%kernel.project_dir%` and `$ogCacheDir` to `%kernel.project_dir%/var/og_cache` under `_defaults`.

**Tests:**
- Unit: `OgImageTypeTest`, `OgImageContentTest`, `ToolRegistryTest`, three `*OgImageContentResolverTest` (each resolves known slug + throws on unknown).
- Integration `GdOgImagePainterTest` — `paint()` writes valid 1200×630 PNG (`getimagesize` assertion); both logo-present and logo-absent branches via test fixture.
- Integration `OgImageRendererTest` — cache miss then cache hit returns same path; SHA-256 of output pinned in a constant (document how to regenerate when layout changes).
- Integration `OgImageControllerTest` — `tool` happy path (200 + image/png + non-empty body), cache-control immutable header, second request returns identical bytes, KB happy path, health happy path (with DB fixture using `domain_health_snapshot` row + share_hash), unknown slug → 302 redirect to `og-default.webp`, unknown type → 404 (route requirement blocks before controller).

**Critical details:**
- Atomic write via temp-file + `rename()` — handles concurrent first-request collisions.
- GD return-value guards (`\GdImage|false`) — `|| throw new \RuntimeException(...)` everywhere.
- Painter accepts optional `?string $logoPath` ctor arg; null/missing → text fallback. This both ships without a logo design asset AND keeps the no-logo branch testable.
- `var/og_cache/.gitkeep` so dir exists in fresh checkouts; `var/` is already in `.gitignore`.
- Inter TTF: download from https://github.com/rsms/inter/releases (OFL-1.1). Commit both `Inter-Bold.ttf` + `Inter-Regular.ttf` + `LICENSE` text.
- Verification: `docker compose exec app php -r "echo extension_loaded('gd') ? 'yes' : 'no';"` before phase 4.
- Cache invalidation on layout change: bump `SCHEMA_VERSION` constant. Old files become orphaned — note in commit that `rm -rf var/og_cache/` on deploy clears them.
- The `BinaryFileResponse` streams via `X-Sendfile` if available — no full file load into PHP memory.

**Build phases:**
1. Fonts + logo asset placeholder + `var/og_cache/.gitkeep` + GD extension verification.
2. Value objects + `ToolRegistry` + exception + unit tests.
3. Three content resolvers + unit tests (with mocked `GetDomainHealthHistory` for health resolver).
4. `GdOgImagePainter` + `OgImageRenderer` + DI binding + integration tests.
5. `OgImageController` + integration tests including the cross-tenant / unknown-slug / unknown-type branches.
6. 12 template `{% block og_image %}` overrides + smoke (assert HTML contains `/og/tool/dmarc-checker` URL).
7. `phpunit --coverage-min=100` + `phpstan` + `cs-fixer` all green; commit + push.

---

## TASK-009: No public trust pages (Privacy, Security, Status) — a real blocker for paid signup

- Status: done
- Shipped: 2026-05-23
- Area: marketing
- Why: Sendvery asks visitors to entrust it with IMAP credentials and email-authentication data. The marketing surface has **no** privacy policy, no security overview, no incident/uptime status page, no data-processing / sub-processor list, no GDPR statement. The footer (`templates/components/Footer.html.twig`) has only Free Tools / Product / Connect columns — no "Legal" or "Trust" column. For Personas 2 and 3 (Marketing Maria, Agency Alex) and any EU customer, this is a hard signup blocker — most procurement processes require at minimum a privacy-policy URL before signing up. The "all data encrypted at rest, IMAP credentials AES-256-GCM encrypted" claim in the homepage credibility section is great but unbacked by any policy page.
- Acceptance:
  - Add three new public routes + Twig templates under `templates/legal/` (or `templates/trust/`):
    - `/legal/privacy` — Privacy Policy: what we collect, why, retention, sub-processors (Stripe, Anthropic, Sentry, Hetzner), GDPR rights, contact, last-updated date.
    - `/legal/security` — Security overview: AES-256-GCM at rest, TLS in transit, magic-link auth (no password storage), how IMAP credentials are stored and rotated, where data lives (Hetzner/EU region), responsible-disclosure / `security@sendvery.com` contact.
    - `/status` — Status / Uptime: either embed a third-party status (BetterStack/StatusPage) widget, or render a simple "Operational" panel with last-24h uptime per subsystem (web app, ingestion workers, DMARC parser, AI service). Data-driving from a JSON file written by ops cron is acceptable as a v1.
  - Each page extends `marketing_layout.html.twig`, has its own SEO title/meta, structured data where appropriate (Organization for security, etc.), and renders a `Last updated: YYYY-MM-DD` line.
  - Footer (`templates/components/Footer.html.twig`) gets a new fourth column "Trust" linking to Privacy / Security / Status / Open Source / Refund Policy.
  - The new routes are added to `SitemapController::ROUTES` at priority 0.6.
  - 100% test coverage on the new controllers (smoke test that each returns 200 and contains the page heading).
- Notes:

### Architect plan (2026-05-23)

**Critical finding:** the actual encryption library is `paragonie/halite` (XChaCha20-Poly1305 via libsodium), **NOT AES-256-GCM** as the homepage credibility section currently claims. Security page must use the truthful description. The homepage's stale "AES-256-GCM" claim should also be corrected in the same PR for consistency.

**Decision on URLs:** `/legal/privacy`, `/legal/security`, `/status` (status convention is top-level, not under /legal/). All three return `final class extends AbstractController` (no readonly — matches `WhatIsSendveryController`/`OpenSourceController` pattern). `StatusController` injects `#[Autowire('%kernel.project_dir%')] private readonly string $projectDir` for future `var/status.json` reading.

**Files to create:**
- `src/Controller/PrivacyPolicyController.php` (`#[Route('/legal/privacy', name: 'legal_privacy')]`)
- `src/Controller/SecurityOverviewController.php` (`#[Route('/legal/security', name: 'legal_security')]`)
- `src/Controller/StatusController.php` (`#[Route('/status', name: 'status')]`) with `loadStatusData(string $path): array` private method returning the file contents if readable + valid JSON, otherwise a hardcoded operational array. Status fallback shape: `['overall' => 'operational', 'updated_at' => null, 'components' => [Web application, Email ingestion workers, DMARC report parser, DNS health checker, AI Insights service — all 'operational']]`.
- `templates/legal/privacy.html.twig`, `security.html.twig`, `status.html.twig` (all extend `marketing_layout.html.twig`, content wrapped in `<twig:SectionContainer><div class="max-w-3xl mx-auto">`).
- `tests/Integration/Controller/TrustPagesTest.php` — 16 dedicated tests (3× return 200, 3× H1 text, 2× last-updated date, sub-processors list, GDPR rights mention, magic-link claim, halite claim regression guard, responsible disclosure email, status operational, web-application component name, footerContainsTrustLinks).

**Files to modify:**
- `templates/components/Footer.html.twig` — change grid from `lg:grid-cols-4` to `lg:grid-cols-2 xl:grid-cols-5` (or `lg:grid-cols-3 xl:grid-cols-5` if 2-col laptop looks sparse). Add new "Trust" column: Privacy / Security / Status / Open Source / Refund Policy (Refund Policy → `path('pricing') ~ '#faq'`). Remove "Open Source" from existing Product column to avoid duplication. Keep "Connect" as 5th column with just GitHub.
- `src/Controller/SitemapController.php` — add three ROUTES entries: `['route' => 'legal_privacy', 'priority' => '0.6', 'changefreq' => 'monthly']`, same for `legal_security`, and `['route' => 'status', 'priority' => '0.6', 'changefreq' => 'weekly']` (weekly because status reflects current state).
- `tests/Integration/Controller/MarketingPagesTest.php` — three new entries in `publicRoutes` provider.
- `tests/Integration/Controller/SeoTest.php` — add three `assertStringContainsString` calls in `sitemapContainsAllPublicRoutes`.
- `templates/homepage/index.html.twig` — replace the AES-256-GCM claim with the truthful libsodium/Halite/XChaCha20-Poly1305 description (architect noted as separate fix; orchestrator decided to bundle for consistency).

**Privacy Policy content (architect drafted exact copy):** 7 sections — Last updated 2026-05-23, What we collect (bullets: account / Stripe-managed payments / DMARC reports / IMAP credentials / AI prompt data — anonymised / Sentry crash data), Why we collect it (no advertising, no sales), Data retention (per plan: Free 30d / Personal 1yr / Pro 2yr / Business unlimited), Sub-processors (responsive table — Stripe USA+EU, Anthropic USA "when AI Insights is enabled by user, anonymised DMARC summary data only", Sentry EU+USA, Hetzner EU/Germany), Your GDPR rights (6 rights + lodge complaint), Children (under 16, none knowingly), Contact (`privacy@sendvery.com` + Jan Mikeš OSVČ Czech Republic).

**Security Overview content (architect drafted exact copy):** sections — Authentication (magic-link only, no password storage anywhere), Encryption at rest (paragonie/halite via libsodium, XChaCha20-Poly1305, key from env var never stored in DB/source/version history, unique nonce per credential), Encryption in transit (TLS 1.3, HSTS, IMAP/POP3 TLS-only), Data location (Hetzner EU, anonymised data to Anthropic US only when AI enabled — disclosed in Privacy Policy), Audit trail (EntityWithEvents pattern, Stripe webhooks persisted, 30-day log retention), Responsible disclosure (`security@sendvery.com`, 48h ack + 14-day fix target for critical, public credit), Self-hosting (AGPL-3.0, link to `/about/open-source` for orgs that can't share IMAP credentials), Certifications (honest: pre-SOC2/ISO27001, on roadmap, self-host if hard requirement).

**Status page content:** H1 "Sendvery System Status", overall badge (`alert-success`/warning/error driven by `statusData.overall`), last-checked line, 5-component grid with coloured dot + name + status badge, subscribe-to-updates `status@sendvery.com` mailto.

**Test list (TrustPagesTest, 16 methods):**
`privacyPageReturns200`, `securityPageReturns200`, `statusPageReturns200`, `privacyPageContainsH1` ("Privacy Policy"), `securityPageContainsH1` ("Security Overview"), `statusPageContainsH1` ("Sendvery System Status"), `privacyPageContainsLastUpdated` ("2026-05-23"), `securityPageContainsLastUpdated`, `privacyPageContainsSubProcessors` (Stripe/Anthropic/Sentry/Hetzner), `privacyPageContainsGdprRights` ("right to access" + "privacy@sendvery.com"), `securityPageContainsMagicLinkClaim` ("magic-link" + "no password"), `securityPageContainsEncryptionClaim` ("halite"/"libsodium"/"XChaCha20" — regression guard against re-introducing AES-256-GCM), `securityPageContainsResponsibleDisclosure` ("security@sendvery.com"), `statusPageContainsOperationalStatus`, `statusPageContainsWebApplicationComponent`, `footerContainsTrustLinks` (GET `/`, assert Privacy / Security / Status text in footer).

**Critical details:**
- `prose prose-lg max-w-none text-base-content/80` per existing about-page pattern. If Tailwind typography plugin isn't enabled and `prose` doesn't render, fall back to `text-base-content/80 leading-relaxed space-y-4 [&_h2]:font-bold [&_h2]:text-base-content [&_h2]:mt-8`.
- daisyUI v5 only; no `dark:` prefix.
- Status JSON file at `var/status.json` (writable; volume-mounted in docker-compose) — controller v1 reads it if present, falls back to hardcoded operational. Future cron can write it.
- Anthropic sub-processor row is described unconditionally (not gated on `ai_available` Twig global) — privacy policy describes max data sharing scope of the product, not current deployment config.
- `Refund Policy` footer link goes to `/pricing#faq` (TASK-005's PricingFaq has the refund question).
- "Hetzner infrastructure in the European Union" — no hard-coded "Falkenstein" (deployment detail).
- No structured data on legal pages (BreadcrumbList low SEO value, omit for simplicity).

**Build phases:** 1) 3 controllers; route registration verified. 2) 3 templates. 3) Footer restructure + homepage AES claim fix. 4) Sitemap entries. 5) Tests; phpunit + phpstan + cs-fixer green; commit; push.

---

## TASK-010: "What is Sendvery" page is a wall of text — no visuals, no product screenshot, no clear conversion path

- Status: done
- Shipped: 2026-05-23
- Area: marketing
- Why: `templates/about/what-is-sendvery.html.twig` is currently a single 30-line `<div class="prose">` block: paragraph, founder quote, persona list, open-source paragraph. No screenshot, no diagram, no real photo of the founder, no "what does the product actually look like", no comparison vs alternatives, no inline CTAs other than a "Check your domain now" button at the end. According to `docs/09-design-and-branding.md`, this page is supposed to be a *"product manifesto"* — instead it reads like a Wikipedia stub. Visitors arriving here from the footer or nav are specifically trying to understand the product before signing up — this is the most influential page for high-consideration buyers and currently fails to convert them.
- Acceptance:
  - Add an actual **product screenshot** (or annotated mockup) of the dashboard overview — visitors should see what they're signing up for.
  - Replace the persona bullet list with a **3-card persona section** (Developer / Small Business / Agency) mirroring `docs/01-vision-and-problem.md` — each card has icon, persona name, "what they need", and a one-line outcome.
  - Add a **"How is Sendvery different?"** mini comparison panel: side-by-side bullets vs MXToolbox (free, no monitoring), vs dmarcian (expensive, no AI), vs self-hosted PowerDMARC (complex to deploy), vs Sendvery (monitoring + AI + open source + $5.99). Three competitors max — keep it scannable.
  - Add a **"Built in the open"** strip with a real GitHub commit graph or a "Last commit X days ago" badge — proves the project is actively maintained.
  - Add at least two **inline mid-page CTAs**, not just the bottom one (after the problem section, after the personas).
  - Founder quote becomes a proper blockquote with photo + name + role + link — currently it's plain inline text in quotation marks.
  - Page still passes Lighthouse perf > 90 on mobile.
- Notes:

---

## TASK-011: `/open-source` page underplays self-host as a value prop — and currently links to a repo that isn't public yet

- Status: done
- Shipped: 2026-05-23
- Area: marketing
- Why: `templates/about/open-source.html.twig` has four short paragraphs and a docker-compose snippet. It doesn't show **how trivial** self-hosting is (one-command quickstart), doesn't show the GitHub repo's star count, doesn't link to docs, doesn't show what running Sendvery locally looks like, and doesn't tie back to the hosted plans ("self-host = forever free, hosted = managed infra"). Worse, the page links `https://github.com/janmikes/sendvery` but per `docs/03-features-roadmap.md` Phase 2 the repo is still private — visitors clicking through hit a 404, which is worse than not linking at all. The "free forever if you self-host" hook is a top-3 differentiator in `docs/05-monetization.md`; the page that lives at that URL should be the strongest argument for the product, not the weakest.
- Acceptance:
  - Add a **live GitHub stats strip** at the top (star count, fork count, last commit, license badge) — can be a static cached snapshot if hitting the GitHub API on every render is too much, but it must be real numbers, not "10k+ developers".
  - Add a **"Self-host in 60 seconds" quickstart**: 3 numbered steps with copyable code blocks (clone / configure `.env` / `docker compose up`), each step with a one-line "what this does" explanation.
  - Add a **"Self-host vs Hosted" comparison table**: rows like *Cost, Time to set up, Auto-updates, Backups, AI key, Support* — making the hosted-tier value prop legible to a technical user who could in principle self-host.
  - Expand the **"Why AGPL?"** call-out (current page has it but it's three sentences) — explain in plain language what users / contributors can and cannot do; reassures companies whose legal team will read this page.
  - Add a **"What's in the repo?"** section: brief tour of `src/`, `docs/`, `tests/` — demonstrates code quality and 100% test coverage as a trust signal.
  - The "View on GitHub" button is gated: if the repo is not yet public (per Phase 2 status in `docs/03-features-roadmap.md`), the button shows *"Coming soon — get notified when we open the repo"* and captures email via a small form. When the repo flips public, change one constant and the button works normally. Do not link to a 404.
  - End-of-page CTA: two buttons side-by-side: "Self-host (free forever)" → quickstart anchor, "Try hosted (no setup)" → auth login.
- Notes:

### Architect plan (2026-05-23)

**Key decisions:**
- **Repo-public gate** = env var `SENDVERY_REPO_PUBLIC` (default `0`). Exposed via new Twig extension `OpenSourceExtension` (clone of `PricingFlagsExtension`'s pattern). Globals: `is_repo_public: bool`, `github_url: string` (constant `https://github.com/janmikes/sendvery` — change one line when repo URL changes).
- **GitHub stats** = cached JSON file at `var/github_stats.json`, refreshed by a new cron command. Twig extension `GithubStatsExtension` reads it and exposes `github_stats: ?GithubStats`. Missing/malformed file → null → stats strip is omitted entirely (no fake placeholders). `symfony/http-client` is NOT in composer.json — use stock `file_get_contents` with a User-Agent stream context. No new dep.
- **Email capture for the "Coming soon" button** = DROPPED. Just a disabled `<button>` with copy "Coming soon — repo opens at launch". Rationale: TASK-012 deliberately retired `BetaSignup`; reactivating it for a different capture flow re-entangles a retired path. No site-wide email drip infra exists yet. Honest copy + no 404 is enough.
- **Cron registration** = OUT OF SCOPE for the code commit (lives in `~/www/spare.srv/deployment/crontab` which is outside the repo). Document in the build report so user can add `0 */6 * * * sentry-cli monitors run sendvery-github-stats -- docker compose run --rm worker bin/console sendvery:opensource:refresh-github-stats` when deploying.

**Architecture:**
- `src/Value/GithubStats.php` (readonly final): `stars: int`, `forks: int`, `lastCommitAt: \DateTimeImmutable`, `defaultBranch: string`. Static `fromJson(string $json): ?self` factory — null on any parse failure.
- `src/Services/Github/GithubApiClient.php` interface: `fetchRepoStats(string $repo): array|false`.
- `src/Services/Github/FileGetContentsGithubApiClient.php` (readonly final, production impl): wraps `file_get_contents` with `User-Agent: sendvery-cron/1.0` stream context against `https://api.github.com/repos/janmikes/sendvery`. Interface lets tests bind a fake.
- `src/Twig/OpenSourceExtension.php`: `#[Autowire(env: 'SENDVERY_REPO_PUBLIC')] string $repoPublic`. Globals `is_repo_public` + `github_url`.
- `src/Twig/GithubStatsExtension.php`: `#[Autowire('%kernel.project_dir%')] string $projectDir`. Reads `$projectDir/var/github_stats.json` via `GithubStats::fromJson`. Returns `['github_stats' => $stats]` where `$stats` may be null.
- `src/Command/RefreshGithubStatsCommand.php`: `#[AsCommand(name: 'sendvery:opensource:refresh-github-stats')]`. Injects `GithubApiClient`. Writes file atomically: write `.tmp` then `rename()` (POSIX-atomic). Failure leaves existing file intact. Exit `SUCCESS`/`FAILURE`.
- `assets/controllers/clipboard_copy_controller.js`: new Stimulus controller. Values: `text: String`. Targets: `label`. Action `copy` writes `this.textValue` to clipboard, flashes label "Copied!" for 1500ms. Same defensive try/catch pattern as `copy_link_controller.js`. Auto-discovered via Symfony UX naming convention (no manual registration in `controllers.json`).

**Template rewrite — `templates/about/open-source.html.twig`** — extends `marketing_layout`. Seven sections:
1. Hero: `<div class="badge badge-outline badge-lg">Open Source · AGPL-3.0</div>` + title "Self-host Sendvery free, forever" + subtitle + dual CTA (`#quickstart` anchor + `auth_login`).
2. GitHub stats strip — conditional `{% if github_stats is not null %}`. Renders stars / forks / last commit / AGPL-3.0 badge. If `is_repo_public` also true, includes "View on GitHub →" link.
3. Quickstart `<section id="quickstart">`: 3 numbered steps, each with `<div class="mockup-code relative">` containing a `<pre><code>` block + absolutely-positioned copy button using `data-controller="clipboard-copy" data-clipboard-copy-text-value="..."`. Step copy as in plan body.
4. Comparison table (7 rows): Cost, Time to set up, Auto-updates, Backups, AI key, Support, Data ownership.
5. "Why AGPL?" expanded explanation (plain language: what users can/cannot do).
6. "What's in the repo?" — brief directory tour (`src/`, `docs/`, `tests/`, mention 100% coverage).
7. End-of-page CTA repeat: two buttons — "Self-host (free forever)" → `#quickstart`, "Try hosted (no setup)" → `auth_login`.

GitHub button gating:
```twig
{% if is_repo_public %}
  <a href="{{ github_url }}" target="_blank" rel="noopener" class="btn btn-sm">View on GitHub</a>
{% else %}
  <button class="btn btn-sm btn-disabled" disabled aria-disabled="true">Coming soon — repo opens at launch</button>
{% endif %}
```

**Tests:**
- `tests/Integration/Controller/OpenSourcePageTest.php`: 200, headings present, quickstart anchor, comparison-table text, "Why AGPL" + "What's in the repo" headings, dual end CTA, GitHub button disabled when env=0, enabled when env=1.
- `tests/Integration/Twig/OpenSourceExtensionTest.php`: globals contain `is_repo_public` + `github_url`; falsy for `""`, `"0"`; truthy for `"1"`.
- `tests/Integration/Twig/GithubStatsExtensionTest.php`: null when file missing / malformed / valid → `GithubStats` instance with correct types.
- `tests/Integration/Command/RefreshGithubStatsCommandTest.php`: fake `GithubApiClient` → writes JSON with correct fields, exits SUCCESS; fake returns false → exits FAILURE + does NOT overwrite existing file.
- `tests/Unit/Value/GithubStatsTest.php`: `fromJson` returns null on invalid JSON / missing fields / invalid date; instance on valid data.

**Critical details:**
- Atomic write via `rename()` — prevents `GithubStatsExtension` from reading a partial file during cron.
- GitHub API unauthenticated rate limit: 60 req/hr — cron runs 4/hr per deployment (every 6h).
- `is_repo_public` env parsing: `'' !== $repoPublic && '0' !== $repoPublic` (handles unset / `"0"` / truthy).
- No `dark:` Tailwind prefix anywhere. daisyUI theme tokens only.
- No `{% block %}` nested inside `<twig:SectionContainer>` tags — content auto-routes to `content` block.

**Build phases:**
1. Add `SENDVERY_REPO_PUBLIC=0` to `.env`. Create `GithubStats` value object + tests.
2. `GithubApiClient` interface + `FileGetContentsGithubApiClient` impl + service alias.
3. `OpenSourceExtension` + `GithubStatsExtension` + tests.
4. `clipboard_copy_controller.js`.
5. `RefreshGithubStatsCommand` + test (with `FakeGithubApiClient` bound in `when@test` services block).
6. Full rewrite of `templates/about/open-source.html.twig`.
7. Controller integration test.
8. `phpunit` + `phpstan` + `cs-fixer` all green. Smoke: run cron command, reload page, flip env var to confirm gating. Document deployment cron line in the commit message.

---

## TASK-012: Post-Stripe cutover, the Free-tier CTA still funnels users to the beta waitlist instead of into the signup funnel

- Status: done
- Shipped: 2026-05-23 (commit `f1d34b3`)
- Decision (orchestrator, 2026-05-23): **Retire `/beta`** (Option A) — simpler, removes contradictory copy, no new email-list infra to maintain. Repurposing would require new "Get product updates" copy + opt-in semantics that aren't strategically valuable yet post-launch.

### Architect plan (2026-05-23)

**Strategy:** `/beta` becomes a 301 → home. Token-based confirmation links sent before retirement still resolve (controller preserved; final redirect → `auth_login` with success flash). `BetaSignup` entity + table + repository stay in place (existing real users — see "never delete user data" memory).

**Files to modify:**
- `src/Controller/BetaSignupController.php` — strip all deps, `__invoke()` returns `$this->redirectToRoute('home', [], 301)`. Route stays `GET|POST /beta`.
- `src/Controller/ConfirmBetaSignupController.php` — keep token lookup + `confirm()` logic; replace final `render('beta/confirmed.html.twig')` with `$this->addFlash('success', 'Your email is confirmed. Sign in to get started with Sendvery.'); return $this->redirectToRoute('auth_login');` (use 302 — token endpoint is dynamic, not a retired stable URL).
- `src/Controller/SitemapController.php` — remove the `['route' => 'beta_signup', ...]` entry from the `ROUTES` constant.
- `templates/components/PricingTable.html.twig` line 82 — change `path('beta_signup')` → `path('auth_login')`; label "Get started" → "Get started free".
- `templates/knowledge_base/_article_layout.html.twig` lines 116-131 — replace lazy turbo-frame beta section with a static `<section>` CTA: heading "Start monitoring your email authentication", subhead "Automated DMARC report parsing, DNS monitoring, and AI-powered insights. Free for 1 domain, no credit card required.", primary button "Get started free" → `path('auth_login')`, fine print "Free plan • No credit card • 2-minute setup".
- `templates/knowledge_base/index.html.twig` lines 67-82 — same replacement, heading "Ready to put this knowledge into action?" preserved.
- `templates/tools/spf-checker.html.twig` line 150, `dkim-checker.html.twig` line 124, `dmarc-checker.html.twig` line 124, `email-auth-checker.html.twig` line 97, `dns-monitoring.html.twig` line 67 — each contains "Join the Sendvery beta" copy pointing at `path('home')#pricing`. Replace anchor `href` with `path('auth_login')` and copy with "Start monitoring free" / "Get started free" (variation per architect's plan).

**Files to delete:**
- `templates/beta/signup.html.twig`
- `templates/beta/_form.html.twig`
- `templates/beta/confirmed.html.twig`

**Files to leave untouched:**
- `src/Entity/BetaSignup.php`, `src/Repository/BetaSignupRepository.php` (existing user data; still consumed by ConfirmBetaSignupController).
- `src/Message/RegisterBetaSignup.php`, `src/MessageHandler/*` for it, `src/FormData/BetaSignupData.php`, `src/Events/BetaSignupCreated.php`, `templates/emails/beta_confirmation.html.twig` — become unreachable but valid; PHPStan-clean. Separate cleanup PR later if desired.
- `templates/dashboard/admin_invite.html.twig` — admin-only, out of scope.

**Tests:**
- **Replace** `tests/Integration/Controller/BetaSignupTest.php` entirely with: `betaRouteRedirectsToHomePermanently` (301 → `/`), `betaRoutePostAlsoRedirects` (301), `confirmWithValidTokenRedirectsToLogin` (302 → `/login`), `confirmWithValidTokenSetsConfirmedAt`, `confirmWithValidTokenShowsFlashMessage` (follow redirects; assert "confirmed" text), `confirmAlreadyConfirmedRedirectsToLogin` (preserves original `confirmedAt`), `confirmWithInvalidTokenReturns404`.
- `tests/Integration/Controller/PricingTableTest.php` — add `testFreeTierCtaPointsToAuthLogin` and `testNoBetaHrefOnPricingPage`.
- `tests/Integration/Controller/SeoTest.php` — remove the `/beta` assertion in `sitemapContainsAllPublicRoutes`; add new `sitemapDoesNotContainBeta`.
- `tests/Integration/Controller/MarketingPagesTest.php` — drop `yield 'beta' => ['/beta']` from the `publicRoutes` provider. `RouteSmokeTest` still auto-covers the 301.
- `tests/Integration/Controller/KnowledgeBaseTest.php` — add: `indexDoesNotEmbedBetaForm`, `indexHasAuthLoginCta`, `guideDoesNotEmbedBetaForm` (data-provider over guide slugs), `guideHasAuthLoginCta`.

**Critical details:**
- Verify `auth_login` URL via `docker compose exec app bin/console debug:router auth_login` before locking in `'/login'` test assertions. If it resolves differently, update the assertions.
- HTTP code: `/beta` = **301** (URL retired, transfer PageRank). `/beta/confirm/{token}` post-confirm = **302** (dynamic endpoint, conventional UX post-action redirect).
- KB pages currently use `<turbo-frame src="...beta..." loading="lazy">`. After retirement the frame would lazy-load the 301-redirected homepage inside itself — broken UX. Replacing with plain HTML eliminates the frame request entirely.
- `confirmWithValidTokenShowsFlashMessage` depends on the login template rendering flashes. If it doesn't, either fix the login template or drop the assertion — the redirect itself is the load-bearing behavior.

**Audit (after this PR every public CTA points at `auth_login` or is unauthenticated context-specific):**
- Homepage final CTA — `auth_login` ✓ already.
- Pricing Free card — fixed in this PR.
- KB index + article bottom CTA — fixed.
- Tool result CTAs (`_StartMonitoringCta`) — already correct.
- Tool body "Join the beta" copy — fixed in 5 files.
- `/beta` route — 301 redirects to home.

**Build sequence:** 1) Controllers (3 files). 2) Templates (1 PricingTable + 2 KB + 5 tool pages, delete 3 beta templates). 3) Tests. 4) `phpunit` / `phpstan` / `cs-fixer` green; commit; push. Smoke-test in browser: `/pricing`, `/learn`, `/learn/<slug>`, `/tools/spf-checker`, `/beta` (expect 301).
- Area: marketing
- Why: Per `docs/05-monetization.md` and the most recent commit history (`Pricing: post-cutover cleanup`, `Pricing: drop remaining fake-door artifacts`), Stripe is live and the fake-door `/request-access` flow was removed on 2026-05-22. But the **Free** plan card in `templates/components/PricingTable.html.twig` (line ~82) still has its CTA pointing at `path('beta_signup')` — i.e. a visitor who picks the Free plan goes to the waitlist form instead of into the actual signup / auth flow. Similarly, `templates/beta/signup.html.twig` is still the destination for `/beta` and still markets *"Be among the first to try Sendvery when it launches"* as if the product hadn't launched. The homepage final CTA *correctly* sends users to `path('auth_login')`. This inconsistency reads as a half-done launch and will measurably suppress conversion: highest-intent visitors (the ones who click a pricing plan) hit a "join the waitlist" form instead of a real signup. Knowledge-base article CTAs also still lazy-load this beta form.
- Acceptance:
  - The Free-tier CTA in `PricingTable.html.twig` points to `path('auth_login')` (or wherever the real Free-tier signup lives), matching the paid tiers' pattern of going directly into the funnel.
  - Decide and document the post-launch role of `/beta`:
    - **Either** retire the `beta_signup` route entirely (redirect `/beta` → home or pricing, remove from sitemap, drop the lazy-loaded turbo-frame includes in `templates/knowledge_base/_article_layout.html.twig` and `templates/knowledge_base/index.html.twig`); **or**
    - Repurpose `/beta` as a generic **"Get product updates"** email-list opt-in with new copy that does **not** imply the product is unreleased.
  - Whichever route is chosen, the copy and CTA labels across the public surface (homepage final CTA, KB article CTAs, KB index CTA, tool-page bottom CTA) are consistent — they all say either "Get started free" → auth login OR "Subscribe to updates" → email opt-in, never both "Get started" and "Join the beta waitlist" within the same visit.
  - 100% test coverage on whatever controller change ships (retirement → redirect test; repurpose → updated form test).
- Notes:

---

## TASK-013: Domain detail page: surface domain-specific DNS health inline instead of bouncing to public tool

- Status: done
- Shipped: 2026-05-23 (commits `a8fcac3` impl + `24e6c8e` post-review tests)
- Area: domains
- Why: `templates/dashboard/domain_detail.html.twig` (lines 32-34) has a "DNS Health Check" header button that links to `tools_domain_health?domain=...` — the **public** anonymous lookup tool. The user already added this domain; they shouldn't be punted back to a marketing form. The domain detail page should answer "is SPF/DKIM/DMARC currently OK for this domain?" without leaving the dashboard.
- Acceptance:
  - The "DNS Health Check" button on `domain_detail.html.twig` is replaced (or augmented) with a link to the in-app `dashboard_domain_health` route (already used elsewhere — see `templates/dashboard/domain_health.html.twig`).
  - Domain header gains an at-a-glance health row: small badges for SPF / DKIM / DMARC / MX with valid / invalid / unknown states, sourced from the latest `DnsCheckResult` rows for that domain (no extra DNS queries on page load — read from `dns_check_result` table).
  - Clicking any badge deep-links to the relevant section of `dashboard_domain_health` / `dashboard_domain_dns_history`.
  - The page also drops the redundant `tools_domain_health` button entirely — the same data is already reachable via "DNS History" + the new in-app DNS Health page.
  - 100% test coverage on the new query (`GetLatestDnsHealthBadgesForDomain` or similar) and the controller change.
- Notes:

### Architect plan (2026-05-22)

**Scope narrowing:** TASK-001 already replaced the "DNS Health Check" header button (`domain_detail.html.twig` line ~32) with a link to `dashboard_domain_health`. Remaining scope is **the at-a-glance SPF/DKIM/DMARC/MX badge row in the domain header** plus deep-link anchors on the health page.

**Data decision (Option A):** Add `forDomain(string $domainId, list<string> $teamIds): ?DnsHealthOverviewResult` to existing `GetDnsHealthOverview` query (TASK-001). Same SQL as `forTeams()` but with `WHERE md.id = :domainId AND md.team_id IN (:teamIds)` (team-scope guard mandatory). Same DTO — no new result class. The `monitored_domain.{spf,dkim,dmarc}_verified_at` columns are the authoritative real-time signal for SPF/DKIM/DMARC badges; `latestMxScore` from the lateral-joined snapshot drives the MX badge.

**Why not Option B (new query/DTO sourced from `dns_check_result.is_valid`):** The nightly cron writes both `dns_check_result` rows and updates `verified_at`, so they encode the same answer. `verified_at` is the field the rest of the app already trusts. Option B would duplicate SQL + DTO with no real-world signal difference.

**Files to modify (no new files, except the test):**
- `src/Query/GetDnsHealthOverview.php` — add `forDomain()` method.
- `src/Controller/Dashboard/ShowDomainDetailController.php` — inject `GetDnsHealthOverview`, call `$this->getDnsHealthOverview->forDomain($id, $teamIds)`, pass `dnsHealth` to render.
- `templates/dashboard/domain_detail.html.twig` — insert badge row between lines 23-24 (under the existing verified/policy badges row).
- `templates/dashboard/domain_health.html.twig` — add `id: 'health-spf'` etc. keys to the `categories` array (lines 57-63), use `{{ cat.id }}` in the loop. Add `id="health-score"` to the grade card, `id="health-trend"` to the trend chart card.

**Files to create:**
- `tests/Integration/Controller/DomainDetailBadgeTest.php` (10 test methods).

**Badge template markup (between lines 23-24 of `domain_detail.html.twig`):**

```twig
<div class="flex items-center gap-2 mt-2 flex-wrap">
    {% if dnsHealth is null %}
        <span class="badge badge-ghost badge-sm">SPF</span>
        <span class="badge badge-ghost badge-sm">DKIM</span>
        <span class="badge badge-ghost badge-sm">DMARC</span>
        <span class="badge badge-ghost badge-sm">MX</span>
    {% else %}
        <a href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}#health-spf"
           class="badge badge-sm {{ dnsHealth.isSpfVerified() ? 'badge-success' : 'badge-error' }}">SPF</a>
        <a href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}#health-dkim"
           class="badge badge-sm {{ dnsHealth.isDkimVerified() ? 'badge-success' : 'badge-error' }}">DKIM</a>
        <a href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}#health-dmarc"
           class="badge badge-sm {{ dnsHealth.isDmarcVerified() ? 'badge-success' : 'badge-error' }}">DMARC</a>
        {% if dnsHealth.latestMxScore is null %}
            <a href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}#health-mx" class="badge badge-sm badge-ghost">MX</a>
        {% elseif dnsHealth.latestMxScore >= 80 %}
            <a href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}#health-mx" class="badge badge-sm badge-success">MX</a>
        {% elseif dnsHealth.latestMxScore >= 50 %}
            <a href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}#health-mx" class="badge badge-sm badge-warning">MX</a>
        {% else %}
            <a href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}#health-mx" class="badge badge-sm badge-error">MX</a>
        {% endif %}
    {% endif %}
</div>
```

The `dnsHealth is null` branch is unreachable in normal use (the `ShowDomainDetailController`'s 404 fires first for cross-tenant domain IDs because `GetDomainDetail::forDomain()` already gates on teamIds), but kept as a defensive fallback.

**Test methods (`DomainDetailBadgeTest`, all integration tests):**
1. `badgeRowRendersForOnboardedOwner` — basic render, regex for any `badge-success|badge-error` adjacent to `>SPF<`
2. `badgeRowRendersSuccessForFullyVerifiedDomain` — all timestamps set + snapshot mxScore=95 → 4 success badges
3. `badgeRowRendersErrorForUnverifiedSpf` — only DKIM+DMARC verified → SPF=error
4. `badgeRowRendersGhostMxWhenNoSnapshot`
5. `badgeRowRendersMxWarningForMidScore` (mxScore=65)
6. `badgeRowRendersMxErrorForLowScore` (mxScore=30)
7. `badgeRowBadgesDeepLinkToHealthPage` — assert all four `#health-{spf,dkim,dmarc,mx}` fragments in body
8. `healthPageHasAnchorIdsForDeepLinks` — GET `/app/domains/{id}/health`, assert `id="health-spf"`, `id="health-dkim"`, `id="health-dmarc"`, `id="health-mx"` in response
9. `domainWithNoSnapshotAndNotVerifiedShowsAllErrorBadges` (extra domain, all `verified_at` null, no snapshot)
10. `domainWithVerifiedDnsButNoSnapshotShowsThreeSuccessAndGhostMx`

**Query-level tests (add to existing `DnsHealthOverviewTest`):**
- `forDomainReturnsNullForUnknownDomain` (random UUID)
- `forDomainReturnsResultForKnownDomain`

**Edge cases (explicit):**
- All-unverified + no snapshot → 3 error badges + ghost MX, all still linked.
- Verified + no snapshot yet → 3 success badges + ghost MX (newly verified, before nightly health check).
- Recently re-verified domain with stale snapshot → `verified_at` is real-time, snapshot is historical — badges reflect verification, MX reflects snapshot (acceptable lag, same as existing health page).
- Cross-tenant domain ID → 404 from controller before template renders (defence-in-depth: the query also team-scopes).

**Build phases:**
1. Add `forDomain()` to query — PHPStan green.
2. Add `id` keys to `categories` array in `domain_health.html.twig` + anchors on cards.
3. Controller wiring.
4. Template badge row in `domain_detail.html.twig`.
5. Tests — 10 new + 2 in existing `DnsHealthOverviewTest`. PHPUnit + PHPStan + cs-fixer all green. Commit + push.

---

## TASK-014: Mailbox setup is a credentials wall — add server presets, port auto-fill, and live "test connection" before save

- Status: done
- Shipped: 2026-05-23
- Area: onboarding
- Why: `templates/dashboard/mailbox_add.html.twig` and the onboarding mailbox option throw the user at raw IMAP/POP3 host/port/encryption fields with no guidance. A non-technical Marketing-Maria persona will give up here. Worse, the form accepts the credentials, saves them encrypted, and only finds out it doesn't work via the next 15-min poll cron — so the user sees "Inactive / Error" minutes later with no clear reason on `dashboard_mailboxes`.
- Acceptance:
  - Provider preset dropdown above the host field: Gmail (imap.gmail.com:993, SSL), Outlook/Microsoft 365 (outlook.office365.com:993, SSL), Fastmail, Yahoo, Seznam, Custom. Selecting a preset auto-fills host/port/encryption.
  - Selecting Gmail/Outlook shows a yellow info banner: "Gmail/Outlook require app-passwords or OAuth2 — link to the relevant knowledge-base article (`learn/*`) explaining how to mint one." (OAuth2 button is out of scope for this task — DEC-034 is a later piece of work.)
  - Submitting the form triggers a synchronous `TestMailboxConnection` (IMAP login + capability check, ~3s timeout) before persisting. On failure: show the exact error inline ("Authentication failed", "Connection refused", "STARTTLS not supported"), keep the user on the form, do NOT persist the row.
  - On success: persist via existing `ConnectMailbox` command and redirect to mailboxes list with success flash.
  - The `dashboard_mailboxes` list grows a "Re-test connection" inline action button on each row that runs the same check on demand.
  - 100% test coverage on the new sync test service (mocked IMAP transport) and the controller branches.
- Notes:

### Architect plan (2026-05-23)

**Key findings:**
- `webklex/php-imap` v6.2 is the existing IMAP transport. `ClientManager::make()` accepts `"timeout" => 3` for connection timeout.
- `ConnectionTestResult` already exists — just extend with optional `?MailboxConnectionErrorCode $errorCode`.
- `FakeMailClient` already exists with `simulateFailure()` — covers the re-test endpoint via the existing `MailClient::testConnection(MailboxConnection)` seam.
- `ConnectMailboxHandler` currently calls `testConnection()` AFTER persisting (anti-pattern — persists broken rows then marks them errored). The wizard's pre-submit test makes this redundant; **remove** the handler's test call. Document the inverted contract in the handler ("caller must test before dispatching").
- A **new** interface `MailboxConnectionTester::test(MailboxConnectionAttempt)` is needed because the wizard tests BEFORE the entity exists (plaintext creds). Separate from the existing `MailClient::testConnection(MailboxConnection)` which decrypts a persisted entity.
- No CSRF on the current add form — add token id `mailbox_add`.
- `templates/learn/` directory doesn't exist yet — the Gmail/Outlook app-password banner uses `href="#"` placeholder.

**Architecture:**
- `src/Value/MailboxConnectionErrorCode.php` — enum with cases `AuthenticationFailed, ConnectionRefused, ConnectionTimeout, StarttlsNotSupported, InboxNotFound, Unknown` + `humanMessage(): string` method.
- `src/Value/MailboxConnectionAttempt.php` (readonly final): `host, username, password: string`, `port: int`, `encryption: MailboxEncryption`, `type: MailboxType`.
- `src/Value/MailboxProviderPreset.php` (readonly final class — not enum, can't carry typed props elegantly). Constructor: `key, label, host`, `port`, `encryption`, `requiresAppPassword`. Static `cases(): list<self>`, `find(string $key): ?self`, `presetsJson(): string`. Six cases:
  - gmail (`imap.gmail.com:993 SSL, requiresAppPassword`)
  - outlook (`outlook.office365.com:993 SSL, requiresAppPassword`)
  - fastmail (`imap.fastmail.com:993 SSL`)
  - yahoo (`imap.mail.yahoo.com:993 SSL`)
  - seznam (`imap.seznam.cz:993 SSL`)
  - custom (`'' / 993 / SSL, requiresAppPassword: false`)
- Extend `src/Value/ConnectionTestResult.php` with `public readonly ?MailboxConnectionErrorCode $errorCode = null` (optional, default null = backward compat).
- `src/Services/Mailbox/MailboxConnectionTester.php` interface — `test(MailboxConnectionAttempt $attempt): ConnectionTestResult`.
- `src/Services/Mailbox/ImapMailboxConnectionTester.php` (readonly final, production impl) — instantiates `Webklex\PHPIMAP\ClientManager` inline with `"timeout" => 3`, connects, gets INBOX folder, status check, disconnects. Catches exceptions, classifies via private `classifyError(string $message): MailboxConnectionErrorCode` (case-insensitive substring match: `auth/login failed/credentials → AUTHENTICATION_FAILED`; `refused → CONNECTION_REFUSED`; `timeout/timed out → CONNECTION_TIMEOUT`; `starttls → STARTTLS_NOT_SUPPORTED`; `inbox/folder → INBOX_NOT_FOUND`; else `UNKNOWN`).
- `src/Services/Mailbox/FakeMailboxConnectionTester.php` (test double) — `willSucceed()`, `willFail(MailboxConnectionErrorCode)`, also `wasInvoked(): bool` for "tester not called on validation failure" assertions.
- Extend `src/Services/Mail/ImapMailClient::testConnection()` to use 3s timeout + return `errorCode`. Add same `classifyError()` helper. The re-test endpoint reuses this.
- Modify `src/MessageHandler/ConnectMailboxHandler.php`: remove `MailClient` dependency + `testConnection()` call. Inline doc comment: "Caller must test the connection before dispatching — see AddMailboxController."

**Controllers:**
- Modify `src/Controller/Dashboard/AddMailboxController.php`: inject `MailboxConnectionTester`. CSRF validation (token id `mailbox_add`) first. Then `Validator` on `AddMailboxData`. Only if validation passes, build `MailboxConnectionAttempt` and run `tester->test()`. On failure: re-render form with `$connectionError = $result->errorCode->humanMessage()`. On success: dispatch `ConnectMailbox`, flash success, redirect to `dashboard_mailboxes`. Pass `$connectionError`, `$presets` (from `MailboxProviderPreset::cases()`), `$presetsJson` (from `presetsJson()`) to template.
- New `src/Controller/Dashboard/RetestMailboxConnectionController.php` — `#[Route('/app/mailboxes/{id}/test', name: 'dashboard_mailbox_retest', methods: ['POST'])]`. CSRF token id `mailbox_retest`. Load `MailboxConnection` via repo, 404 on not-found, 404 on cross-tenant (`team->id->equals($teamId)`). `$result = $mailClient->testConnection($connection)`. Success: flash `success "Connection is working."` Failure: flash `error "Connection failed: {humanMessage}"` (and include raw `$result->error` substring for debugging). Redirect to `dashboard_mailboxes`.

**Stimulus controller `assets/controllers/mailbox_preset_controller.js`** — identifier `mailbox-preset`. Values: `presets: Object` (JSON keyed by preset key). Targets: `select, host, port, encryption, banner`. Action: `change->mailbox-preset#presetChanged`. On `"custom"`: no field changes, hide banner. On a known preset: set host/port/encryption inputs, toggle banner via `hidden` attribute based on `requiresAppPassword`. Handle unknown keys gracefully (no-op).

**Templates:**
- Rewrite `templates/dashboard/mailbox_add.html.twig`: CSRF hidden input; preset `<select>` as FIRST field with `data-controller="mailbox-preset"` on the wrapping `<div>`, `data-mailbox-preset-presets-value="{{ presetsJson }}"`, `data-action="change->mailbox-preset#presetChanged"` on the select; yellow `<div role="alert" class="alert alert-warning" data-mailbox-preset-target="banner" hidden>` with the app-password explainer + `href="#"` placeholder; `{% if connectionError %}<div class="alert alert-error">{{ connectionError }}</div>{% endif %}` block; host/port/encryption fields wired as Stimulus targets.
- Modify `templates/dashboard/mailboxes.html.twig`: add `<th>Actions</th>`; per-row `<form method="post" action="{{ path('dashboard_mailbox_retest', {id: mailbox.id}) }}">` with CSRF + `<button class="btn btn-ghost btn-xs">Re-test</button>`.

**Service wiring in `config/services.php`:**
- Production: alias `App\Services\Mailbox\MailboxConnectionTester::class → ImapMailboxConnectionTester::class`.
- `when@test`: alias to `FakeMailboxConnectionTester`, make both `FakeMailboxConnectionTester` AND `ImapMailboxConnectionTester` public.

**Tests:**
- `tests/Unit/Value/MailboxConnectionErrorCodeTest.php` — 6 cases, `humanMessage()` non-empty + sensible.
- `tests/Unit/Value/MailboxProviderPresetTest.php` — 7 cases: `cases()` count, gmail values, outlook `requiresAppPassword=true`, custom `host=''`, `find('gmail')` non-null, `find('unknown')` null, `presetsJson()` valid JSON with all keys.
- `tests/Integration/Controller/MailboxWizardTest.php` — 12 cases: page renders / presets JSON in HTML / happy path (persists row + redirect + flash) / each error code (`AuthenticationFailed`, `ConnectionRefused`, `ConnectionTimeout`, `StarttlsNotSupported`, etc.) re-renders form inline / no DB row on test failure / validation error skips tester (asserts `wasInvoked()=false`) / no CSRF rejected / bad CSRF rejected / unauth redirects to login.
- `tests/Integration/Controller/RetestMailboxConnectionTest.php` — 8 cases: success flash / failure flash / 404 unknown id / 404 cross-tenant / no CSRF rejected / bad CSRF rejected / GET → 405 / unauth redirects to login.

**Critical details:**
- Connection test runs ONLY AFTER form validation passes — blank fields shouldn't generate confusing network errors.
- Error-message string-matching in `classifyError()` is case-insensitive substring (fragile but acceptable — Webklex wraps various underlying errors).
- Webklex's `"timeout"` config is the TCP connection timeout (no separate read timeout). 3s covers our connectivity-check use case.
- Re-test uses `MailClient::testConnection(MailboxConnection)` (existing seam, decrypts via `CredentialEncryptor`). No plaintext leaves function scope.
- KB article link is `href="#"` placeholder — note in commit that the article stub is a separate task.
- Removing `testConnection` from `ConnectMailboxHandler` means any future entry point dispatching `ConnectMailbox` must test first. Document inline.
- The existing `DashboardPagesTest::addMailboxPageReturns200()` smoke test continues to pass (form still renders).
- Search the test suite for any existing test that depended on the handler's post-persist `testConnection` call — fix those.

**Build phases:**
1. Value objects + their unit tests.
2. Tester interface + IMAP impl + Fake + `ImapMailClient` extension + handler simplification + service wiring.
3. Controllers (modify Add + new Retest).
4. Templates (rewrite Add form, modify mailboxes list).
5. Stimulus `mailbox_preset_controller.js`.
6. Integration tests (Wizard + Retest). `phpunit` + `phpstan` + `cs-fixer` all green. Smoke: GET `/app/mailboxes/add`, confirm preset dropdown renders; POST with `Custom` + fake fail confirms inline error.

---

## TASK-015: Alerts list/detail has no in-app actions beyond "mark read" — add snooze, mute-this-type-for-this-domain, and copy-link

- Status: done
- Shipped: 2026-05-23
- Area: dashboard
- Why: `templates/dashboard/alerts.html.twig` and `alert_detail.html.twig` only let the user mark an alert read. For a noisy domain (e.g. a forwarder ruining DMARC pass rate, generating "failure spike" alerts daily) the user has no recourse other than "delete email rule + mark every alert read forever." That turns the Alerts page from "what needs attention" into "inbox zero ritual," which kills trust in the channel.
- Acceptance:
  - `alert_detail.html.twig`: add a "Snooze" dropdown (1 day / 7 days / 30 days) that sets `snoozed_until` on the alert; snoozed alerts are excluded from the unread count and from the default `alerts.html.twig` list (visible only under a new "Snoozed" filter chip).
  - `alert_detail.html.twig`: add "Mute this alert type for this domain" — persists a `(team_id, domain_id, alert_type)` row in a new `muted_alert` table; the `RaiseAlert*` handlers consult this table before persisting future alerts (no-op when muted). User can un-mute from a new "Muted alerts" section on `team_settings` or `dashboard_preferences`.
  - "Copy alert link" button on alert detail header — copies the absolute URL via clipboard API, with a brief flash confirmation. Critical for support/Slack handoff.
  - Bulk action on `alerts.html.twig`: checkbox per row + a "Mark selected read" / "Snooze selected 7d" toolbar that appears only when at least one row is selected.
  - 100% test coverage on the snooze/mute commands + handlers + the query filter change.
- Notes:

### Architect plan (2026-05-23)

**Key architectural finding:** `AlertEngine::createAlert()` in `src/Services/AlertEngine.php` is the **single chokepoint** for all 5 alert-emitting handlers. Inject `MutedAlertRepository` there and add the mute check ONCE — no need to touch any of the 5 individual handlers. Return type changes from `Alert` to `?Alert`; all callers ignore the return.

**Migration `Version20260525000000.php`:** 
- ALTER `alert` ADD `snoozed_until TIMESTAMP NULL`; index `idx_alert_team_unread_snoozed (team_id, is_read, snoozed_until)`.
- CREATE `muted_alert(id UUID PK, team_id UUID, monitored_domain_id UUID, alert_type VARCHAR(64), muted_at TIMESTAMP)` with `UNIQUE(team_id, monitored_domain_id, alert_type)` and FKs to team + monitored_domain (ON DELETE CASCADE).

**Entities:**
- Modify `Alert.php` — add `?\DateTimeImmutable $snoozedUntil = null` property + `snoozeUntil(now)`, `unsnooze()`, `isSnoozed(now): bool` methods + index annotation.
- New `MutedAlert.php` — `final class` (Doctrine, not readonly), `readonly` properties: `id`, `team`, `monitoredDomain`, `alertType` (enum), `mutedAt`. NO `EntityWithEvents` (no events).

**New `MutedAlertRepository`** with: `isMuted(teamId, domainId, AlertType): bool` (DBAL hot path, indexed SELECT 1 LIMIT 1), `findForTeam(teamId): MutedAlert[]`, `findOneForTeamDomainType(...)`, `get($id)`, `findForTeams($id, $teamIds)`.

**Commands (`readonly final` in `src/Message/`):**
- `SnoozeAlert(UuidInterface $alertId, \DateTimeImmutable $snoozedUntil)`
- `UnsnoozeAlert(UuidInterface $alertId)`
- `MuteAlertType(UuidInterface $mutedAlertId, UuidInterface $teamId, UuidInterface $domainId, AlertType $alertType)`
- `UnmuteAlertType(UuidInterface $mutedAlertId)`
- `BulkMarkAlertsRead(list<UuidInterface> $alertIds, UuidInterface $teamId)`
- `BulkSnoozeAlerts(list<UuidInterface> $alertIds, UuidInterface $teamId, \DateTimeImmutable $snoozedUntil)`

**Handlers (`#[AsMessageHandler] readonly final`):** 6 corresponding handlers. Snooze/Unsnooze/BulkMarkRead/BulkSnooze follow `MarkAlertAsReadHandler` pattern (entity mutation, no explicit flush — Doctrine flushes at request end). **`MuteAlertTypeHandler` and `UnmuteAlertTypeHandler` MUST call `$entityManager->flush()` explicitly** because `MutedAlert` doesn't implement `EntityWithEvents` — no postFlush listener exists for it. Bulk handlers iterate IDs, call `findForTeams()` per id (cross-tenant IDs silently skipped).

**Query changes:**
- `GetAlerts::forTeams()` — add `bool $onlySnoozed = false` param. Default: `AND (a.snoozed_until IS NULL OR a.snoozed_until <= NOW())`. When `onlySnoozed`: `AND a.snoozed_until IS NOT NULL AND a.snoozed_until > NOW()`. Add `a.snoozed_until` to SELECT.
- `countUnreadForTeams` + `countUnreadCriticalForTeams` — both exclude currently-snoozed.
- `GetAlertDetail` — add `a.snoozed_until` to SELECT.
- New `GetMutedAlerts::forTeam(teamId): MutedAlertResult[]` joining `muted_alert` to `monitored_domain` for the preferences page.
- `AlertListResult` + `AlertDetailResult` + new `MutedAlertResult` get `snoozedUntil`/`mutedAt` fields.

**Controllers (CSRF tokens listed in architect plan: `snooze_alert`, `unsnooze_alert`, `mute_alert`, `unmute_alert`, `bulk_alert_action`):**
- `SnoozeAlertController` POST `/app/alerts/{id}/snooze` — whitelist `days` to {1,7,30}, default 7. Validates alert via `findForTeams` (team scoping). Redirects to referer or alerts list.
- `UnsnoozeAlertController` POST `/app/alerts/{id}/unsnooze`
- `MuteAlertTypeController` POST `/app/alerts/{id}/mute` — guards on `$alert->monitoredDomain !== null` (cannot mute team-wide alerts), checks for existing mute (idempotent), dispatches.
- `UnmuteAlertTypeController` POST `/app/muted-alerts/{id}/unmute`
- `BulkAlertActionController` POST `/app/alerts/bulk` — `action=mark_read` or `snooze_7d`, validates UUIDs from `alertIds[]`, no-op on empty selection.
- Modify `ListAlertsController` — add `snoozed=1` query param.
- Modify `ShowAlertDetailController` — inject `MutedAlertRepository`, pass `existingMute` to template.
- Modify `UserPreferencesController` — inject `GetMutedAlerts`, pass list to template.

**Templates:**
- `alert_detail.html.twig`: header gains Copy-link button (inline `navigator.clipboard.writeText` JS, no Stimulus needed); Snooze dropdown (daisyUI `dropdown` with three form-submits 1d/7d/30d) when not snoozed, "Snoozed until X — Unsnooze" form-submit when snoozed; Mute/Unmute form when alert has a domain (hidden when domain-less).
- `alerts.html.twig`: new Snoozed filter chip; bulk-action `<form>` wrapping the list with hidden CSRF + per-row `<input type="checkbox" name="alertIds[]">`; sticky bulk toolbar (`data-alert-selection-target="toolbar"`) with Mark read / Snooze 7d / Clear buttons; toolbar hidden until ≥1 selected. The existing alert card converts from anchor-wrapping-card to flex-row with checkbox + stretched-link card (matches TASK-018 pattern).
- `preferences.html.twig`: new "Muted Alert Types" section at the bottom — table of muted (domain × alert type × muted_at × Unmute button). Empty-state message when none.

**Stimulus:** new `assets/controllers/alert_selection_controller.js` — targets `toolbar`+`count`; actions `updateCount` on checkbox change, `clearAll` for the Clear button.

**Edge cases (decisions):**
- Muting is forward-only — doesn't retroactively hide existing raised alerts.
- Marking a snoozed alert read doesn't unsnooze it; both states coexist.
- Cross-tenant snooze/mute/unmute → 404 (controllers use `findForTeams`).
- Bulk with empty selection → redirect with no error.
- Bulk cross-tenant IDs → silently skipped by handler.
- Snooze on already-snoozed → overwrites with new `snoozedUntil`.
- Muting a domain-less alert → flash error + redirect to detail (no mute persisted).

**Tests (~30 new tests across 5 files):**
- `tests/Integration/Controller/AlertActionsTest.php` — ~24 methods covering all controllers + template assertions.
- `tests/Integration/Query/GetAlertsSnoozeFilterTest.php` — snoozed filter branches, expired snooze treated as not-snoozed, count exclusions.
- `tests/Unit/MessageHandler/SnoozeAlertHandlerTest.php` — handler called with correct DateTimeImmutable.
- `tests/Unit/MessageHandler/MuteAlertTypeHandlerTest.php` — persist + flush called.
- `tests/Unit/MessageHandler/BulkMarkAlertsReadHandlerTest.php` — skips null (cross-tenant) IDs.
- Extend `tests/Unit/Services/AlertEngineTest.php` (or create) — when `isMuted=true`, no persist; when `monitoredDomain=null`, mute check skipped.

**File map (28 create + 12 modify):**
- **Create:** migration; entity (`MutedAlert`); repository (`MutedAlertRepository`); 6 messages + 6 handlers; query+result for muted alerts; 5 new controllers; 1 Stimulus controller; 5 test files.
- **Modify:** `Alert` entity, `AlertEngine`, `GetAlerts`, `GetAlertDetail`, `AlertListResult`, `AlertDetailResult`, `ListAlertsController`, `ShowAlertDetailController`, `UserPreferencesController`, 3 templates.

**Build phases:** 1) Schema + entities + migration. 2) Repository + AlertEngine mute check + AlertEngine test. 3) Commands + handlers + handler tests. 4) Query changes + result DTOs + query tests. 5) Controllers (5 new + 3 modify) + route confirmation. 6) Templates + Stimulus. 7) Integration tests + coverage gate; phpunit + phpstan + cs-fixer green; commit; push.

---

## TASK-016: Reports list has no filters or search — adding domain/reporter/date-range filters is the single biggest "this app is usable" lift

- Status: done
- Shipped: 2026-05-23
- Area: reports
- Why: `templates/dashboard/reports.html.twig` + `_reports_table.html.twig` show a paginated table with no filters. Once a paying customer has 5+ domains and 100+ reports, finding "the Google reports for example.com in February that had pass rate under 80%" requires manually clicking through pages. The data is in `dmarc_report` already — the missing piece is a filter bar and the matching query parameters in `GetAllReports`.
- Acceptance:
  - Filter bar above the table on `dashboard_reports`: Domain (multiselect, populated from team's monitored domains), Reporter Org (multiselect, populated from distinct reporter_org values for the team), Pass-rate range (chips: 90%+, 70–90%, <70%), Date range (last 7d / 30d / 90d / custom).
  - Filters update the URL query string (so links are shareable) and re-fetch the table via the existing Turbo Frame; no full page reload.
  - "Search by reporter or domain" text input on the same bar (server-side `ILIKE`).
  - `GetAllReports::forTeams` gains optional named filter parameters; raw SQL change with parameterised filters (per project DBAL convention).
  - The same filter bar appears on `dashboard_domain_reports` (currently `_domain_reports_table.html.twig`), minus the Domain filter (already scoped).
  - 100% test coverage on the query for every combination of filter / no-filter.
- Notes:

### Architect plan (2026-05-23)

**Strategy:** URL-driven GET filters. Filter form does a full Turbo Drive page navigation (`data-turbo-action="advance"`) so the chip bar AND table both re-render and URL updates. Pagination links keep their existing `data-turbo-frame="reports-table"` for fast partial swaps. Unify `GetDomainReports` → `GetAllReports` (one query path, one filter code path).

**Files to create:**
- `src/Value/ReportsFilter.php` — `readonly final class` with constructor (domainIds, reporterOrgs, passRateBand, dateRange, dateFrom, dateTo, search), static `fromRequest(Request, ClockInterface): self`, `toQueryParams(): array`, `hasActiveFilters(): bool`, `passRateMin()`, `passRateMax()`. Validation in `fromRequest`: invalid pass_rate → null; non-UUID domain IDs filtered out (`Uuid::isValid()`); empty arrays → no filter; reversed custom dates swapped silently; `date_range=7d|30d|90d` → computes `dateFrom = clock.now().modify('-N days')`, dateTo null.
- `src/Query/GetReporterOrgs.php` — `readonly final`, single `forTeams(array $teamIds): list<string>`, SQL `SELECT DISTINCT dr.reporter_org FROM dmarc_report dr JOIN monitored_domain md ON md.id = dr.monitored_domain_id WHERE md.team_id IN (:teamIds) ORDER BY dr.reporter_org ASC`, `fetchFirstColumn()`. Empty teamIds guard.
- `templates/components/ReportsFilterBar.html.twig` — props `domains`, `reporterOptions`, `filter`, `formAction` (route name), `domainId = null`, `showDomainFilter = true`. Two rows in a `card`: chips row (pass-rate + date-range, each a `<a>` link with `data-turbo-action="advance"` that merges its value into `filter.toQueryParams()`); inputs row (`<form method="GET" data-turbo-action="advance" action="path(formAction, ...)">` with multiselect `<select multiple name="domain[]">` if `showDomainFilter`, `<select multiple name="reporter[]">`, text `<input name="q">`, submit + clear (clear is link to same form action without params)). Custom date inputs rendered always but hidden via `class="hidden"` unless `filter.dateRange == 'custom'`. daisyUI v5 `select select-bordered select-sm`, `input input-bordered input-sm`, `badge badge-lg`, `btn btn-sm`. Mobile: `flex-col sm:flex-row`, chips wrap with `flex-wrap`.
- `tests/Unit/Value/ReportsFilterTest.php` — ~17 unit tests covering each fromRequest branch + toQueryParams + hasActiveFilters + passRateMin/Max.
- `tests/Integration/Query/GetAllReportsFilterTest.php` — ~20 query-level tests covering each filter independently, combinations, team-scoping under filter, cross-tenant domain ID returns empty.
- `tests/Integration/Query/GetReporterOrgsTest.php` — distinct/sorted, empty results, empty teamIds, cross-team isolation.
- `tests/Integration/Controller/ReportsFilterTest.php` — ~18 integration tests on `/app/reports` and `/app/domains/{id}/reports` covering chip rendering, filter application, clear, pagination URL preservation, empty-with-filter vs empty-without-filter copy, cross-team security, invalid pass_rate ignored.

**Files to modify:**
- `src/Query/GetAllReports.php` — extend `forTeams()` with named args: `?string $domainId`, `?array $domainIds`, `?array $reporterOrgs`, `?string $passRateBand`, `?DateTimeImmutable $dateFrom`, `?DateTimeImmutable $dateTo`, `?string $search`. Build SQL with `$whereClauses[] = '...'` array joined by ` AND `, `$havingClauses[]` for pass-rate band (HAVING required because pass rate is post-GROUP-BY aggregate). Extract `PASS_RATE_EXPR` private constant. Use `ArrayParameterType::STRING` for all IN clauses. Mandatory `WHERE md.team_id IN (:teamIds)` stays.
- `src/Controller/Dashboard/ListReportsController.php` — inject `GetReporterOrgs`, `GetDomainOverview`, `ClockInterface`. Parse `ReportsFilter::fromRequest($request, $this->clock)`. Pass filtered args to `GetAllReports::forTeams()`. Add `'filter' => $filter, 'domains' => $domains, 'reporterOptions' => $reporterOptions, 'filterParams' => $filter->toQueryParams()` to render.
- `src/Controller/Dashboard/ListDomainReportsController.php` — switch from `GetDomainReports` to `GetAllReports` (call `forTeams($teamIds, ..., domainId: $id, ...)`). Same filter parse + injection as `ListReportsController`. Pass `showDomainFilter: false` and `domainId: $id` to template.
- `templates/dashboard/reports.html.twig` — `<twig:ReportsFilterBar domains=... reporterOptions=... filter=... formAction="dashboard_reports" />` ABOVE the `<turbo-frame id="reports-table">`. Empty-state branch differentiates filtered-empty vs truly-empty: `{% if reports is empty and currentPage == 1 %}{% if filter.hasActiveFilters() %}<p>No reports match the current filters.</p>{% else %}<twig:EmptyState .../>{% endif %}{% else %}<turbo-frame>...{% endif %}`.
- `templates/dashboard/domain_reports.html.twig` — same component with `formAction="dashboard_domain_reports" domainId="{{ domainId }}" showDomainFilter="{{ false }}"`.
- `templates/dashboard/_reports_table.html.twig` — pagination links use `path('dashboard_reports', filterParams|merge({'page': currentPage - 1}))` (and same for next).
- `templates/dashboard/_domain_reports_table.html.twig` — same. Switch from `DomainReportListResult` field access to `ReportListResult` (same field names except `domainName` is now present but unused — fine).

**Files to delete:**
- `src/Query/GetDomainReports.php`
- `src/Results/DomainReportListResult.php`
- `tests/Integration/Query/GetDomainReportsTest.php` (migrate any unique tests into `GetAllReportsFilterTest` as `legacyDomainIdParamFiltersCorrectly` etc.)

**URL scheme:** `?domain[]=uuid&reporter[]=foo&pass_rate=high|medium|low&date_range=7d|30d|90d|custom&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&q=text&page=N`.

**Pass-rate bands:** `high` ≥ 90, `medium` 70-89.99, `low` < 70. Implemented as `HAVING` against the existing pass-rate aggregate expression (extract to `PASS_RATE_EXPR` constant). For `medium`, two HAVING conditions joined with `AND`.

**Turbo behaviour:**
- Filter form: `<form method="GET" data-turbo-action="advance">` → full page navigation, URL updates, both filter bar (with new chip state) and frame re-render.
- Pagination `<a>`: keep `data-turbo-frame="reports-table"` → partial swap only, filter bar stays.
- Chip `<a>` links also use `data-turbo-action="advance"` because they need both the filter bar (chip selection visual change) and the table to update.

**Critical security:** Team scoping clause `WHERE md.team_id IN (:teamIds)` is mandatory. Any cross-tenant domain UUID in `domain[]=` just produces no matching rows — no PHP-side validation needed, the SQL guards it. Tests must include `crossTeamDomainIdInFilterDoesNotLeakData` and a query-level equivalent.

**Custom date range without JS:** date inputs always rendered but `class="hidden"` unless `filter.dateRange == 'custom'`. Selecting "Custom" chip via link navigates to `?date_range=custom`, re-renders the form with date inputs visible. User then fills and submits. No JS dependency for v1.

**Edge cases:**
- Empty `domain[]=` (no UUIDs) → treated as `null` (no filter).
- Non-UUID values in `domain[]` → filtered out by `Uuid::isValid()`.
- `pass_rate=garbage` → silently null.
- `date_from > date_to` → swapped silently.
- Pagination preserves filters via `filterParams|merge({'page': N})`.

**Build phases:**
1. Value object + GetReporterOrgs + GetAllReports extension; unit tests + query tests green.
2. Switch `ListDomainReportsController` to `GetAllReports`; delete `GetDomainReports` + DTO + test; migrate test cases.
3. Modify `ListReportsController`.
4. Twig component + template wiring; pagination updates.
5. Controller integration tests; phpunit + phpstan + cs-fixer all green; commit; push.

---

## TASK-017: Report-detail records table is a wall of raw rows — group by source IP/sender and label "what is this thing"

- Status: done
- Shipped: 2026-05-23 (commit `51c0612`)
- Area: reports
- Why: `templates/dashboard/report_detail.html.twig` renders one row per `DmarcReportRecord`, which for a high-volume reporter like Google can be 50+ rows of `66.249.93.x ... 1 ... none ... fail ... pass ...` that's impossible to interpret. The same source IP appears multiple times. The user can't easily answer "which actual services are sending as my domain?"
- Acceptance:
  - Above the existing flat records table, add a new "By sender" grouped view (default open): rows are grouped by `resolved_org` (falling back to PTR/hostname, then IP). Each group row shows: org/hostname, total messages, DKIM pass %, SPF pass %, disposition breakdown.
  - Clicking a group row expands the underlying per-IP / per-record rows beneath it (HTML `<details>` element — no JS framework needed).
  - For source IPs that match a known sender in `sender_inventory` for this team, display its authorization status badge (Authorized / Unknown) inline so the user sees "ugh, Mailchimp is failing DKIM" at a glance.
  - Move the existing flat table behind a "Show raw records" toggle (collapsed by default).
  - 100% test coverage on the grouping query.
- Notes:

### Architect plan (2026-05-23)

**Key schema findings:**
- `dmarc_record` already has both `resolved_org` (nullable) and `resolved_hostname` (nullable). Template already uses `record.resolvedHostname ?? record.resolvedOrg ?? '—'` — proves the fallback chain.
- The sender-inventory entity is `KnownSender` (table `known_sender`); unique key is `(monitored_domain_id, source_ip)`; has plain bool `is_authorized` (not multi-status). Mapping: `true` → "Authorized" badge, `false` → "Unauthorized", LEFT-JOIN-NULL → no badge.
- `GetReportDetail` is untouched by TASK-016 (it's a separate query). Safe to extend without affecting reports filter work.

**Data approach (Option B — SQL grouping):** New `GetReportSenderGroups` query runs a single `GROUP BY` with `LEFT JOIN known_sender`. Aggregates pass counts + disposition counts + `array_agg(DISTINCT source_ip)` in SQL. The drilldown is done in Twig: `for record in report.records if record.sourceIp in group.sourceIps` — reuses existing flat record list, no second round-trip.

**Group key fallback chain:** `COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip)` — same as display label.

**SQL (final):**
```sql
SELECT
    COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip) AS group_key,
    COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip) AS display_label,
    SUM(rec.count)                                                    AS total_messages,
    SUM(CASE WHEN rec.dkim_result = 'pass' THEN rec.count ELSE 0 END) AS dkim_pass_count,
    SUM(CASE WHEN rec.spf_result  = 'pass' THEN rec.count ELSE 0 END) AS spf_pass_count,
    SUM(CASE WHEN rec.disposition = 'none'        THEN rec.count ELSE 0 END) AS disposition_none,
    SUM(CASE WHEN rec.disposition = 'quarantine'  THEN rec.count ELSE 0 END) AS disposition_quarantine,
    SUM(CASE WHEN rec.disposition = 'reject'      THEN rec.count ELSE 0 END) AS disposition_reject,
    array_agg(DISTINCT rec.source_ip)                                AS source_ips,
    MAX(ks.is_authorized::int)                                       AS sender_is_authorized
FROM dmarc_record rec
JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
JOIN monitored_domain md ON md.id = dr.monitored_domain_id
LEFT JOIN known_sender ks ON ks.monitored_domain_id = dr.monitored_domain_id AND ks.source_ip = rec.source_ip
WHERE rec.dmarc_report_id = :reportId AND md.team_id IN (:teamIds)
GROUP BY group_key
ORDER BY total_messages DESC
```

Note `MAX(is_authorized::int)`: across multiple records in a group, if any IP is authorized (1) it wins over false (0); all-null → null (no badge). Correct semantics.

**Files to create:**
- `src/Results/ReportSenderGroupResult.php` — `readonly final`, fields: `groupKey: string`, `displayLabel: string`, `totalMessages: int`, `dkimPassCount: int`, `dkimPassRate: float` (computed: `round(pass/total*100, 1)`, 0.0 when total=0), `spfPassCount: int`, `spfPassRate: float`, `dispositionNone: int`, `dispositionQuarantine: int`, `dispositionReject: int`, `sourceIps: array<string>`, `senderIsAuthorized: ?bool`. Static `fromDatabaseRow()` factory; private static `parsePgArray(string $literal): array<string>` handles `{a,b,c}` → `[a,b,c]`, with `array_filter` to strip empties (handles `{NULL}` edge case).
- `src/Query/GetReportSenderGroups.php` — `readonly final`, `Connection`-injected, `forReport(string $reportId, list<string> $teamIds): list<ReportSenderGroupResult>`. Empty-teamIds guard returns `[]`. Uses `ArrayParameterType::STRING`. `array_map(ReportSenderGroupResult::fromDatabaseRow(...), $rows)`.
- `tests/Integration/Query/GetReportSenderGroupsTest.php` — 12 tests covering: empty teamIds, non-existent report, single-record group, grouping by resolved_org, grouping by hostname fallback, grouping by IP fallback, separating different orgs, aggregating DKIM pass count + rate (e.g. 13/18 → 72.2%), aggregating disposition counts, `senderIsAuthorized=true` when KnownSender authorized, `senderIsAuthorized=false` when unauthorized, cross-tenant isolation (team A's IDs + team B's reportId → `[]`).
- `tests/Integration/Controller/ReportDetailSenderGroupsTest.php` — 8 tests covering: 200 response, "By sender" heading present, group with `resolved_org=google.com` displays "google.com", DKIM pass-rate text "50%", disposition badge "reject" shown, "Authorized" badge with KnownSender(true), "Unauthorized" badge with KnownSender(false), `<details>` toggle wrapping raw records table.

**Files to modify:**
- `src/Controller/Dashboard/ShowReportDetailController.php` — inject `GetReportSenderGroups`. After fetching `$report`, call `$senderGroups = $this->getReportSenderGroups->forReport($id, $this->dashboardContext->getTeamIdStrings());`. Pass `'senderGroups' => $senderGroups` to render.
- `templates/dashboard/report_detail.html.twig` — insert "By sender" section between line 85 (close of donut grid) and line 88 (existing records card). Each group: `<details open>` with summary row containing display label (truncated at 50 chars with `…`, full label in `title` attr for hover), `{{ group.totalMessages }} msg`, `DKIM {pct}%` ghost badge, `SPF {pct}%` ghost badge, disposition badges (only shown when count > 0): `badge-info "{n} none"`, `badge-warning "{n} quarantine"`, `badge-error "{n} reject"`. Authorization badge: `badge-success "Authorized"` / `badge-error "Unauthorized"` / nothing. Inner drilldown table filters `report.records` via `if record.sourceIp in group.sourceIps` — table-xs styling with source IP, hostname, count, disposition, dkim, spf using existing `<twig:StatusBadge>`. Move existing records card (lines 88-121) INSIDE a `<details>` element with `<summary>Show raw records ({{ report.records|length }})</summary>` — default collapsed.

**Critical details:**
- `parsePgArray` robustness: strip outer `{}`, explode on `,`, `array_values(array_filter(...))` to handle empty/NULL edge cases.
- `senderIsAuthorized` from DBAL: `MAX(ks.is_authorized::int)` returns `int|null`. Cast in DTO: `null !== $row['sender_is_authorized'] ? (bool)(int)$row['sender_is_authorized'] : null`.
- Team isolation: `WHERE md.team_id IN (:teamIds)` on the report path; `LEFT JOIN known_sender` tied to same `monitored_domain_id` as the report → no cross-domain or cross-team leakage.
- Display label truncation in Twig (`length > 50 ? value[:50] ~ '…' : value`) — no PHP-side change needed; `title` attr carries full label.
- Twig `record in group.sourceIps`: works with `array<string>` per Twig's `in` semantics.

**Build phases:** 1) DTO + Query, PHPStan green. 2) ShowReportDetailController wiring. 3) Template restructure. 4) Tests; phpunit + phpstan + cs-fixer green; commit; push.

---

## TASK-018: Mobile dashboard nav highlight is broken on long page titles + table rows use `onclick` instead of `<a>` (a11y & middle-click broken)

- Status: done
- Shipped: 2026-05-23
- Area: dashboard
- Why: Two adjacent dashboard-quality cuts that show the app feels rushed: (1) Sidebar uses `current_route starts with 'dashboard_domain'` for the Domains item, which also matches `dashboard_domain_health` — but DNS Health is its own concept. More importantly, every table row that links to a detail page uses `<tr onclick="window.location='...'">` (see `overview.html.twig`, `_reports_table.html.twig`, `domain_detail.html.twig`). This breaks middle-click ("open in new tab"), keyboard navigation (rows are not focusable), screen readers, and right-click → copy link.
- Acceptance:
  - Replace every `<tr ... onclick="window.location=...">` in `templates/dashboard/**` with a proper anchor pattern: either wrap the whole row in `<a>` via CSS `display: table-row`, or use a `<td>` with `<a class="absolute inset-0">` (stretched-link pattern) — pick the one that keeps existing daisyUI table styling intact and document the choice in the template.
  - Rows become keyboard-focusable, middle-clickable, and right-click-copy-link works. Verify by tabbing through `dashboard_reports` and seeing each row receive focus ring.
  - Sidebar "Domains" active state must NOT trigger on `dashboard_domain_health` (or whatever route the in-app DNS Health page from TASK-001 lands on); use the route name explicitly or split the prefix check.
  - Mobile (<lg breakpoint): verify the sidebar overlay closes on link click (currently it does via the Stimulus controller, but worth a Cypress / Panther smoke).
  - Test plan note: include an axe-core accessibility scan baseline for `/app` and `/app/reports` in the PR.
- Notes:

### Architect plan (2026-05-23)

**Findings from surface scan:**
- Only 4 `<tr onclick>` instances in `templates/dashboard/**`: `_reports_table.html.twig:17`, `_domain_reports_table.html.twig:16`, `domain_detail.html.twig:149`, `overview.html.twig:250`. All structurally identical.
- None of the rows contains inner buttons or secondary `<a>` tags — no nested-anchor or z-index conflict.
- `_reports_table.html.twig` is wrapped in `<turbo-frame id="reports-table">` (TASK-016); `_domain_reports_table.html.twig` wrapped in `<turbo-frame id="domain-reports-table">`. Inline tables in `overview.html.twig` and `domain_detail.html.twig` are NOT inside any turbo-frame.
- **Sidebar bug doesn't exist:** all 7 routes in the `dashboard_domain*` namespace are genuine domain-area sub-pages (`dashboard_domains`, `_domain_add`, `_domain_detail`, `_domain_health`, `_domain_reverify`, `_domain_reports`, `_domain_dns_history`). `dashboard_dns_health` (the in-app overview from TASK-001) has prefix `dashboard_dns_` — does NOT match `dashboard_domain`. Sidebar is correct. **No change to `layout.html.twig`.**
- **Mobile sidebar overlay already works:** Stimulus controller at `assets/controllers/sidebar_controller.js` + Turbo Drive page morph reset CSS classes on navigation. No change.

**Decision: stretched-link pattern (Option B).** Keep `<tr>` semantic, add `<tr class="relative">` + `<a class="absolute inset-0 z-10">` inside the first `<td>`. Preserves daisyUI table semantics (zebra-striping, hover); native middle-click / right-click / keyboard focus; no nested-anchor issues; no `display: contents` weirdness.

**Pattern (copied verbatim into each of 4 files, with a leading template comment on first occurrence per file):**

```twig
{# Row-level navigation via stretched-link (a11y, middle-click, keyboard safe).
   The <tr> is position:relative; the <a> is absolute inset-0 inside the first <td>. #}
<tr class="hover:bg-base-200/50 cursor-pointer relative">
    <td class="font-medium">
        <a href="{{ path('dashboard_report_detail', { id: report.reportId }) }}"
           class="absolute inset-0 z-10"
           data-turbo-frame="_top"  {# only inside turbo-frames #}
           aria-label="View report from {{ report.reporterOrg }}"></a>
        {{ first-column-content }}
    </td>
    {# remaining <td>s unchanged #}
</tr>
```

**Per-file disposition:**
- `_reports_table.html.twig:17` — apply pattern WITH `data-turbo-frame="_top"` (frame escape needed). First column: `report.domainName`.
- `_domain_reports_table.html.twig:16` — apply pattern WITH `data-turbo-frame="_top"`. First column: `report.reporterOrg`.
- `domain_detail.html.twig:149` — apply pattern WITHOUT `data-turbo-frame`. First column: `report.reporterOrg`.
- `overview.html.twig:250` — apply pattern WITHOUT `data-turbo-frame`. First column: `report.domainName`. aria-label includes both reporter and domain.

**Files to create:** `tests/Integration/Controller/AccessibleRowNavigationTest.php` — 10 tests:
1. `reportListRowHasAnchorNotOnclick` — `/app/reports`, `table tbody tr td a[href*="/app/reports/"]` count > 0; body lacks "onclick"
2. `domainDetailRowHasAnchorNotOnclick` — `/app/domains/{id}`, same checks
3. `overviewRowHasAnchorNotOnclick` — `/app`, same
4. `domainReportsListRowHasAnchorNotOnclick` — `/app/domains/{id}/reports`, same
5. `reportListRowAnchorHasAriaLabel` — `table tbody tr td a[aria-label]` count > 0
6. `reportListRowAnchorHasTurboFrameTop` — `a[data-turbo-frame="_top"]` count > 0 (regression for frame escape)
7. `domainReportsListRowAnchorHasTurboFrameTop` — same on per-domain reports
8. `noOnclickInAnyDashboardPage` (global regression guard) — iterate over: `dashboard_overview`, `dashboard_domains`, `dashboard_reports`, `dashboard_alerts`, `dashboard_dns_health`, `dashboard_mailboxes`, `dashboard_billing`, `dashboard_preferences`, `team_settings`. For each: assert response body lacks `onclick=` substring.
9. `sidebarDomainsHighlightedOnDomainSubpages` — for `dashboard_domain_detail`, `dashboard_domain_health`, `dashboard_domain_reports`: Domains nav anchor has `bg-primary` class.
10. `sidebarDomainsNotHighlightedOnDnsHealthOverview` — on `/app/dns-health`: Domains nav anchor LACKS `bg-primary`; DNS Health nav anchor HAS `bg-primary`.

**axe-core baseline:** SKIP — no Panther/Cypress infrastructure in the test suite. Stretched-link pattern addresses the concrete a11y failures (keyboard focus + screen-reader label) which are tested explicitly above. Document in commit message.

**Critical detail on future-proofing:** if a future developer adds a `<button>` or secondary `<a>` inside one of these rows, that inner control's containing `<td>` (or the control itself) needs `class="relative z-20"` to win pointer events. Document in the template comment.

**Build phases:** 1) 4 template edits. 2) Browser smoke (middle-click, right-click, Tab focus). 3) New test file (10 methods). 4) phpunit + phpstan + cs-fixer green. 5) Commit + push.

---

## TASK-019: Billing page hides the most valuable view — show this team's actual usage vs. limit for the metric that actually triggers churn (reports/mo, retention)

- Status: done
- Shipped: 2026-05-23
- Area: dashboard
- Why: `templates/dashboard/billing.html.twig` shows only domains-used and seats-used. The real bill-vs-value question for a deliverability customer is: "Am I getting close to my monthly report cap (Free: 100, Personal: 1k, Pro: 10k, Business: 50k)?" and "How far back can I look at my data on this plan?". Those numbers exist in `team_usage` and `PlanLimits::getRetentionDays` already — they're just not surfaced. This is also where the `PlanOverage` quarantine pile-up makes itself felt: a customer can have N reports quarantined and never know unless they bump into the (deferred) usage-warning email.
- Acceptance:
  - Billing page grows a third panel after Domains / Seats: "Monthly reports" with current count, limit, percentage bar (matches existing pattern), and the period-reset date pulled from `team_usage.period_ends_at`. Hidden on Unlimited.
  - Same panel grows a sub-line: "Retention: keeping reports for N days" (or "unlimited"), with a 1-line nudge ("Upgrade to Pro for 2-year retention →") for plans below their next-tier retention.
  - If the team has any reports in `quarantined_dmarc_report` with reason `PlanOverage`, surface a warning card above the panel: "N reports waiting — they were received after you hit this month's cap. Upgrade to unlock them." with link to upgrade flow.
  - The same monthly-reports widget is added as a 6th stat card on `dashboard_overview` (only when the team isn't Unlimited and is over 50% used — keeps the overview clean for low-usage users).
  - 100% test coverage on the new `GetMonthlyReportUsage` query + the overview-card visibility branches.
- Notes:

### Architect plan (2026-05-23)

**Key finding:** `GetTeamPlan::forTeam()` already exists and returns `SubscriptionPlan` directly. `PlanLimits` is already injected into `BillingController`. `team_usage` table exists from migration `Version20260524200000.php`. Schema columns: `reports_parsed_count` (the current count), `period_ends_at`. `QuarantinedDmarcReport.domain_name` is lowercased; `MonitoredDomain.domain` is stored as-entered — use `LOWER(md.domain) = qdr.domain_name` in joins (matches `QuarantinedDmarcReportRepository::countForDomain` which calls `strtolower($domainName)`).

**Files to create:**
- `src/Query/GetMonthlyReportUsage.php` — `readonly final`, Connection-injected, single method `forTeam(string $teamId): ?MonthlyReportUsageRawResult`. SQL is a single statement: `SELECT tu.reports_parsed_count, tu.period_ends_at, (SELECT COUNT(*) FROM quarantined_dmarc_report qdr JOIN monitored_domain md ON LOWER(md.domain) = qdr.domain_name WHERE md.team_id = :teamId AND qdr.reason = 'plan_overage') AS plan_overage_quarantine_count FROM team_usage tu WHERE tu.team_id = :teamId`. Return null when `fetchAssociative()` returns false.
- `src/Results/MonthlyReportUsageRawResult.php` — `readonly final` DTO: `currentCount: int`, `periodEndsAt: \DateTimeImmutable`, `planOverageQuarantineCount: int`. Static `fromDatabaseRow()`.
- `src/Results/MonthlyReportUsageResult.php` — `readonly final` DTO: `currentCount`, `limit` (int), `percentageUsed` (float, capped at 100.0), `periodEndsAt`, `planOverageQuarantineCount`, `isUnlimited` (bool — true when `getMaxReportsPerMonth() === PHP_INT_MAX`), `retentionDays` (?int). Method `nextTierRetentionUpsell(): ?string` — pure `match` on `$this->retentionDays`: null → null (Business or Unlimited has no upgrade msg), 30 → "Upgrade to Personal for 1-year retention →", 365 → "Upgrade to Pro for 2-year retention →", 730 → "Upgrade to Business for unlimited retention →".
- `tests/Integration/Query/GetMonthlyReportUsageTest.php` — 8 cases: returnsNullWhenNoTeamUsageRow, returnsCurrentCountAndPeriodEndsAt, returnsZeroOverageWhenNoneExist, countsPlanOverageReports, excludesOtherTeamsQuarantine, excludesNonOverageQuarantineReasons, countsMultiplePlanOverageForSameDomain, periodEndsAtHydratesCorrectly. Insert `team_usage` rows directly via DBAL (no entity).
- `tests/Unit/Results/MonthlyReportUsageResultTest.php` — 4 tests for `nextTierRetentionUpsell()` (each branch).

**Files to modify:**
- `src/Controller/Dashboard/BillingController.php` — inject `GetMonthlyReportUsage`. After existing `$billing = $this->getBillingOverview->forTeam($teamId);`, call `$rawUsage = $this->getMonthlyReportUsage->forTeam($teamId);`. If non-null: resolve `$maxReports = $this->planLimits->getMaxReportsPerMonth($billing->plan)`, `$retentionDays = $this->planLimits->getRetentionDays($billing->plan)`, build `MonthlyReportUsageResult` with `isUnlimited: PHP_INT_MAX === $maxReports`. Else `$reportUsage = null`. Pass `'reportUsage' => $reportUsage` to render.
- `templates/dashboard/billing.html.twig` — ABOVE the "Current Plan" card div, when `reportUsage.planOverageQuarantineCount > 0`: warning card `bg-warning/10 border border-warning/30` with copy "N reports waiting — they were received after you hit this month's cap. Upgrade to unlock them." with link to upgrade flow (use the existing pricing/upgrade route the billing template already references). INSIDE the "Current Plan" card after the Domains/Members grid: new "Monthly reports" panel matching existing percentage-bar pattern (`progress progress-success` when <70%, `progress-warning` when 70-89%, `progress-error` when >=90%). Show current/limit + percentage + "Resets {periodEndsAt|date('M j, Y')}". Wrap entire panel in `{% if reportUsage is not null and not reportUsage.isUnlimited %}`. Below the panel: "Retention: keeping reports for {N} days" (or "unlimited" when `retentionDays` is null); plus `{% if reportUsage.nextTierRetentionUpsell() is not null %}<a href="{{ path('pricing') }}">{{ reportUsage.nextTierRetentionUpsell() }}</a>{% endif %}`. The upsell link goes to pricing (lets user compare), unlike the PlanOverage warning which goes straight to upgrade flow.
- `src/Controller/Dashboard/DashboardOverviewController.php` — additive injections (`GetMonthlyReportUsage`, `GetTeamPlan`, `PlanLimits`). After existing logic (reuses `$teamId = $this->dashboardContext->getTeamId()` already called at ~line 104 for `$hasMailbox`): `$rawUsage = $this->getMonthlyReportUsage->forTeam($teamId);`. Compute `$overviewReportUsage = null; $showReportUsageCard = false;`. If `$rawUsage !== null`: resolve plan via `GetTeamPlan::forTeam()` + PlanLimits, build the result DTO, set `$showReportUsageCard = !$overviewReportUsage->isUnlimited && $overviewReportUsage->percentageUsed >= 50.0`. Pass both vars to render. **Do NOT disturb TASK-002 work** (NextActionResolver / HealthSummaryResolver) — additive only.
- `templates/dashboard/overview.html.twig` — change stat-grid container from current `lg:grid-cols-5` to `{{ showReportUsageCard ? 'lg:grid-cols-6' : 'lg:grid-cols-5' }}` (read current file to confirm exact grid class). Add conditional 6th card after the Alerts card: `{% if showReportUsageCard %}<div class="stat ..."><div class="stat-title">Reports this month</div><div class="stat-value">{{ overviewReportUsage.currentCount }}</div><div class="stat-desc">of {{ overviewReportUsage.limit }} ({{ overviewReportUsage.percentageUsed|number_format(0) }}%)</div></div>{% endif %}`. Match the existing stat-card styling.
- `tests/Integration/Controller/BillingPagesTest.php` — 6 new methods: `billingPageShowsMonthlyReportsPanel` (insert `team_usage` via DBAL), `billingPageHidesPanelOnUnlimitedPlan`, `billingPageShowsPlanOverageWarning` (insert `team_usage` + `QuarantinedDmarcReport` with PlanOverage), `billingPageHidesWarningWhenZeroOverage`, `billingPageShowsRetentionSubLine` (Free plan → "30 days"), `billingPageShowsRetentionUpsellNudge` (Free → "Upgrade to Personal").
- `tests/Integration/Controller/DashboardPagesTest.php` — 4 new methods: `overviewShowsReportUsageCardWhenOver50Percent` (insert `team_usage` with count = 600 on Personal plan limit 1000), `overviewHidesCardWhenBelow50Percent` (count = 400), `overviewHidesCardOnUnlimitedPlan`, `overviewHidesCardWhenNoTeamUsageRow`.

**Critical details:**
- `isUnlimited` signal: `PlanLimits::getMaxReportsPerMonth()` returns `PHP_INT_MAX` for Unlimited. Use exact-int comparison `PHP_INT_MAX === $maxReports` in the controller.
- `retentionDays === null` is correct for Business plan (unlimited retention with 50k-reports/mo cap). Template should print "unlimited" for this case.
- `team_usage` row missing → both pages render cleanly with no panel/card; this is the state for any team that has never had a report parsed.
- `percentageUsed` capped at 100.0 in the constructor (`min(100.0, ...)`) to prevent UI overflow on edge cases.
- Plan transition mid-period: `team_usage` count tracks ingestion as-it-happens; PlanLimits resolves current plan's limit. Acceptable behavior.
- Test fixture limitation: existing personas don't insert `team_usage` rows. Tests must insert via DBAL `Connection::executeStatement()` (no entity for `team_usage`).
- Quarantine join: `LOWER(md.domain) = qdr.domain_name` (matches existing convention since `QuarantinedDmarcReport` lowercases on construction).

**Build phases:** 1) DTOs + Query + DTO/query tests. 2) BillingController + billing.html.twig + billing tests. 3) DashboardOverviewController + overview.html.twig + overview tests. 4) phpunit + phpstan + cs-fixer green; commit; push.

---

## TASK-020: Quarantine pile-up is invisible from the dashboard — give users a single page to see "reports we received but parked"

- Status: done
- Shipped: 2026-05-23
- Area: dashboard
- Why: `QuarantinedDmarcReport` is referenced from `overview.html.twig` (unverified-domain banner) and from `domain_detail.html.twig` (count badge), but the user can never actually **see** the underlying envelopes. With three quarantine reasons in production (`UnknownDomain`, `PlanOverage`, parser failures), the user has no way to answer "did receivers actually send reports for my domain?" or "what's in the pile?". Per the project's `never-delete-user-data` rule we keep them, so we owe the user visibility.
- Acceptance:
  - New in-app route `dashboard_quarantine` (sidebar link added under Reports as a secondary item, or as a tab on the Reports page — pick one and stick to it). Lists quarantined envelopes with: received-at, reporter from address, claimed domain, reason badge, size.
  - Each row expandable / linked to a detail view showing the raw envelope subject + the structured `quarantine_reason` payload, plus a "Reprocess now" button that dispatches `ReprocessQuarantinedReport` (matches existing `sendvery:reports:reprocess` semantics).
  - Reason `UnknownDomain` rows offer a one-click "Add this domain" action that pre-fills `dashboard_domain_add` with the claimed domain, and on successful add automatically triggers reprocess of the quarantined envelopes for that domain name.
  - Reason `PlanOverage` rows show a banner pointing at upgrade flow.
  - Empty state: friendly "No reports in quarantine — every report we received has been parsed." copy with a link to the most recent report.
  - 100% test coverage on the new query, controller, and the add-domain-+-reprocess flow.
- Notes:
  - Shipped commit `820dc8d`. Critical scoping fix during review round: `UnknownDomain` rows for domains no team yet owns require visibility via the receiving `mailbox_connection.team_id` (the architect's two-branch WHERE missed this case entirely). The query now unions monitored-domain ownership OR mailbox ownership; central-inbox (`mailbox_connection_id IS NULL`) UnknownDomain rows remain invisible (correct — no team can claim ownership).

### Architect plan (2026-05-23)

**No migration needed.** `QuarantinedDmarcReport` entity is complete. `ReceivedReportEmail` (joined via `received_email_id` FK) carries `from_address`, `subject`, `size_bytes`, `received_at`. No schema change.

**Sidebar placement: dedicated top-level nav entry** between Reports and Alerts, always visible (hiding when empty would make the empty state unreachable). Active-state check: `current_route starts with 'dashboard_quarantine'`. Inline `badge badge-xs badge-warning` shows when count > 0.

**Team-scoping for the DBAL query.** `quarantined_dmarc_report` has no `team_id`. Scope via `monitored_domain`: `LEFT JOIN monitored_domain md ON LOWER(md.domain) = LOWER(q.domain_name) AND md.team_id = :teamId`, with WHERE `md.team_id = :teamId OR (q.reason = 'unknown_domain' AND EXISTS (SELECT 1 FROM monitored_domain mdx WHERE LOWER(mdx.domain) = LOWER(q.domain_name) AND mdx.team_id = :teamId))`. Shows `UnverifiedDomain` + `PlanOverage` rows for the team's own domains and `UnknownDomain` rows for domains the team has since added.

**`src/Query/GetQuarantineList.php`** (readonly final, DBAL Connection):
- `forTeam(string $teamId, int $limit = 50, int $offset = 0): array<QuarantineListResult>` — team-scoping WHERE, ORDER BY `q.quarantined_at DESC`, LIMIT/OFFSET. SELECTs: `q.id AS quarantine_id`, `q.domain_name`, `q.reporter_email`, `q.reason`, `q.quarantined_at`, `q.expires_at`, `e.subject`, `e.size_bytes`. JOIN `received_report_email e ON e.id = q.received_email_id`.
- `countForTeam(string $teamId): int` — same WHERE, `SELECT COUNT(*)`; used by sidebar Twig extension.

**`src/Results/QuarantineListResult.php`** (readonly final): `quarantineId, domainName, reporterEmail, reason, quarantinedAt, expiresAt: string`, `subject: string`, `sizeBytes: int`. Static `fromDatabaseRow(array $row): self` with docblock shape.

**`src/Query/GetQuarantineDetail.php`** (readonly final, DBAL): `forTeam(string $quarantineId, string $teamId): ?QuarantineDetailResult` — same team-scoping WHERE plus `q.id = :quarantineId`; additionally SELECTs `e.id AS envelope_id`. Null → 404 in controller.

**`src/Results/QuarantineDetailResult.php`** (readonly final): list-result fields + `envelopeId: string`.

**Empty-state "most recent report" link:** Use `GetAllReports::forTeams($teamIds, limit: 1)` (already ORDER BY `date_range_end DESC`). Pass `mostRecentReportId: ?string` to template.

**`src/Message/ReprocessQuarantinedReport.php`** (readonly final): `public UuidInterface $quarantineId`, `public UuidInterface $teamId`. Handler reloads quarantine row, dispatches `ProcessReceivedReportEmail($quarantined->receivedEmail->id)`, removes the row, flushes. Null-load → return silently (idempotent).

**`src/MessageHandler/ReprocessQuarantinedReportHandler.php`** (readonly final, `#[AsMessageHandler]`): injects `QuarantinedDmarcReportRepository`, `MessageBusInterface`, `EntityManagerInterface`.

**`QuarantinedDmarcReportRepository::find(UuidInterface $id): ?QuarantinedDmarcReport`** — single `entityManager->find(...)`.

**Add-domain-from-quarantine flow — two existing messages, zero new commands.** Controller dispatches `AddDomain($domainId from IdentityProvider, $teamId, $domainName)` then `ReleaseQuarantinedReportsForDomain($domainId, $domainName)`. Gated to `reason === 'unknown_domain'` (404 otherwise). Guard via `MonitoredDomainRepository::findAnyByName` — if same team, skip `AddDomain`; if other team, redirect to `domain_taken`. `PlanEnforcement::canAddDomain` first.

**Controllers (`src/Controller/Dashboard/`, single-action `__invoke`):**
1. `ListQuarantineController` — `#[Route('/app/quarantine', name: 'dashboard_quarantine', methods: ['GET'])]`. Injects `DashboardContext`, `GetQuarantineList`, `GetAllReports`. Paginates at 50.
2. `ShowQuarantineDetailController` — `#[Route('/app/quarantine/{id}', name: 'dashboard_quarantine_detail', methods: ['GET'])]`.
3. `ReprocessQuarantinedReportController` — `#[Route('/app/quarantine/{id}/reprocess', name: 'dashboard_quarantine_reprocess', methods: ['POST'])]`. CSRF `quarantine_reprocess`. 404-guard via `GetQuarantineDetail::forTeam`. Dispatches `ReprocessQuarantinedReport`. Flash + redirect to list.
4. `AddDomainFromQuarantineController` — `#[Route('/app/quarantine/{id}/add-domain', name: 'dashboard_quarantine_add_domain', methods: ['POST'])]`. CSRF `quarantine_add_domain`. `reason === 'unknown_domain'` guard (404). Plan limit + conflict guards. Dispatches `AddDomain` + `ReleaseQuarantinedReportsForDomain`. Redirects to `dashboard_domain_detail`.

**Authorization:** `DashboardContext::getTeamId()` → query WHERE — same pattern as `ShowReportDetailController`. No voter.

**`src/Twig/QuarantineCountExtension.php`** — `AbstractExtension` + `GlobalsInterface`. `getGlobals()` returns `['quarantine_count' => $this->resolveCount()]`. Wraps `countForTeam` in try/catch `\RuntimeException` → 0 (public/unauth pages). Autoconfigured as `twig.extension`.

**Sidebar nav change in `templates/dashboard/layout.html.twig`** — new `<a>` block after Reports, before Alerts:

```twig
<a href="{{ path('dashboard_quarantine') }}"
   class="flex items-center gap-3 px-3 py-2 rounded-btn text-sm font-medium {{ current_route starts with 'dashboard_quarantine' ? 'bg-primary text-primary-content' : 'text-base-content/70 hover:bg-base-300 hover:text-base-content' }}">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4"></path></svg>
    Quarantine
    {% if quarantine_count > 0 %}<span class="badge badge-xs badge-warning ml-auto">{{ quarantine_count }}</span>{% endif %}
</a>
```

**`templates/dashboard/quarantine.html.twig`** — extends layout. Breadcrumbs `<li>Quarantine</li>`. Table columns: Received, Domain, Reporter, Reason, Size. Rows use stretched-link anchor pattern (TASK-018) → `dashboard_quarantine_detail`. Reason badges: `unknown_domain → badge-warning "Unknown domain"`, `unverified_domain → badge-info "Unverified domain"`, `plan_overage → badge-error "Plan overage"`. Size `{{ (item.sizeBytes / 1024)|number_format(1) }} KB`. Pagination via `btn btn-sm btn-ghost` prev/next. Empty state: plain `<div>` card (avoid `<twig:EmptyState>` because we need a conditional link inside) with copy + optional "View most recent report" `btn btn-sm btn-primary`.

**`templates/dashboard/quarantine_detail.html.twig`** — extends layout. Metadata card (subject, domain, reporter, timestamps, size, reason badge). `plan_overage` → `<div class="alert alert-warning">` + billing link. `unknown_domain` → add-domain form (CSRF + `btn btn-warning btn-sm`). Reprocess form always present (CSRF + `btn btn-primary btn-sm`). Two **sibling** `<form>` tags — no nesting.

**Tests — `tests/Integration/Controller/QuarantineTest.php`** (~20 cases). Helper `bootClientWithQuarantinedReport(QuarantineReason $reason): array` creates `User`+`Team`+`TeamMembership`+(conditional `MonitoredDomain`)+`ReceivedReportEmail` (unique `messageId = Uuid::uuid7()->toString()`, valid `source` enum, `gzencode('<xml/>')`)+`QuarantinedDmarcReport`.

Cases: list renders / empty-state / empty-with-recent-link / empty-without-recent-link / detail renders / 404-unknown / 404-cross-tenant / add-form visible for unknown / add-form hidden for plan-overage / plan-overage banner / reprocess CSRF rejected / reprocess happy (redirects + deletes row) / reprocess 404 cross-tenant / add-domain CSRF rejected / add-domain happy (creates `monitored_domain`, redirects to detail) / add-domain 404 non-unknown / add-domain 404 cross-tenant / sidebar badge present / sidebar badge absent.

**Tests — `tests/Integration/Query/GetQuarantineListTest.php`** (~4 cases): count excludes cross-tenant / count includes unknown-domain rows after team adds the domain / `forTeam` paginates / count = 0 for fresh team.

**Critical details:**
- `ReceivedReportEmail` `messageId` must be unique per row (constraint `uniq_envelope_source_msgid`) → `Uuid::uuid7()->toString()` in helper.
- DBAL query must NOT select `q.report_xml_gz` (blob).
- After `AddDomain` + `ReleaseQuarantinedReportsForDomain`, the domain is owned by the team — releasing its quarantine rows is correct.
- The Twig `quarantine_count` global handles unauth pages via try/catch → 0.
- The unknown-domain add-domain path must handle the race where the team already added the domain between page load and submit: `findAnyByName` non-null + same team → skip `AddDomain`, just release.

**Build phases:**
1. `QuarantinedDmarcReportRepository::find`.
2. Result DTOs + `GetQuarantineList` (forTeam + countForTeam) + `GetQuarantineDetail`. Write `GetQuarantineListTest` (4 cases).
3. `ReprocessQuarantinedReport` message + handler.
4. Four controllers; `bin/console debug:router | grep quarantine`.
5. `QuarantineCountExtension`.
6. Sidebar nav entry.
7. Two templates.
8. `QuarantineTest` (~20 cases). phpunit --coverage-min=100 + phpstan + cs-fixer all green; commit + push.

---

## TASK-021: Onboarding "I'll set up later" exit is a one-way ramp — bring it back into the dashboard as a dismissible setup checklist

- Status: done
- Shipped: 2026-05-23
- Area: onboarding
- Why: The `Skip — I'll set this up later` link on `templates/onboarding/ingestion.html.twig` marks onboarding complete but leaves the user on `dashboard_overview` with no in-app reminder of the work they skipped (verifying DNS, connecting a mailbox). The skipped state is **the most fragile state for retention**: they paid (or signed up), saw a half-empty dashboard, and have nothing nudging them back to finish. Tied to TASK-002 but distinct: this is about persistence and dismissibility, not the "next action" surface itself.
- Acceptance:
  - New persistent "Setup checklist" card on `dashboard_overview`, rendered only while at least one of these is incomplete for the active team: DMARC verified, mailbox connected OR DNS-forwarding verified (i.e. first report arrived), domain count > 0, team name customised (still default "My Team"?).
  - Each row: green check / outline circle, one-line description, "Do it →" link to the exact in-app surface that resolves it.
  - "Hide checklist" button — sets a `setup_checklist_dismissed_at` column on `team` (not `user` — team-scoped) so dismissal is shared across team members.
  - Auto-un-dismiss if a previously-completed step regresses (e.g. DMARC TXT goes missing).
  - Logic lives in a `SetupChecklistResolver` testable service that returns a list of typed step objects (matches the pattern proposed for TASK-002's NextActionResolver — these can share scaffolding).
  - 100% test coverage on resolver for all completion combinations.
- Notes:

### Architect plan (2026-05-23)

**Important findings from codebase scan:**
- Teams are auto-named after the user's email domain in `src/Services/TeamProvisioner.php` (lines 43-48) — NOT `"My Team"`. The acceptance criterion's "team name customised" step is **dropped** (no stable default to compare against). 3 steps total, not 4.
- `MonitoredDomain` already has `dmarcVerifiedAt` and `firstReportAt` non-null timestamps — these are the completion signals for steps 2 and 3.
- `GetDomainVerificationStatus` already provides `dmarcVerifiedAt`, `firstReportAt`, `consecutiveDmarcFailures` — perfect inputs for the resolver.
- `NextActionResolver` (TASK-002) is the pattern to mirror: pure computation service, no DB access, typed result objects.

**Regression auto-un-dismiss decision:** The resolver computes `isVisible` from current state + a `hasDmarcRegression` flag. The DB column `setup_checklist_dismissed_at` is never cleared on regression — the resolver overrides the dismissal in-memory when `dmarcVerifiedAt != null && consecutiveDmarcFailures >= 2`. Simpler, no new event listeners, no extra flushes on DNS-check hot path.

**Steps (3):**
1. `add_domain` — `domainCount > 0` — links to `dashboard_domain_add`
2. `publish_dmarc` — any team domain has `dmarcVerifiedAt != null` — links to `dashboard_domains`
3. `receive_reports` — any domain has `firstReportAt != null` OR `hasMailbox == true` — links to `dashboard_dns_health`

**Migration `Version20260527000000.php`:** ALTER TABLE team ADD COLUMN `setup_checklist_dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL` with `(DC2Type:datetime_immutable)` comment. No index.

**Entity `Team`:** Add `public ?\DateTimeImmutable $setupChecklistDismissedAt = null` (after `planWarningAt`, same pattern), and a `dismissSetupChecklist(\DateTimeImmutable $at): void` mutator. No unset/clear method (resolver handles regression in-memory).

**Value object `src/Value/SetupChecklistStep.php`** (readonly final): `id, title, description, actionRoute, actionLabel: string`, `actionRouteParams: array<string,string>`, `isComplete: bool`.

**Result `src/Results/SetupChecklistResult.php`** (readonly final): `steps: array<SetupChecklistStep>`, `completedCount, totalCount: int`, `isVisible, isFullyComplete: bool`.

**Service `src/Services/SetupChecklistResolver.php`** (readonly final) — pure computation:
```
resolve(
    int $domainCount,
    bool $anyDomainHasDmarcVerified,
    bool $anyDomainHasFirstReport,
    bool $hasMailbox,
    ?\DateTimeImmutable $dismissedAt,
    bool $hasDmarcRegression,
): SetupChecklistResult
```
Visibility rule: `$anyIncomplete && (!$isDismissed || ($hasDmarcRegression && $publishDmarcStep->isComplete))`. Fully-complete trumps everything (never visible when all 3 steps done).

**Controller modification — `DashboardOverviewController`:** Inject `SetupChecklistResolver` + `TeamRepository`. Compute inputs from already-fetched `$verificationStatus`, `$hasMailbox`, `count($domains)`. Load team via `TeamRepository::get($teamId)` to access `setupChecklistDismissedAt`. Pass `setupChecklist` to template render.

**New controller `DismissSetupChecklistController`** — `#[Route('/app/setup-checklist/dismiss', name: 'dashboard_setup_checklist_dismiss', methods: ['POST'])]`. CSRF token id `setup_checklist_dismiss`. On valid token: load team via `TeamRepository::get`, call `dismissSetupChecklist($clock->now())`, flush, redirect to `dashboard_overview`. Invalid CSRF → 403.

**Template change — `templates/dashboard/overview.html.twig`:** Insert checklist block BEFORE the Next Action card (before the `{% set actionTone ... %}` line ~55). Renders only when `setupChecklist.isVisible`. Card has: header with "Setup checklist" + "X of N steps complete" + "Hide checklist" button (form POSTing to the dismiss route). Each step row: filled green circle with check (if complete, opacity-60 + strikethrough title) or outline circle (if incomplete, full description + "Do it →" outline-primary button). daisyUI 5 only — no `dark:` prefix, no nested `<twig:>`, no `{% block %}` inside loops.

**Unit tests `tests/Unit/Services/SetupChecklistResolverTest.php`** (~14 cases):
- All steps incomplete / only domain added / dmarc-verified completes step 2 / first-report completes step 3 / mailbox completes step 3 / mailbox without domain doesn't complete step 1 / dismissed hides when no regression / dismissed+regression overrides / regression without ever-verified DMARC doesn't override / fully-complete is not visible / fully-complete ignores regression / step count always 3 / step ids correct / route names correct.

**Integration tests `tests/Integration/Controller/SetupChecklistTest.php`** (~8 cases):
- Dismiss with valid CSRF redirects + sets `setupChecklistDismissedAt` / dismiss with invalid CSRF → 403 / GET dismiss route → 405 / dismissed checklist doesn't render / checklist renders when not dismissed / checklist hidden when fully complete / checklist shows Hide button / checklist shows "X of 3 steps complete".

**Critical details:**
- The `verificationStatus` query reflects only the most-recently-created domain, not all team domains. For multi-domain teams, step 2 completeness might appear "wrong" relative to other domains — but this is the same view the Next Action card uses, so the mental model is consistent. Document as v2 enhancement (full DBAL count of `dmarc_verified_at IS NOT NULL`) once multi-domain DMARC status is exposed broadly.
- CSRF token id `setup_checklist_dismiss`. Field name `_csrf_token` (matches the AddDomainFromQuarantine pattern).
- No flash on dismiss — the absence of the card on redirect is feedback enough.

**Build phases:**
1. Migration + `Team` entity property + mutator. Verify with `phpunit` (existing tests stay green).
2. `SetupChecklistStep` value object + `SetupChecklistResult`.
3. `SetupChecklistResolver` service + unit tests (~14). All green.
4. `DismissSetupChecklistController`.
5. `DashboardOverviewController` modification (inject + compute + pass to template).
6. Template block in `overview.html.twig`.
7. `SetupChecklistTest` integration tests (~8). `phpunit` + `phpstan` + `php-cs-fixer fix --dry-run` all green.

---

## TASK-022: Sender Inventory authorize/revoke buttons are unlabelled, undoable, and have no bulk action — a single mis-click costs you 20 mins of cleanup

- Status: done
- Shipped: 2026-05-23
- Area: domains
- Why: `templates/dashboard/sender_inventory.html.twig` per-row "Authorize" / "Revoke" buttons submit a form with no confirmation, no undo, no batch action, and no audit log entry visible to the user. For a domain with 30+ unique senders (typical for marketing newsletters + transactional + employees) the user has to click 30 individual buttons and has no way to know which ones they already touched if the page reloads or they get distracted. This is also where mis-classification has real consequences — marking Mailchimp "Authorized" then forgetting about it suppresses real failure alerts.
- Acceptance:
  - Checkbox per row + bulk action bar ("Authorize selected", "Mark unknown") that appears when ≥1 row is checked.
  - Each row shows a small "Last changed by Jane on May 22" line under the status badge (sourced from existing `sender_inventory.updated_at` + a new `updated_by_user_id` column). New column means a small migration.
  - The Authorize action gains a confirm modal on first authorize per session: "Authorizing this sender means we'll trust mail it sends as your domain. Real failures from this IP won't trigger alerts. Continue?" (suppressed for subsequent toggles in the same session via `sessionStorage`.)
  - Inline notes field per sender — small textarea opens on a "Note" icon click; persists to a `notes` column on `sender_inventory`. Useful for "this is Mailchimp's marketing IP — Jane set up DKIM on 2026-04-12."
  - 100% test coverage on the bulk-update command + the audit-log column.
- Notes:

### Architect plan (2026-05-23)

**Schema migration `Version20260526000000.php`:** ALTER `known_sender` ADD `updated_at TIMESTAMP NULL`, `notes TEXT NULL`, `updated_by_user_id UUID NULL` (FK to user.id, ON DELETE SET NULL). The `updated_by_user_id` is nullable because existing rows have no historical attribution; future updates fill it in. Index on `(updated_by_user_id)`.

**Entity `KnownSender`:** add 3 properties + 3 mutation methods — `authorize(User $by, \DateTimeImmutable $at)`, `markUnknown(User $by, \DateTimeImmutable $at)`, `setNotes(?string $notes, User $by, \DateTimeImmutable $at)` — each updates the audit fields.

**Commands (modify + 3 new in `src/Message/`):**
- Modify existing `MarkSenderAuthorized` — add `public UuidInterface $actorUserId`.
- New: `BulkAuthorizeSenders(list<UuidInterface> $senderIds, UuidInterface $teamId, UuidInterface $actorUserId)`.
- New: `BulkMarkSendersUnknown(...)`.
- New: `SetSenderNote(UuidInterface $senderId, UuidInterface $teamId, ?string $note, UuidInterface $actorUserId)`.

**Handlers:** Modify `MarkSenderAuthorizedHandler` (inject UserRepository + ClockInterface, call entity methods). New: 3 bulk/note handlers, all `readonly final` with `#[AsMessageHandler]`. Bulk handlers iterate IDs, call `findForTeam` per ID, silently skip nulls (defense-in-depth). No explicit flush — rely on `doctrine_transaction` middleware (matches TASK-015 alert pattern).

**Repository:** add `KnownSenderRepository::findForTeam(UuidInterface $id, UuidInterface $teamId): ?KnownSender`.

**Query:** `GetSenderInventory.php` — extend SQL with `LEFT JOIN "user" u ON u.id = ks.updated_by_user_id` (quoted because PostgreSQL reserved word). Select `ks.updated_at`, `ks.notes`, `u.email AS updated_by_user_email`. `SenderInventoryResult` gains `updatedAt: ?string`, `notes: ?string`, `updatedByUserEmail: ?string`.

**Controllers (4 new + 1 modify):**
- Modify `SenderInventoryController` — strip POST handling (moves to dedicated controllers), GET-only, route param `{id}` → `{domainId}` for clarity.
- New `AuthorizeSenderController` POST `/app/domains/{domainId}/senders/{senderId}/authorize` (CSRF `sender_action`).
- New `RevokeSenderController` POST `/app/domains/{domainId}/senders/{senderId}/revoke` (CSRF `sender_action` — shared with authorize).
- New `BulkSenderActionController` POST `/app/domains/{domainId}/senders/bulk` (CSRF `bulk_sender_action`, `action ∈ {authorize, mark_unknown}`, no-op on empty selection).
- New `UpdateSenderNoteController` POST `/app/domains/{domainId}/senders/{senderId}/note` (CSRF `sender_note`).

All write controllers: `findForTeam` lookup → 404 on null; team scoped via `DashboardContext::getTeamId()`; `getUser()` cast via `assert($user instanceof User)`.

**Stimulus controllers (2 new):**
- `assets/controllers/sender_selection_controller.js` — structural copy of `alert_selection_controller.js` with `senderIds[]` filter (rename of name attribute requires separate controller — alternative is renaming the alert one to a generic `bulk_selection`, but that's churn). Targets `toolbar`+`count`, actions `updateCount`+`clearAll`.
- `assets/controllers/sender_authorize_confirm_controller.js` — attaches to the hidden authorize form via `data-action="submit->sender-authorize-confirm#confirmIfNeeded"`. First-submit-per-session calls `confirm(...)` with the explanatory message and sets `sessionStorage.setItem('senderAuthorizeConfirmed', '1')`. Subsequent submissions skip.

**Template `sender_inventory.html.twig` rewrite (HTML-valid pattern for nested forms):**
- Outer `<form id="bulk-sender-form" method="post" action="dashboard_sender_bulk" data-controller="sender-selection">` wraps the table (checkboxes + bulk toolbar).
- Per-row Authorize/Revoke buttons use HTML5 `form="authorize-form-{id}"` / `form="revoke-form-{id}"` attribute pointing to hidden `<form>` elements rendered AFTER the table (outside the bulk form). Each hidden form has its own CSRF + action URL. This avoids invalid nested `<form>` tags.
- Note button per row opens a daisyUI `<dialog id="note-dialog-{id}">` with a textarea (maxlength=10000) + save form (action `dashboard_sender_note`).
- Authorize button has `data-controller="sender-authorize-confirm"` on its linked hidden form.
- Status column gains audit sub-line: `Last changed by {{ updatedByUserEmail ?? 'system' }} on {{ updatedAt|date('M j, Y') }}` when `updatedAt` non-null.

**Tests (~22 new in `tests/Integration/Controller/SenderInventoryActionsTest.php`):**
- Page renders (checkboxes, toolbar, note buttons present)
- Audit line renders when `updatedAt` set; "system" fallback when `updatedByUser` null (ON DELETE SET NULL case)
- Authorize/Revoke single-row: CSRF rejected, happy path sets `isAuthorized` + `updatedByUser` + `updatedAt`, 404 unknown, 404 cross-tenant
- Bulk: CSRF rejected, authorize happy, mark_unknown happy, empty selection no-op redirect, cross-tenant IDs silently skipped, unknown action 404
- Notes: CSRF rejected, save persists + redirects, empty string normalizes to null, 15000-char string truncates at 10000

Plus message constructor tests and entity audit method tests.

**Critical details:**
- CSRF token IDs: `sender_action` (authorize+revoke shared), `bulk_sender_action`, `sender_note`.
- Bulk handler safety: per-ID `findForTeam` skip-null pattern matches TASK-015 alerts; forged cross-tenant IDs silently dropped.
- `getUser()` narrowed via `assert($user instanceof User)` — PHPStan+symfony understands this.
- Existing route name `dashboard_sender_inventory` parameter `{id}` becomes `{domainId}` — rename throughout (grep `dashboard_sender_inventory` to find callers).
- `sender_authorize_confirm` controller attaches to the hidden form, not the button, so `event.preventDefault()` on `submit` reliably blocks. The linked button via `form=` attribute triggers a form `submit` event.
- Default empty-string note normalized to null in handler.
- Note max length 10000 chars; handler truncates if exceeded (defense — also maxlength on textarea).

**Build phases:** 1) Migration + entity + entity tests. 2) Commands + repository + message tests. 3) Handlers. 4) Query + DTO + result tests. 5) Controllers + route registration. 6) 2 Stimulus controllers + template rewrite + browser smoke. 7) Integration tests; phpunit + phpstan + cs-fixer green; commit + push.


---

## RUN SUMMARY — 2026-05-23 autonomous CX/feature loop

### Shipped (14 tasks)

| # | Task | Commit | Area | Headline change |
|---|---|---|---|---|
| 001 | DNS Health in-app nav | `3220a5f` + `d010bb1` | dashboard | Sidebar DNS Health stops bouncing to public tool; in-app per-domain overview with SPF/DKIM/DMARC/MX badges |
| 002 | Dashboard guidance | `d9c0962` + `24e6c8e` | dashboard | Health summary banner + Next-Action card at top of `/app`, picks single highest-value step; empty-state hides zero-value widgets |
| 003 | Homepage 5-second test | `4e53b7d` + `fdadad1` | marketing | Category-explicit kicker, named DMARC/DNS/AI capabilities in subhead, primary CTA flipped to Get-started-free, trust badges, mislabeled "View on GitHub" replaced |
| 004 | Logo bar | `7882623` | marketing | "Trusted by founder's own companies" reframed positively; companies linked to live sites |
| 005 | Pricing depth | `f123e1d` | marketing | Comparison table + 10-Q FAQ + annual-savings callout + final CTA + corrected meta description |
| 009 | Trust pages | `0142506` + `9d9df9f` | marketing | New /legal/privacy + /legal/security + /status; footer Trust column; **Halite/XChaCha20-Poly1305 truth fix** on homepage (was "AES-256-GCM" — false claim) |
| 012 | Retire /beta | `f1d34b3` | marketing | Free-tier CTA → auth_login; /beta → 301 to home; KB lazy turbo-frame embeds replaced; 5 tool-page "Join the beta" instances rewritten |
| 013 | Domain detail badges | `a8fcac3` + `24e6c8e` | domains | At-a-glance SPF/DKIM/DMARC/MX badges in header, deep-link to per-health anchors |
| 015 | Alerts snooze/mute/bulk | `07a5975` | dashboard | snoozed_until column + muted_alert table; AlertEngine.createAlert single mute-check seam; per-row checkboxes + bulk toolbar; copy-link button; "Muted Alert Types" section on preferences |
| 016 | Reports filters + search | `56b575e` | reports | URL-driven filter bar (domain/reporter/pass-rate band/date range/search); Turbo-Drive advance; unified GetDomainReports into GetAllReports |
| 017 | Records grouping | `51c0612` + `00d9113` | reports | By-sender grouped view with DKIM/SPF pass-rate + disposition + KnownSender auth badge; raw records moved behind details toggle |
| 018 | Dashboard a11y | `add6655` | dashboard | All 4 `<tr onclick>` patterns replaced with stretched-link anchors (middle-click + keyboard + screen-reader safe); regression test guards against re-introduction |
| 019 | Billing usage panel | `c39edcc` | dashboard | Monthly Reports panel + retention nudge + PlanOverage warning on /billing; conditional 6th stat card on overview at ≥50% usage |
| 022 | Sender Inventory bulk + audit + notes | `7fa8acb` | domains | Bulk authorize/mark-unknown + per-row audit ("Last changed by X on Y") + inline notes textarea + first-authorize-per-session confirm |

**Suite at run end:** 1422 tests, 3709 assertions, all green. PHPStan clean. PHP-CS-Fixer clean. ~95 new test files + ~150 new test methods across the run.

### Not shipped (8 tasks remaining)

- **TASK-006** — Tool-result micro-conversion (soft email-me form on /tools/*)
- **TASK-007** — KB content depth (need ~5+ new long-form articles; copy-writing-heavy)
- **TASK-008** — Per-page OG images (touches every public controller)
- **TASK-010** — "What is Sendvery" page polish (visuals + screenshot + conversion path)
- **TASK-011** — `/open-source` page (60-second quickstart + comparison table + GitHub stats; gated repo-link until repo is public per `docs/03-features-roadmap.md` Phase 2)
- **TASK-014** — Mailbox setup wizard (large: provider presets + synchronous IMAP `TestMailboxConnection` service + new sync test endpoint)
- **TASK-020** — Quarantine visibility (new `/app/quarantine` route + reprocess UI + UnknownDomain → add-domain pre-fill flow)
- **TASK-021** — Onboarding checklist (overview surface — touches TASK-002's NextActionResolver / overview.html.twig; needs careful integration)

### Blocked: 0

No task was blocked. Every architect → developer → reviewer cycle landed cleanly. Reviewer rounds caught and fixed 4 real defects during the run:
- TASK-001: `domain_health.html.twig` orphaned "Run a DNS health check" copy + test only covered DNS Health page → expanded to cover domain detail + per-domain health.
- TASK-002: Missing multi-domain `ConnectMailbox` suppression test (the "ALL domains have zero reports" invariant was untested).
- TASK-009: `#faq` anchor missing from `/pricing` (footer Refund Policy link landed at page top); `TrustPagesTest` used bare Symfony `WebTestCase` instead of project's `App\Tests\WebTestCase`.
- TASK-013: Cross-tenant security test missing on the new `GetDnsHealthOverview::forDomain()`; `id="health-trend"` anchor unverified by tests; "Run a DNS health check" copy was orphaned.

### Suggested next moves (priority ordered)

1. **TASK-011 (/open-source)** — medium scope, high marketing differentiator. Watch for the "repo not public yet" caveat in `docs/03-features-roadmap.md` Phase 2; gate the GitHub link behind a config flag or just confirm the repo is now public before linking. Quickstart + comparison table + Why-AGPL expansion fit a single PR.
2. **TASK-020 (Quarantine visibility)** — high-value for paying customers (visible "data you paid for that's stuck"). New route + new query + reprocess action. Builds on existing `QuarantinedDmarcReport` infrastructure already referenced from `overview.html.twig` (unverified-domain banner) and `domain_detail.html.twig` (count badge).
3. **TASK-014 (Mailbox setup wizard)** — biggest single drop-off risk per the dashboard Product agent's analysis. Largest remaining task — provider presets + synchronous IMAP connection test service. Pays dividends on every non-developer signup.
4. **TASK-021 (Onboarding checklist)** — dismissible setup checklist back on `overview.html.twig`. Must coordinate with TASK-002's `isEmptyState` guard (the empty-state branch hides the checklist trivially; the medium-state needs the checklist *and* the next-action card to coexist). Medium scope.
5. **TASK-008 (Per-page OG images)** — distributed scope (touches many controllers). Moderate SEO/social distribution lift. Can be parallelised across many tool pages.
6. **TASK-007 (KB content)** — copy-writing-heavy. Best done by a human with product context + SEO target keywords from `docs/00-project-overview.md`'s GTM thesis. AI-drafted articles risk shallow content that competitors already rank for.
7. **TASK-006 (Tool soft conversion)** — tied to retired-but-not-deleted `BetaSignup` infrastructure (TASK-012 left the entity in place). Decide whether to repurpose that as a generic "email me updates" capture or build new lightweight subscriber infra.
8. **TASK-010 (What is Sendvery polish)** — medium scope, mostly visual; needs product screenshots to make impactful.

### Architectural notes for future work

- **`AlertEngine::createAlert()` is the single chokepoint for all alert emission.** TASK-015 used this seam for mute checks; future per-team alert preferences (e.g. "only critical via email, all in app") should also hook in here.
- **`ReportsFilter` value object pattern (TASK-016)** is the template for URL-driven dashboard filters. Apply the same shape if/when filtering is added to alerts (`AlertsFilter`) or domains (`DomainsFilter`).
- **Stretched-link pattern (TASK-018)** is the canonical row-navigation idiom. Future tables MUST follow it — the `noOnclickInAnyDashboardPage` regression test will fail loudly if anyone reintroduces `<tr onclick>`.
- **Bulk action pattern (TASK-015 + TASK-022)** is consistent: outer `<form data-controller="*-selection">`, per-row `name="ids[]"` checkboxes, sticky toolbar via Stimulus targets. The two `*_selection_controller.js` files are nearly identical and could be unified into a generic `bulk_selection_controller.js` taking the input name as a Stimulus value attribute. Refactor opportunity once a third instance lands.
- **Halite vs AES-256-GCM correction (TASK-009)**: the actual encryption library is paragonie/halite (XChaCha20-Poly1305 via libsodium). The homepage previously claimed AES-256-GCM in three places — all fixed. New security copy must use the Halite description; `TrustPagesTest::testSecurityPageContainsEncryptionClaim` is the regression guard.

---

## RUN SUMMARY — 2026-05-23 second autonomous CX loop

### Shipped (8 tasks, finishing the backlog)

| # | Task | Commit | Area | Headline change |
|---|---|---|---|---|
| 020 | Quarantine visibility | `820dc8d` | dashboard | New `/app/quarantine` route + reprocess + add-domain-and-release flow; sidebar entry with badge-warning count; team-scoped via monitored-domain ownership OR receiving mailbox connection (covers the UnknownDomain-via-own-mailbox case the architect originally missed) |
| 021 | Setup checklist | `469037e` | onboarding | Persistent dismissible 3-step checklist above the Next Action card on `/app`; team-scoped `setup_checklist_dismissed_at` column; auto-resurface on DMARC regression via in-memory override (no extra DB writes on the DNS-check hot path) |
| 011 | /open-source polish | `bb41766` | marketing | 7-section rewrite: hero with dual CTA, GitHub stats strip (cached JSON from new `sendvery:opensource:refresh-github-stats` cron), 60-second quickstart with copy-to-clipboard, comparison table, expanded Why-AGPL, repo tour. Gated "View on GitHub" via `SENDVERY_REPO_PUBLIC` env (disabled "Coming soon" button until flipped) |
| 014 | Mailbox setup wizard | `f99a103` | onboarding | Provider preset dropdown (Gmail / Outlook / Fastmail / Yahoo / Seznam / Custom) + Gmail/Outlook app-password banner + synchronous `MailboxConnectionTester::test()` (3s timeout via Webklex) running BEFORE persist — inline classified error on failure, no row created. Per-row "Re-test" action on mailboxes list. `ConnectMailboxHandler` no longer double-tests (controller owns the gate). Tightened `auth` substring matcher (no more false positives on `OAUTH` / `AUTH=PLAIN`). Credentials never leak via re-test flash |
| 008 | Per-page OG images | `e1a84e8` | marketing | New `/og/{type}/{slug}` route — PHP GD painter, 1200×630 PNG, immutable 30-day cache. Three card variants: tool (8 pages), kb (3 articles, now 7 after TASK-007), health (`/health/{hash}` shares). Inter Bold/Regular TTF committed (OFL-1.1). Logo PNG optional with wordmark text fallback so feature shipped without a design asset |
| 010 | /what-is-sendvery polish | `95b6ef8` | marketing | Wall-of-text → 8-section product manifesto: hero with dual CTA, problem statement, daisyUI dashboard mock as product preview (no real screenshot needed), 3 persona cards (Developer / Small Business / Agency), 4-card comparison vs MXToolbox/dmarcian/PowerDMARC/Sendvery (Sendvery highlighted), "Built in the open" strip reading TASK-011's github_stats, founder blockquote, final CTA strip. 4 distinct CTAs total |
| 006 | Tool soft-conversion | `1c577f2` | marketing | New Turbo-frame `<twig:MonitorEmailMeMicro />` under every tool result — single email field, creates `BetaSignup` with source tagging (`spf-result`, `dkim-result`, …) for per-tool conversion analytics. Existing `SendBetaConfirmationEmail` listener picks it up automatically. Migration moved BetaSignup unique constraint from `email` to `(email, source)` for multi-source captures. Idempotent on re-submit |
| 007 | KB content depth | `82e19c5` | marketing | Knowledge base 3 → 7 articles: DKIM explainer, Gmail/Yahoo 2024 sender requirements, p=none→p=reject migration, MX records (~1700-1950 words each). KB index grid + article cross-link grid moved to auto-fill minmax columns so categories with any article count look balanced. Sitemap + OG-image generator pick up the new slugs automatically |

**Suite at run end:** 1666 tests, 4448 assertions, all green. PHPStan clean. PHP-CS-Fixer clean. ~24 new test files + ~150 new test methods + ~6800 words of new KB copy across the run.

### Backlog now empty

All 22 tasks from the previous run's identification (TASK-001 through TASK-022) are shipped. No proposed or planned tasks remain. The orchestrator stopped here per the brief's stop-condition: "Backlog has zero `proposed` or `planned` tasks."

### Blocked: 0

Every architect → developer → reviewer cycle landed cleanly. Reviewer rounds caught and fixed 8 real issues in this run:
- TASK-020: `UnknownDomain` quarantine rows for unowned domains were invisible to all teams (architect plan flaw — needed mailbox-scoping branch in the WHERE).
- TASK-020: `EM::clear()` inside the doctrine_transaction-wrapped reprocess handler could detach unrelated entities → captured envelope id as primitive string + detached only the receiving proxy.
- TASK-021: Architect's note about CSRF field name `_token` was wrong — code correctly used `_csrf_token`; doc fixed for future reference.
- TASK-014: `FakeMailClient::testConnection` didn't propagate `errorCode` → re-test classified-message branch was dead code → added optional `errorCode` to `simulateFailure()`.
- TASK-014: `classifyError` matched bare `auth` substring → false positive on `OAUTH`, `AUTH=PLAIN` capability strings → tightened to `authentication / login failed / credentials / auth failed / invalid login`.
- TASK-014: Re-test flash leaked raw `$result->error` which can contain bound usernames or credential fragments from IMAP server responses → dropped the raw detail, log-only.
- TASK-008: `HealthOgImageContentResolver` made two sequential DB queries without logging the second's null-fallback → added LoggerInterface dependency with explicit warning on data-integrity escape.
- TASK-008: `GdOgImagePainterTest` tearDown leaked nested dirs from the `createsTargetDirectoryIfMissing` test → switched to recursive cleanup via RecursiveIteratorIterator.

### Deferred for follow-up (NOT in scope for this run)

- **Real product screenshot for `/what-is-sendvery`**. Section 3 uses a daisyUI HTML mock with an "Illustrative" caption. Replace with a real dashboard screenshot when one is available — no code change needed beyond swapping the `<div>` mock for an `<img>`.
- **Real founder photo on `/what-is-sendvery`**. Section 7 uses an initials avatar placeholder. Swap for a real photo whenever one exists.
- **Real OG brand logo**. `GdOgImagePainter` falls back to a "Sendvery" wordmark text mark when `assets/images/og-logo.png` is absent. Drop a 240×60 transparent PNG there to upgrade every OG card to a real logo with zero code change.
- **System cron for GitHub stats refresh**. The `sendvery:opensource:refresh-github-stats` command is shipped but the actual cron line lives in `~/www/spare.srv/deployment/crontab` (outside the repo). Add `0 */6 * * * sentry-cli monitors run sendvery-github-stats -- docker compose run --rm worker bin/console sendvery:opensource:refresh-github-stats` next deploy. Until then the stats strip on `/open-source` silently omits itself (by design — never renders fake numbers).
- **KB article: app passwords for Gmail / Outlook**. Referenced as a placeholder `href="#"` in the TASK-014 mailbox-wizard's Gmail/Outlook app-password banner. Write the article when convenient and update the link.
- **Bulk-selection Stimulus controller unification**. `alert_selection_controller.js` (TASK-015), `sender_selection_controller.js` (TASK-022) are nearly identical and now joined by patterns in TASK-020. Consider a generic `bulk_selection_controller.js` taking the input name as a Stimulus value attribute once a fourth instance lands.
- **OG image layout-drift detection**. Reviewer asked for a SHA-256 pinned-output test; declined because GD/libgd minor version drift produces false positives. If layout regressions become a real problem, consider a visual-diff tool (e.g. screenshot comparison via percy/chromatic) rather than byte-equality.

### Architectural notes added this run

- **`SetupChecklistResolver` (TASK-021)** is a pure-computation service mirroring `NextActionResolver`. Both take pre-fetched inputs from the overview controller, no DB access. New dashboard widgets that synthesise multiple signals should follow this pattern — keeps unit tests cheap and the controller as the single composition point.
- **`MailboxConnectionTester` interface (TASK-014)** is the seam for pre-persist connection tests with plaintext credentials. Separate from `MailClient::testConnection(MailboxConnection)` which decrypts a persisted entity. Future mailbox-style integrations (e.g. OAuth2 token tests, SMTP send tests) should split the same way.
- **`OgImageRenderer::SCHEMA_VERSION` (TASK-008)** is the cache-invalidation switch. Bump from `'v1'` → `'v2'` whenever the painter's layout changes — every md5 cache key changes, forces regeneration on first request. Old files become orphaned in `var/og_cache/`; `rm -rf var/og_cache/` on deploy is acceptable since regeneration is fast and CDNs cache aggressively.
- **`var/{github_stats,og_cache}/` cache-directory pattern (TASK-011 + TASK-008)** — Twig globals exposed via `GlobalsInterface` extensions read these on each request, with try/catch wrappers returning safe defaults for unauthenticated / pre-cron contexts. Mirror this pattern for any future on-disk read-mostly cache.
- **`BetaSignup` source taxonomy (TASK-006)** — the unique constraint moved from `(email)` to `(email, source)`. Future capture forms (e.g. a "notify me when self-host is published" form) can land another `source` value without colliding with existing rows. Per-source analytics queries become trivial.
- **KB index grid auto-fill pattern (TASK-007)** — `[grid-template-columns:repeat(auto-fill,minmax(min(100%,22rem),1fr))]` handles any article count gracefully. Apply to any other "grid of cards where the row may have 1-many entries" surface.

---

## TASK-023: Homepage testimonials section — placeholder-but-credible social proof, swap-in via single config file

- Status: done
- Area: marketing
- Why: The homepage has zero testimonials. Every visitor in the 5-second scan sees feature claims and price tiers but no human saying "this worked for me." For a category (DMARC/email-auth) where buyers are deeply sceptical of yet-another-tool, the missing testimonials section is the single largest trust gap. Sendvery is pre-launch so real quotes don't exist yet — but a polished placeholder section, marked with a one-grep swap convention, lets the design land now and the words drop in at launch with a 10-minute edit.
- Acceptance:
  - New `templates/components/TestimonialsSection.html.twig` Twig component rendering a 3-card responsive grid (1 col mobile, 2 col tablet, 3 col desktop) — quote, attribution (name + role + company), initials-avatar with brand-tinted background (no fake photo URLs). Optional small "logos strip" row below the cards with company name marks rendered as text (no fake SVG logos).
  - Component data lives in **one** file `config/placeholders.php` (new), returning a `list<array{quote: string, name: string, role: string, company: string, initials: string}>`. Loaded into Twig via a new `PlaceholdersExtension` that exposes a `testimonials` global. **Every entry** carries an inline `// TODO(placeholder): replace before launch` comment on the same line as the array key opening. The file ALSO carries a top-of-file `// TODO(placeholder): see docs/cx-improvement-backlog.md TASK-023 — entire file is launch-swap content` banner.
  - Six placeholder testimonials, diverse plausible names and roles: e.g. *"Maya Hernandez, Head of Deliverability, Lattice Mail"* / *"Tomáš Novák, Platform Engineer, Forkbox"* / *"Priya Iyer, IT Director, Northwind Logistics"* / *"David Okafor, DevOps Lead, RouteSignal"* / *"Anna Lindqvist, CTO, Klippa Studio"* / *"Marco Bianchi, SRE, Telio Cloud"*. Quotes must sound like real ops/deliverability practitioners — concrete artefacts ("p=none for 14 months until Sendvery flagged the missing DKIM selector"), not marketing fluff ("game-changer", "best in class", "10x"). Render only 3 in the section; the other 3 are the bench for swap-in.
  - Renders as the new section 8.5 on the homepage — between the "Domain Health Score Preview" (current section 8) and the "Open Source Callout" (current section 9). Eyebrow label, H2, no kicker emoji.
  - Single hard rule documented at the top of `config/placeholders.php`: `grep "TODO(placeholder)" config/placeholders.php` returns ≥ 1 hit per fake item, and a `tests/Unit/Config/PlaceholdersConventionTest.php` test asserts this so a future swap that removes the marker on only some entries fails CI.
  - Integration test asserts the section renders, contains the H2 heading, and renders all visible placeholder names.
- Notes:
  **Architect plan (locked in)**

  Insertion point confirmed: `templates/homepage/index.html.twig` between section 8 close (`</twig:SectionContainer>`) and section 9 comment (`{# === 9. Open Source Callout === #}`). Extension model to mirror: `src/Twig/GithubStatsExtension.php` (`final class ... extends AbstractExtension implements GlobalsInterface`, `#[Autowire('%kernel.project_dir%')]`). Autowiring under `src/` is automatic; the `when@test` block in `config/services.php` (~line 406) needs a `public: true` entry for `App\Twig\PlaceholdersExtension`. Integration tests live in `tests/Integration/Controller/` (no `Marketing/` subdir). Unit test home: `tests/Unit/Config/`. Base class: `App\Tests\WebTestCase`.

  **Files to create**

  1. `config/placeholders.php` — plain PHP `return [...]` array (no namespace). Top-of-file banner: `// TODO(placeholder): see docs/cx-improvement-backlog.md TASK-023 — entire file is launch-swap content. Swap convention: every fake entry carries an inline "// TODO(placeholder): replace before launch" comment...`. Three top-level keys:
     - `founder_photo => null` — line-end marker `// TODO(placeholder): replace with real photo URL or asset path before launch (TASK-024)`.
     - `linkedin_url => null` — line-end marker (same convention, TASK-024).
     - `testimonials => [ 6 entries ]` — each opening bracket has line-end marker `// TODO(placeholder): replace before launch` (bench entries 3-5 carry the same marker plus " — bench entry, not rendered in the default 3-card grid").
     Concrete-artefact quotes (no marketing fluff) — see the architect output for the exact 6 quotes; names: Maya Hernandez (Lattice Mail, MH), Tomáš Novák (Forkbox, TN), Priya Iyer (Northwind Logistics, PI) — visible; David Okafor (RouteSignal, DO), Anna Lindqvist (Klippa Studio, AL), Marco Bianchi (Telio Cloud, MB) — bench. Each entry shape: `{quote, name, role, company, initials}`.

  2. `src/Twig/PlaceholdersExtension.php` — `final class PlaceholdersExtension extends AbstractExtension implements GlobalsInterface`. Constructor takes `#[Autowire('%kernel.project_dir%')] private readonly string $projectDir`. `getGlobals()` returns `['testimonials' => ..., 'founder_photo' => ..., 'linkedin_url' => ...]` — explicit key enumeration (no array spreading) to prevent accidental global namespace leakage. Private `loadPlaceholders()` does `require $this->projectDir.'/config/placeholders.php'` then `array_merge` with defaults `['founder_photo' => null, 'linkedin_url' => null, 'testimonials' => []]`. Class is `final` (not `readonly final` because `AbstractExtension` is not readonly-compatible). PHPStan docblock on `loadPlaceholders()`: `@return array{testimonials: list<array{quote: string, name: string, role: string, company: string, initials: string}>, founder_photo: string|null, linkedin_url: string|null}`.

  3. `templates/components/TestimonialsSection.html.twig` — raw `<section id="testimonials" class="py-20 lg:py-24">` (NOT wrapped in `<twig:SectionContainer>` — this component IS the section). Eyebrow `<div class="text-xs font-semibold text-primary uppercase tracking-wider mb-3">What practitioners say</div>` + H2 `text-2xl md:text-3xl font-bold` reading "Trusted by the people who actually read DMARC reports". Grid `grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-5xl mx-auto`. `{% for entry in testimonials|slice(0, 3) %}` — hard-cuts at 3 even if config has more. Card: `card bg-base-100 border border-base-300` → `card-body` → quote `<p class="text-base-content/80 text-sm leading-relaxed flex-1">&ldquo;{{ entry.quote }}&rdquo;</p>` → attribution row with `avatar avatar-placeholder` (inner `bg-primary/10 text-primary w-10 rounded-full` — lighter tint than the /what-is-sendvery founder avatar to differentiate) showing initials, then `<div class="font-semibold text-sm">{{ entry.name }}</div>` and `<div class="text-xs text-base-content/50">{{ entry.role }} &middot; <span class="font-medium text-base-content/60">{{ entry.company }}</span></div>`. Company name as inline text in the attribution row fulfills "company name marks rendered as text (no fake SVG logos)" — no separate logos row. Heading classes match the rest of the homepage so TASK-026 can mechanically substitute when it lands.

  4. `tests/Unit/Config/PlaceholdersConventionTest.php` — extends `PHPUnit\Framework\TestCase` (NOT WebTestCase — pure file read). `setUpBeforeClass` loads `dirname(__DIR__, 3).'/config/placeholders.php'` both as `require` and via `file_get_contents`. Tests: `configFileExists`, `topOfFileBannerIsPresent` (asserts substring `'TODO(placeholder): see docs/cx-improvement-backlog.md TASK-023'`), `everyTestimonialEntryHasPlaceholderMarker` (asserts `substr_count(source, '// TODO(placeholder): replace before launch') >= count(testimonials)`), `testimonialListHasSixEntries`, `founderPhotoKeyIsReserved`, `linkedinUrlKeyIsReserved`, `eachTestimonialEntryHasAllRequiredKeys` (loops 5 required keys).

  5. `tests/Integration/Controller/HomepageTestimonialsTest.php` — extends `App\Tests\WebTestCase`. Tests: `homepageWith200Response`, `testimonialsSectionH2IsPresent` (CSS selector `#testimonials h2`), `firstThreeTestimonialNamesAreVisible` (Maya Hernandez / Tomáš Novák / Priya Iyer in body text), `benchTestimonialNamesAreNotRendered` (David Okafor / Anna Lindqvist / Marco Bianchi absent — proves `|slice(0, 3)` cut), `testimonialsSectionContainsThreeCards` (`crawler->filter('#testimonials .card')->count() === 3`), `initialsAvatarsAreRendered` (MH / TN / PI substrings in `#testimonials` text).

  **Files to modify**

  - `templates/homepage/index.html.twig` — insert between section 8 close and section 9 comment:
    ```diff
         </twig:SectionContainer>

    +{# === 8.5. Testimonials === #}
    +<twig:TestimonialsSection />
    +
     {# === 9. Open Source Callout === #}
    ```
  - `config/services.php` — `when@test` block, after the `GithubStatsExtension` entry: add `'App\Twig\PlaceholdersExtension' => ['public' => true],`.

  **Data flow**: `config/placeholders.php` (required once per worker on extension construction) → `PlaceholdersExtension::loadPlaceholders()` → `array_merge` with defaults → `getGlobals()` explicit enumeration → Twig global `testimonials` → `templates/homepage/index.html.twig` calls `<twig:TestimonialsSection />` → component template iterates `testimonials|slice(0, 3)`.

  **Non-goals (out of scope for this task — DO NOT do them here)**: No `SectionHeader` component extraction (TASK-026). No founder-bio code paths (TASK-024 — but the `founder_photo` / `linkedin_url` keys are reserved in `placeholders.php` now). No logos-strip row of fake SVG company marks. No real photo URLs anywhere.

  **Build sequence**: create `placeholders.php` → create `PlaceholdersExtension.php` → edit `services.php` (`when@test` registration) → create `TestimonialsSection.html.twig` → edit `homepage/index.html.twig` (3-line insertion) → create `PlaceholdersConventionTest.php` → create `HomepageTestimonialsTest.php` → run phpunit / phpstan / php-cs-fixer.

---

## TASK-024: Homepage founder bio section — first-person legitimacy, distinct from /what-is-sendvery treatment

- Status: done
- Area: marketing
- Why: The owner explicitly named this as a missing surface. `/what-is-sendvery` already has a founder blockquote (initials avatar, italic pull-quote, GitHub link) — the homepage needs a separately-designed founder section so a first-time visitor (who never clicks through to `/what-is-sendvery`) still gets the "this is built by a human you can name" signal. Distinct visual treatment ensures the two surfaces reinforce rather than duplicate.
- Acceptance:
  - New `templates/components/FounderBio.html.twig` Twig component. **Different visual language** from the `/what-is-sendvery` blockquote: a 2-column row on desktop (avatar left, ~140px circular; bio text right, max-w-2xl), collapsing to centred single column on mobile. NOT an italic pull-quote — a 3-paragraph short-form bio in normal weight: paragraph 1 = who I am + what I do, paragraph 2 = why I built Sendvery (deliverability pain story, ~2 sentences), paragraph 3 = how to reach me / what to expect (one-person company, replies within 24h, GitHub/email links).
  - Avatar uses the same initials-placeholder pattern as `/what-is-sendvery` (`avatar avatar-placeholder` daisyUI v5 component, primary-tinted, initials `JM`, larger size — `w-32 h-32` vs the `w-12` blockquote variant) with an `// TODO(placeholder): replace JM avatar with real photo before launch` comment on the same line as the placeholder block in the component template. **Crucially, the photo URL is sourced from `config/placeholders.php` (TASK-023's file) as a single `founder_photo` key**, currently `null`. When non-null, the component renders an `<img>`; when null, the initials placeholder. One grep, one swap.
  - Below the bio: a small inline row with 3 chips/links — GitHub profile, "Email me directly" mailto to `jan.mikes@sendvery.com`, optional LinkedIn (gated on `linkedin_url` in the placeholders config — `null` by default, the chip hides).
  - Section lives on the homepage at a new slot: **between** the testimonials section (TASK-023) and the "Open Source Callout". The flow becomes: social proof from others → human face behind it → "yes you can self-host the whole thing." Heading: "Built by one person, in the open" (or similar — not "Meet the founder" which reads marketing-deck).
  - daisyUI v5 only, no `dark:` prefix. Mobile renders without horizontal overflow at 360px viewport.
  - Integration test asserts the section renders on `/`, contains "Jan Mikeš", contains the GitHub link, and that the LinkedIn chip is absent when the config value is null.
- Notes:
  **Architect plan (locked in, 2026-05-24)**

  TASK-023 (commit `9a5a5b3`) already wired the infrastructure: `config/placeholders.php` reserves `founder_photo => null` and `linkedin_url => null` with TASK-024-tagged markers, and `PlaceholdersExtension::getGlobals()` already exposes both as Twig globals. TASK-024 is pure-template — zero PHP changes, no new service, no controller change.

  **Differentiation checklist vs `/what-is-sendvery` blockquote** (templates/about/what-is-sendvery.html.twig lines 275–298): no `<blockquote>`, no `text-5xl` quotation-mark glyph, no `italic` class, no `w-12` avatar, no `.card` wrapper. All four must be absent.

  **Insertion point**: `templates/homepage/index.html.twig` between `<twig:TestimonialsSection />` (currently line ~237) and `{# === 9. Open Source Callout === #}` (currently line ~239). Insert:
  ```
  {# === 8.7. Founder bio === #}
  <twig:FounderBio />
  ```

  **Files to create**

  1. `templates/components/FounderBio.html.twig` — raw `<section id="founder" class="py-20 lg:py-24">` (NOT `<twig:SectionContainer>` — matches `TestimonialsSection.html.twig` pattern for consistent vertical rhythm). Inside `<div class="container mx-auto">`:
     - Centred section header: eyebrow `text-xs font-semibold text-primary uppercase tracking-wider mb-3 text-center` reading "The team", H2 `text-2xl md:text-3xl font-bold mb-10 text-center` reading "Built by one person, in the open".
     - Content row: `<div class="flex flex-col md:flex-row items-center md:items-start gap-8 md:gap-12 max-w-4xl mx-auto">`.
     - Avatar block left (`<div class="flex flex-col items-center flex-shrink-0">`): `{% if founder_photo %}` renders `<img src="{{ founder_photo }}" alt="Jan Mikeš" class="w-32 h-32 md:w-40 md:h-40 rounded-full object-cover shadow-md">`; `{% else %}` renders daisyUI placeholder avatar (preceded by inline Twig comment `{# TODO(placeholder): swap to <img> when config/placeholders.php has founder_photo set #}`):
       ```twig
       <div class="avatar avatar-placeholder">
           <div class="bg-primary text-primary-content w-32 md:w-40 rounded-full">
               <span class="text-3xl font-semibold">JM</span>
           </div>
       </div>
       ```
     - Bio block right (`<div class="flex-1 max-w-2xl">`): three `<p>` paragraphs inside `<div class="space-y-4 text-base-content/80 leading-relaxed">`:
       - **P1 (who + what)**: "I'm Jan Mikeš. I've spent over ten years building developer-facing infrastructure — most recently as a freelance PHP and Symfony consultant for small Czech product companies. I'm the kind of engineer who reads the RFC before filing the bug."
       - **P2 (why)**: "I built Sendvery because every team I've ever worked with hit the same DMARC wall: nobody on the team can read the XML attachments that land in the reports inbox, nobody has time to learn, and the existing tools cost more per month than the email infrastructure they're supposed to be monitoring. That felt fixable."
       - **P3 (what to expect)**: "Sendvery is a one-person company — when you email support, you reach me directly. I aim to reply within 24 hours on EU business days. The code is on GitHub; issues and feature requests are triaged in public. If you find a bug, open an issue; I'll fix it."
     - Chip row `<div class="mt-6 flex flex-wrap gap-3 items-center">`:
       - GitHub: `<a href="https://github.com/janmikes" target="_blank" rel="noopener" class="btn btn-sm btn-ghost gap-2">` + inline SVG octocat (currentColor, `w-4 h-4`) + "GitHub".
       - Email: `<a href="mailto:jan.mikes@sendvery.com" class="btn btn-sm btn-ghost gap-2">` + inline SVG envelope (currentColor, `w-4 h-4`) + "Email me". Address confirmed canonical from `templates/about/pricing.html.twig` and `templates/components/PricingFaq.html.twig`.
       - LinkedIn: wrapped in `{% if linkedin_url %} ... {% endif %}` — load-bearing for the test. Currently absent because the global is null.

     **Absent by design**: no `<blockquote>`, no `italic`, no `text-5xl` quote glyph, no `w-12` avatar.

  2. `tests/Integration/Controller/HomepageFounderBioTest.php` extending `App\Tests\WebTestCase`. Nine `#[Test]` methods, all on `GET /`:
     - `homepageReturns200`
     - `founderSectionExists` (`#founder` count === 1)
     - `founderSectionH2IsPresent` (`#founder h2` contains "Built by one person")
     - `founderNameIsPresent` (`#founder` text contains "Jan Mikeš")
     - `githubLinkIsRendered` (`#founder a[href*="github.com"]` count ≥ 1)
     - `emailLinkIsRendered` (`#founder a[href^="mailto:"]` count ≥ 1)
     - `linkedinChipIsAbsentWhenConfigIsNull` (`#founder a[href*="linkedin.com"]` count === 0 — relies on live `linkedin_url => null`)
     - `initialsPlaceholderAvatarIsRenderedWhenFounderPhotoIsNull` (`#founder .avatar-placeholder` count === 1 AND `#founder img` count === 0)
     - `bioParagraphCountIsThree` (`#founder p` count === 3; chip row uses `<a>`, not `<p>`)

     File-top comment (not docblock): `// Mobile overflow at 360px viewport cannot be asserted here — verify manually after implementation.`

     No mocking — tests exercise the live wiring against the live config. When real values land, two test names + assertions update in the same PR.

  **Files to modify**

  - `templates/homepage/index.html.twig` — 3-line insertion only:
    ```diff
     <twig:TestimonialsSection />

    +{# === 8.7. Founder bio === #}
    +<twig:FounderBio />
    +
     {# === 9. Open Source Callout === #}
    ```

  **Data flow**: `config/placeholders.php` (`founder_photo: null`, `linkedin_url: null`) → `PlaceholdersExtension::getGlobals()` → Twig globals → `FounderBio.html.twig` `{% if founder_photo %}` false / `{% if linkedin_url %}` false → rendered HTML has `#founder .avatar-placeholder`, no `#founder img`, no LinkedIn `<a>` → tests pass.

  **Non-goals**: no new keys in `placeholders.php`, no `PlaceholdersExtension` changes, no `SectionHeader` extraction (TASK-026), no `<blockquote>` / `italic` / `text-5xl` quote-glyph / `w-12` avatar / `.card` wrapper, no logo / company strip on this section.

  **Build sequence**: verify `placeholders.php` still has both null keys → create `FounderBio.html.twig` → insert 3-line block in homepage → create integration test → phpunit + phpstan + php-cs-fixer (with `--allow-risky=yes`) → browser smoke at 1280px desktop + 360px mobile (DevTools).

---

## TASK-025: Homepage GitHub-stats trust strip — wire the existing data into the hero credibility row

- Status: done
- Area: marketing
- Why: TASK-011 wired up the `github_stats` Twig global (cached JSON refreshed by cron). `/what-is-sendvery` and `/open-source` both read it. The homepage — the highest-traffic surface — does NOT. The hero's "trust badges" row right now is three text spans (Open source, 1 domain free, Self-hostable) plus a "See the source" link, none of which are dynamic. The moment the cron runs and stars exist, the homepage should say so. Owner explicitly named this: "GitHub stats are wired (cached JSON, may be empty until cron runs)" — proposing a surface for it.
- Acceptance:
  - In `templates/homepage/index.html.twig`, the hero trust-badges row (lines 56-76) gains a fourth inline span (between "Self-hostable" and "See the source"): `{% if github_stats is not null %}<span>{{ github_stats.stars|number_format }} ★ on GitHub · last commit {{ github_stats.lastCommitAt|date('M j') }}</span>{% endif %}`. When `github_stats` is null (no cron has run / cron failed / repo not public yet) the span is silently omitted — no fake numbers, no "10k+ developers" placeholder. Same null-fallback pattern as `/what-is-sendvery`.
  - The existing "Star on GitHub" button in section 9 ("Open Source Callout") is augmented when stats are present: button label becomes `Star on GitHub ({{ github_stats.stars|number_format }})`. Null-safe.
  - The Technical Credibility section (currently a flat row of 6 framework badges) gains, when `github_stats` is present, one trailing badge: `<span class="badge badge-lg badge-outline gap-2">AGPL-3.0 · {{ github_stats.stars }} stars</span>`. Null-safe.
  - No new controller, no new service. Purely template-side wiring of the existing `GithubStatsExtension` global.
  - Integration test with a fake `GithubStats` instance injected via the test-time service alias asserts the hero shows the star count; a second test with the JSON file absent asserts the span is omitted (not rendered as zero or as the literal "null").
- Notes:
  **Architect plan (locked in, 2026-05-24)**

  **Live `github_stats` shape verified** (`src/Value/GithubStats.php`): `readonly final class GithubStats` with `public int $stars`, `public int $forks`, `public \DateTimeImmutable $lastCommitAt` (NEVER null — `fromJson()` returns null for the whole object if the date is missing or malformed), `public string $defaultBranch`. Use `is not null` not bare truthiness (`0` stars is a valid live state — and `0` is falsy in Twig). Existing consumers `/open-source` and `/what-is-sendvery` both use `{% if github_stats is not null %}`.

  **Files to modify** — only `templates/homepage/index.html.twig`. Three insertion points:

  **A. Hero trust badges row** (find the "Self-hostable" span followed by `<span class="text-base-content/20">·</span>` separator before the "See the source" anchor). Insert between them:
  ```twig
  {% if github_stats is not null %}
  <span class="text-base-content/20">·</span>
  <span class="inline-flex items-center gap-1.5">
      {{ github_stats.stars|number_format }} ★ on GitHub · last commit {{ github_stats.lastCommitAt|date('M j') }}
  </span>
  {% endif %}
  ```
  Both the separator dot AND the stats span are inside the `{% if %}` so the separator only renders when stats are present. Use the `★` character (matches acceptance criteria); no inline SVG icon — the unicode star + the surrounding inline-flex pattern matches the other badges' visual rhythm.

  **B. "Star on GitHub" button in section 9 Open Source Callout** — inline label augment, no structural change:
  ```twig
  Star on GitHub{% if github_stats is not null %} ({{ github_stats.stars|number_format }}){% endif %}
  ```

  **C. Technical Credibility badges row** (section 11) — append after the last existing badge ("AI-Powered"), before the closing `</div>`:
  ```twig
  {% if github_stats is not null %}
  <span class="badge badge-lg badge-outline gap-2">
      AGPL-3.0 · {{ github_stats.stars }} stars
  </span>
  {% endif %}
  ```
  Note: badge uses `{{ github_stats.stars }}` WITHOUT `|number_format` (matches acceptance criteria text literally; visual compactness — large counts are unlikely soon).

  **Test injection mechanism — precedent from TASK-011**:

  Both `OpenSourcePageTest` and `WhatIsSendveryPageTest` inject fake stats via:
  ```php
  $twig = self::getContainer()->get('twig');
  \assert($twig instanceof \Twig\Environment);
  $twig->addGlobal('github_stats', new GithubStats(...));
  // or for null case:
  $twig->addGlobal('github_stats', null);
  ```
  `addGlobal()` overwrites whatever `GithubStatsExtension::getGlobals()` registered. Call before `$client->request(...)`. NO `when@test` service alias needed; NO JSON fixture file needed.

  **File to create**: `tests/Integration/Controller/HomepageGithubStatsTest.php` extending `App\Tests\WebTestCase`. Six `#[Test]` methods:

  1. `heroStarsSpanIsRenderedWhenGithubStatsArePresent` — inject `new GithubStats(stars: 314, forks: 15, lastCommitAt: new \DateTimeImmutable('2026-05-20T08:00:00+00:00'), defaultBranch: 'main')`. Assert body contains `'314'`, `'★ on GitHub'`, `'last commit'`, `'May 20'`.
  2. `heroStarsSpanIsOmittedWhenGithubStatsAreNull` — inject `null`. Assert body does NOT contain `'★ on GitHub'`, `'last commit'`, `'null'`, `'0 ★'`.
  3. `starOnGithubButtonLabelIncludesStarCountWhenStatsArePresent` — inject `stars: 89`. Assert body contains `'Star on GitHub (89)'`.
  4. `starOnGithubButtonLabelOmitsStarCountWhenStatsAreNull` — inject null. Assert body contains `'Star on GitHub'` AND does NOT contain `'Star on GitHub ('`.
  5. `agplBadgeIsAppendedWithStarCountWhenStatsArePresent` — inject `stars: 42`. Assert body contains `'AGPL-3.0 · 42 stars'`.
  6. `agplBadgeIsAbsentWhenStatsAreNull` — inject null. Assert body does NOT contain `'AGPL-3.0 · '` (unique substring; verify during impl by grepping rendered HTML that no other element produces this combo).

  **Non-goals**: no `GithubStatsExtension` changes, no new component, no new service, no cron trigger, no extended GitHub data (issues/PRs/contributors).

  **Edge cases decided**: `stars === 0` renders "0 ★ on GitHub" (valid state — null is the missing-state, not zero). `lastCommitAt` is never null on a non-null `GithubStats` (constructor-typed non-nullable). Large counts use comma-separated format via `|number_format` in hero + button; raw in badge.

  **Build sequence**: read homepage line numbers (post-TASK-023/024 shift) → apply insertions A/B/C → create the test file with `$twig->addGlobal()` pattern → phpunit (full suite) → phpstan → php-cs-fixer (with `--allow-risky=yes`) → optional smoke: write a fake `var/github_stats.json`, hit `/`, verify all three augmentations render; delete the JSON, hit `/`, verify all three disappear cleanly.

---

## TASK-026: Homepage section-header system — eyebrow + H2 + lede, replace the seven identical `text-2xl md:text-3xl font-bold` headings

- Status: done
- Area: marketing
- Why: Audit finding. The homepage uses the exact same `<h2 class="text-2xl md:text-3xl font-bold">` markup on **every** section header (How it works, Feature Highlights, Security Expertise, Domain Health Score Preview, Free forever if you self-host, Simple transparent pricing, Frequently asked questions, Built for developers, Start monitoring today). Visually identical headings on 9+ sections create a "scroll-by-flatness" rhythm — the page reads as a long list with no visual hierarchy distinguishing the hero from the support sections from the pre-CTA closer. Real product sites layer their section heads (small uppercase eyebrow + larger headline + optional lede paragraph) to give each section a distinct weight and to telegraph "this is a fresh idea, lean in."
- Acceptance:
  - New `templates/components/SectionHeader.html.twig` Twig component with three slots: `eyebrow: ?string`, `title: string`, `lede: ?string`, plus a `centered: bool = true` prop. Renders eyebrow as `text-xs font-semibold uppercase tracking-[0.18em] text-primary mb-3` (only when non-null), title as `text-3xl md:text-4xl font-bold tracking-tight`, lede as `mt-4 text-base-content/65 text-lg max-w-2xl mx-auto` (only when non-null). Single max-width wrapper.
  - Replace the inline `<h2>`/`<p>` markup on all six body sections of `templates/homepage/index.html.twig` that currently use the identical pattern (sections 2, 5, 6, 7, 8, 9, 10, 11, 12 — anything that's not the hero or the final CTA). Provide each with a distinct eyebrow: "Free check", "How it works", "Capabilities", "Risks in your DNS", "Health grade", "Self-host", "Pricing", "Built for engineers", "Common questions". The hero H1 stays as-is (it's the page's anchor headline). The final CTA H2 stays as-is (intentionally larger to read as a closer).
  - Visually verify on a 1280px desktop and a 360px mobile viewport: the eyebrow + H2 + lede triplet should be visibly different from the hero H1 (smaller) and the final CTA H2 (smaller still). No section header should read as identical-weight to the one before it.
  - Daisyui v5 only. No `dark:` prefix.
  - Integration test: count distinct `<h2>` markup variants in the homepage HTML — at least one section uses the new `eyebrow` label "Capabilities" (regression-guard that the component actually landed). Re-using `SectionHeader` in `templates/about/what-is-sendvery.html.twig` is out of scope for this task to keep the PR small — that page already has a working hierarchy.
- Notes:

---

## TASK-027: Homepage product preview — replace the three tiny WebP icons with a real dashboard mock above the fold of "How it works"

- Status: done
- Area: marketing
- Why: `/what-is-sendvery` has a credible daisyUI dashboard mock (the rotated card with the 3-row domains table). The homepage `How it works` section uses three 80×80px rounded-square WebP icons (`how-connect.webp`, `how-monitor.webp`, `how-act.webp`) and no actual product preview anywhere. A first-time visitor scrolls past the hero and the next thing they're shown is three tiny illustrations and short text — they never see what they'd be buying. The brief explicitly says: *"the homepage should have its own treatment, not duplicate"* the `/what-is-sendvery` mock — so this proposes a different framing: a single annotated mock placed BEFORE "How it works", positioned as "here's what your dashboard looks like" rather than a 3-step diagram.
- Acceptance:
  - New homepage section inserted between section 4 ("Problem Statement") and section 5 ("How it works"): `<twig:SectionContainer bgClass="bg-base-200/20">`. Eyebrow "Your dashboard, one screen", H2 "Everything for one domain in one view".
  - Render a stylised daisyUI HTML mock — distinct from `/what-is-sendvery`'s rotated 3-row domains table. Suggested distinct framing: a **per-domain detail view** mock, not a multi-domain list. Single header row with domain name + grade badge, four DNS status pills (SPF/DKIM/DMARC/MX) in a row, a fake pass-rate sparkline (CSS gradient bar, no real chart lib), and three "recent reports" list items with reporter name + percentage. All values illustrative; small "Illustrative — your data, your domains" caption underneath, identical convention to `/what-is-sendvery`.
  - The mock is wrapped in a subtle browser-chrome frame (three dots + URL bar showing `app.sendvery.com/app/domains/acme.io`) to ground it as a real product surface.
  - One annotation callout (small `<div class="hidden lg:block absolute">` with a thin connecting line) pointing at the grade badge, label: "Single A–F score per domain". Pure CSS, no JS.
  - "How it works" (current section 5) stays in place after this section, framed as the **process** that produces what the mock shows.
  - Mobile (< 768px): mock renders as a vertically-stacked card without the annotation callout. No horizontal overflow at 360px viewport.
  - No real screenshot needed — the mock is pure HTML, swap-in for a real screenshot is a single `<img>` substitution later (note in a `{# TODO #}` comment above the mock).
- Notes:

---

## TASK-028: Homepage de-duplicate the two near-identical feature grids ("Feature Highlights" vs "Security Expertise")

- Status: done
- Area: marketing
- Why: Audit finding. Sections 6 ("Everything you need for email authentication") and 7 ("Your email security, explained") are both 4-card grids using inline-SVG-icon-plus-title-plus-paragraph cards. Section 6 uses `<twig:FeatureCard>`, section 7 uses `<twig:ToolCard>`, but the visitor scrolling past sees two consecutive "grid of four feature cards" sections that feel like leftover drafts of the same idea. Worse, section 7's cards all link to public tool pages, which makes section 7 functionally identical in intent to the footer Tools column — three places (nav dropdown, footer column, section 7) pushing the same destination. Either the second grid is a redundant restatement of capabilities, or it's a tool-discovery surface — pick one and let it be that.
- Acceptance:
  - Either: (A) **Merge** sections 6 and 7 into a single "Capabilities + risks-in-your-DNS" grid of 6-8 cards with a unified visual treatment (`FeatureCard` or `ToolCard`, not both) — half "what we monitor" cards, half "what we have free tools for" cards with explicit tool links; OR
  - (B) **Reframe** section 7 to drop tool-card duplication and instead become a "What problems does this catch?" grid — concrete failure scenarios (e.g. "DKIM key expired after DNS migration", "SPF over 10-lookup limit", "Marketing tool added to SPF without DKIM", "Subdomain inheriting weaker DMARC than apex") with the resolution one-liner, no tool link, no icon, no card — a denser text grid that doesn't visually echo section 6.
  - Whichever ships, the homepage has ONE feature-cards grid, not two. Reduces the section count from 13 to 12.
  - The 4 tool destinations currently in section 7 are still reachable via the nav Tools dropdown and the footer Free Tools column — no link rot.
  - Integration test: count the number of `<twig:FeatureCard>` and `<twig:ToolCard>` invocations on the homepage; assert the combined count is ≤ 8 (down from current 8) AND that exactly one of the two component types is used (not both). Regression-guard.
- Notes:

---

## TASK-029: Replace the lock emoji in section 9 with a real inline-SVG icon (kill the only "AI-default" leak on the homepage)

- Status: done
- Area: marketing
- Why: Audit finding. Every other icon on the homepage is a hand-tuned inline SVG (lucide-style stroke icons, consistent 1.5-2 stroke-width). One outlier: section 9 ("Free forever if you self-host") opens with `<div class="text-4xl mb-4">&#128272;</div>` — a giant lock **emoji**. Emojis render with platform-native styling (Apple emoji on macOS, Segoe on Windows, Noto on Android) — they're the single most-reliable visual tell that a page was drafted by an LLM or shipped quickly without a designer's pass. The page reads polished elsewhere; this one element undermines the rest.
- Acceptance:
  - Replace `<div class="text-4xl mb-4">&#128272;</div>` with an inline SVG lock icon (lucide `lock` or equivalent), sized `w-12 h-12 mx-auto mb-4 text-primary` to match the established icon language on the homepage.
  - Audit the rest of the marketing surface (`templates/homepage/`, `templates/about/`, `templates/legal/`, `templates/knowledge_base/_article_layout.html.twig`, all `templates/tools/*.html.twig`) for any remaining literal-emoji uses inside `<h*>` / hero / section-header positions. Replace each with inline SVG. Acceptable to keep emojis inside body copy that's quoting a user / showing example DNS text — but NOT as a section's visual icon.
  - List of files audited (and either changed or marked "no emoji icons present") is included in the PR description so the next agent can rely on the audit having happened.
- Notes:

---

## TASK-030: Brand mark — add a small Sendvery logo SVG to the nav and footer alongside the wordmark

- Status: done
- Area: marketing
- Why: Audit finding. Both the nav (`templates/components/Nav.html.twig`) and the footer (`templates/components/Footer.html.twig`) brand row render only the text wordmark "Sendvery" in primary colour. No symbol, no mark, no icon. This is fine for a wireframe but unusual for a paying-product marketing site — visitors associate brand legitimacy with a small mark next to the wordmark (envelope-with-checkmark, shield, signal-bars, etc.). The OG image generator (TASK-008) already has a `og-logo.png` fallback path that ships as text — meaning the brand mark exists nowhere as an asset, only as a TODO. This task lands a single SVG mark and wires it into the two primary brand surfaces.
- Acceptance:
  - Add `assets/images/logo-mark.svg` — a simple geometric mark, ~24×24 viewBox, single-colour (uses `currentColor`), works at 16px and 32px. Suggested design language: an envelope outline with a small shield-or-checkmark overlay (mail-with-trust signal), 2px stroke. No multi-colour gradients (must inherit `text-primary` cleanly).
  - Insert in `Nav.html.twig` brand `<a>`: 24×24 SVG to the left of the "Sendvery" text, `gap-2` between mark and wordmark, both inheriting `text-primary`. Both desktop and mobile nav.
  - Insert in `Footer.html.twig` brand block: 32×32 SVG above the wordmark `<a>`, same primary colour.
  - Convert the OG image painter (TASK-008) to render the SVG-derived mark too: drop a 240×60 transparent PNG at `assets/images/og-logo.png` that visually matches the mark + wordmark (this is the asset the existing painter already looks for and silently falls back from). The painter code does NOT change — this task only delivers the asset.
  - Daisyui v5 only. No new dependencies. SVG is hand-written, ≤ 1 KB.
  - The mark uses `currentColor` so when nav or footer is themed (`text-primary` / `text-base-content`) it adopts the surrounding colour automatically.
- Notes:

---

## TASK-031: Sender Inventory & per-domain Blacklist Status are completely unlinked — paying customers can't reach two whole product surfaces

- Status: done
- Area: domains
- Why: Audit finding from the four-paths IA review. `SenderInventoryController` and `BlacklistStatusController` both ship working routes (`/app/domains/{id}/senders`, `/app/domains/{id}/blacklist`), templates, queries, and 100% test coverage — but they appear in **zero** templates as a link. A user lands on `/app/domains/{id}` (the canonical DEEP-DIVE surface), sees a "Top Senders" chart and a "Unique Senders" stat card, and has no clickable affordance to drill from there into the full sender list. Same for blacklist status — `latest.blacklistScore` is rendered as a progress bar on `domain_health.html.twig` with no link to the underlying per-blacklist breakdown. This is the worst single discoverability bug in the dashboard: features that exist, are tested, and that customers paid for, are URL-typing-only. The DEEP-DIVE path is broken — users feel lost because two of the four sub-surfaces of the domain workspace are invisible.
- Acceptance:
  - On `templates/dashboard/domain_detail.html.twig`, add two header action buttons (matching the existing "All reports / DNS History / DNS Health Check" row): "Senders" → `dashboard_sender_inventory` with `{domainId: domain.domainId}`, and "Blacklist" → `dashboard_blacklist_status` with `{id: domain.domainId}`. Use the same `btn btn-ghost btn-sm` styling.
  - On `templates/dashboard/domain_detail.html.twig`, make the **"Unique Senders" `<twig:StatCard>`** (line 93) wrap in an `<a href="{{ path('dashboard_sender_inventory', {domainId: domain.domainId}) }}">` so the count is clickable to its list (per the clickable-cards rule).
  - On `templates/dashboard/domain_detail.html.twig`, add a "View all senders →" link in the "Top Senders" chart card header (alongside the existing chart) pointing at `dashboard_sender_inventory`. The chart is a tease for the deeper view.
  - On `templates/dashboard/domain_health.html.twig`, make the "Blacklist" row in the category-scores list (lines 62, `health-blacklist`) wrap its progress bar in an `<a href="{{ path('dashboard_blacklist_status', {id: domain.domainId}) }}">` so clicking the row drills into the per-blacklist detail.
  - Add a sub-tab row at the top of `domain_detail.html.twig` (under the header, above the quarantine banner) showing the four sibling surfaces of a domain workspace: **Overview · Reports · Senders · DNS · Blacklist · History**. Use `tabs tabs-bordered` daisyUI v5 component. Highlights the currently-active sibling. Add identical tab row to `domain_health.html.twig`, `domain_reports.html.twig`, `domain_dns_history.html.twig`, `sender_inventory.html.twig`, `blacklist_status.html.twig` so the user can move between the six surfaces without bouncing back to the domain detail page each time.
  - Integration test guard: `noOrphanedDashboardRoute` — iterate every `dashboard_*` route, render at least one page that links to it, fail if any controller's route name isn't referenced from at least one rendered template (excluded set: webhooks, POST-only action routes). This catches the next orphaned-page bug at CI time.
  - 100% test coverage on the tab-row component + the new domain-detail link assertions.
- Notes:
  **Architect plan (locked in, 2026-05-24)**

  **Route inventory (CRITICAL — `dashboard_sender_inventory` uses `{domainId}`, ALL others use `{id}`)**:
  | Tab | Route | Param |
  |---|---|---|
  | Overview | `dashboard_domain_detail` | `{id: domain.domainId}` |
  | Reports | `dashboard_domain_reports` | `{id: domain.domainId}` |
  | Senders | `dashboard_sender_inventory` | `{domainId: domain.domainId}` ⚠ |
  | DNS | `dashboard_domain_health` | `{id: domain.domainId}` |
  | Blacklist | `dashboard_blacklist_status` | `{id: domain.domainId}` |
  | History | `dashboard_domain_dns_history` | `{id: domain.domainId}` |

  Using `{id}` for Senders throws `MissingMandatoryParametersException`. This is the single most likely correctness bug.

  **Prerequisite controller change**: `src/Controller/Dashboard/ListDomainReportsController.php` does NOT currently pass a `domain` object — only `domainId` string. Add `GetDomainDetail $getDomainDetail` dependency, call `$domain = $this->getDomainDetail->forDomain($id, $teamIds)` at top of `__invoke`, 404 on null, add `'domain' => $domain` to render vars. WITHOUT this, the tab component throws `Undefined variable "domain"` on `/app/domains/{id}/reports`. This also tightens cross-tenant access enforcement on the reports page.

  **Files to create**

  1. `templates/components/DomainWorkspaceTabs.html.twig` — props `domain` (DomainDetailResult) + `active` (string: `overview|reports|senders|dns|blacklist|history`). Pattern:
     ```twig
     {% props domain, active %}
     <div role="tablist" class="tabs tabs-bordered mb-6 overflow-x-auto">
         <a role="tab" href="{{ path('dashboard_domain_detail', {id: domain.domainId}) }}"
            class="tab {{ active == 'overview' ? 'tab-active' : '' }}">Overview</a>
         <a role="tab" href="{{ path('dashboard_domain_reports', {id: domain.domainId}) }}"
            class="tab {{ active == 'reports' ? 'tab-active' : '' }}">Reports</a>
         <a role="tab" href="{{ path('dashboard_sender_inventory', {domainId: domain.domainId}) }}"
            class="tab {{ active == 'senders' ? 'tab-active' : '' }}">Senders</a>
         <a role="tab" href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}"
            class="tab {{ active == 'dns' ? 'tab-active' : '' }}">DNS</a>
         <a role="tab" href="{{ path('dashboard_blacklist_status', {id: domain.domainId}) }}"
            class="tab {{ active == 'blacklist' ? 'tab-active' : '' }}">Blacklist</a>
         <a role="tab" href="{{ path('dashboard_domain_dns_history', {id: domain.domainId}) }}"
            class="tab {{ active == 'history' ? 'tab-active' : '' }}">History</a>
     </div>
     ```
     `overflow-x-auto` handles horizontal scroll on narrow viewports.

  2. `tests/Integration/Controller/NoOrphanedDashboardRouteTest.php` — extends `KernelTestCase`. Enumerates all routes from `RouterInterface`, filters to `dashboard_*` prefix, auto-excludes POST-only routes via `$route->getMethods()` check (skip if non-empty and no GET). Explicit exclusion constant for GET-but-unreachable-via-nav routes:
     ```php
     private const array EXCLUDED_ROUTE_NAMES = [
         'dashboard_billing_manage', 'dashboard_billing_upgrade',
         'dashboard_export_domain_pdf',
         'dashboard_billing_success', 'dashboard_billing_cancel',
     ];
     ```
     Strategy: concatenate all `.twig` files under `templates/` via `RecursiveDirectoryIterator`, then for each non-excluded GET route assert `str_contains($allTemplates, "path('$routeName'")`. Single `#[Test]` method with per-route assertions.

  3. `tests/Integration/Twig/Components/DomainWorkspaceTabsTest.php` — six `#[Test]` methods, one per surface (overview/reports/senders/dns/blacklist/history). For each: login `onboardedOwner`, GET the URL, assert 200, assert `tab-active` class appears next to the expected label, assert all six labels render.

  **Files to modify**

  4. `src/Controller/Dashboard/ListDomainReportsController.php` — add `GetDomainDetail` dependency + fetch domain + 404 + `domain` template var (see prerequisite above).

  5. `templates/dashboard/domain_detail.html.twig` — four changes:
     - **A. Header buttons** (in the `<div class="flex items-center gap-2">` at lines ~49-59): append two `<a class="btn btn-ghost btn-sm">` for Senders + Blacklist using the right route/param.
     - **B. Sub-tab row** (after closing of header `</div>` ~line 60, before quarantine banner): `<twig:DomainWorkspaceTabs :domain="domain" active="overview" />`.
     - **C. Unique Senders StatCard** (line ~93): wrap in `<a href="{{ path('dashboard_sender_inventory', {domainId: domain.domainId}) }}" class="block">`.
     - **D. Top Senders chart** (lines ~106-123): `<twig:ChartCard>` has no slot for adding header links. Replace the `<twig:ChartCard>` call (NOT the empty-state branch) with inline card markup that mirrors ChartCard's shell:
       ```twig
       <div class="card bg-base-100 border border-base-200 shadow-sm">
           <div class="card-body p-4">
               <div class="flex items-center justify-between">
                   <div>
                       <h3 class="text-sm font-semibold">Top Senders</h3>
                       <p class="text-xs text-base-content/50 mt-0.5">By message volume</p>
                   </div>
                   <a href="{{ path('dashboard_sender_inventory', {domainId: domain.domainId}) }}"
                      class="text-xs text-primary hover:underline">View all senders →</a>
               </div>
               <div class="mt-3" style="min-height: 300px"
                    {{ stimulus_controller('chart', { config: senderChartConfig }) }}></div>
           </div>
       </div>
       ```
       Empty-state branch unchanged (no senders → no point linking).

  6. `templates/dashboard/domain_health.html.twig` — two changes:
     - **A. Sub-tab row**: insert `<twig:DomainWorkspaceTabs :domain="domain" active="dns" />` after closing `</div>` of header (~line 26), before `{% if ruaInstruction %}`.
     - **B. Blacklist row link**: inside `{% for cat in categories %}` loop, wrap only the `<progress>` element conditionally:
       ```twig
       {% if cat.id == 'health-blacklist' %}
           <a href="{{ path('dashboard_blacklist_status', {id: domain.domainId}) }}" class="block">
       {% endif %}
       <progress class="progress {{ ... }}" value="{{ cat.score }}" max="100"></progress>
       {% if cat.id == 'health-blacklist' %}
           </a>
       {% endif %}
       ```
       Preserves `id="{{ cat.id }}"` on the outer `<div>` for deep-linking.

  7. `templates/dashboard/domain_reports.html.twig` — sub-tab insert as first line of `{% block content %}`, before `<twig:ReportsFilterBar>`. (Requires controller change #4 first.)

  8. `templates/dashboard/domain_dns_history.html.twig` — sub-tab insert after closing `</div>` of header (~line 17), before `{% if ruaInstruction %}`.

  9. `templates/dashboard/sender_inventory.html.twig` — sub-tab insert after closing `</div>` of page header (~line 16), BEFORE the existing page-local filter tabs (All/Authorized/Unauthorized at ~line 18). Two-level hierarchy: workspace tabs first, page-local filter tabs below.

  10. `templates/dashboard/blacklist_status.html.twig` — sub-tab insert after closing `</div>` of header (~line 16), before `{% if statusResults is empty %}`.

  **Test additions to `tests/Integration/Controller/DomainSubpagesTest.php`** (5 new methods):
  - `headerHasSendersAndBlacklistButtons` — GET /app/domains/{id}, assert button text + hrefs
  - `uniqueSendersStatCardLinksToSenderInventory` — assert `<a>` wrap around card with right href
  - `topSendersChartHasViewAllSendersLink` — requires `KnownSender` fixture; assert "View all senders →" text + href
  - `blacklistRowLinksToBlacklistStatus` — GET /app/domains/{id}/health with seeded health snapshot
  - `domainWorkspaceTabsRenderOnEachSurface` — data-provider with 6 URLs + expected active labels

  **Edge cases / critical correctness**

  - Senders route param asymmetry — see top of plan. Most likely bug.
  - `domain_reports` controller change is load-bearing — without it the new component fails on `/app/domains/{id}/reports`.
  - Twig component CLAUDE.md anti-pattern (`{% block content %}` around `<twig:>` children) doesn't apply — `DomainWorkspaceTabs` is a leaf component with no children.
  - The Blacklist row keeps its `id="health-blacklist"` anchor on the outer `<div>` so deep-links from elsewhere (e.g. `/app/domains/{id}/health#health-blacklist`) still work.
  - `NoOrphanedDashboardRouteTest` MUST be created LAST (after all templates updated) — otherwise it fails immediately because `dashboard_sender_inventory` and `dashboard_blacklist_status` are still orphaned before the changes land.

  **Non-goals**: no new controllers/routes, no `<twig:StatCard>` refactor, no new tabs on non-domain-workspace pages, no destination-page empty-state copy changes, no `<twig:ChartCard>` component refactor (just inline the Top Senders one instance).

  **Build sequence**:
  1. Modify `ListDomainReportsController` (add `GetDomainDetail`, pass `domain`).
  2. Create `DomainWorkspaceTabs.html.twig`.
  3. Update `domain_detail.html.twig` (4 changes: header buttons, sub-tab, StatCard wrap, Top Senders inline replacement).
  4. Update `domain_health.html.twig` (sub-tab + Blacklist row conditional wrap).
  5. Update `domain_reports.html.twig` (sub-tab).
  6. Update `domain_dns_history.html.twig` (sub-tab).
  7. Update `sender_inventory.html.twig` (sub-tab above existing filter tabs).
  8. Update `blacklist_status.html.twig` (sub-tab).
  9. Add 5 methods to `DomainSubpagesTest.php`.
  10. Create `DomainWorkspaceTabsTest.php` (6 surface tests).
  11. Create `NoOrphanedDashboardRouteTest.php` LAST.
  12. Run phpunit (full + coverage), phpstan, php-cs-fixer (with `--allow-risky=yes`).

---

## TASK-032: "Healthy domain" counts on the overview banner aren't clickable — the named example from the PO brief

- Status: done
- Area: dashboard
- Why: PO brief names this explicitly: *"1 domain needs attention" should drop the user on the offending domain (or a filtered list)*. Today the health-summary banner on `templates/dashboard/overview.html.twig` (lines 29-50) renders three inline counts — "N healthy", "N need attention", "N unverified" — as plain `<span>` text. The user reads "1 needs attention" and has to scroll down past the next-action card, past the setup checklist, past the stat cards, past the trend chart, past the alerts list, to find the Domain Health card at the bottom, then scan five domain rows to find the one with a yellow pass-rate. Every count on this banner must be a one-click jump to the exact filtered subset.
- Acceptance:
  - Each of the three counts ("N healthy", "N need attention", "N unverified") in `overview.html.twig` lines 31-48 becomes an `<a>` linking to `dashboard_domains` with a new `?status=healthy|attention|unverified` query param. When `domainsHealthyCount = 0` etc. the row hides entirely (existing behavior) — links only render for non-zero counts.
  - `templates/dashboard/domains.html.twig` and `ListDomainsController` gain a top filter chip row (`All · Healthy · Need attention · Unverified`) mirroring the alerts page filter pattern (`alerts.html.twig` lines 28-55). URL-driven state via `?status=` query param. "All" is the default.
  - `GetDomainOverview::forTeams()` accepts an optional `?DomainHealthFilter $statusFilter = null` parameter; SQL filters via `HAVING pass_rate >= 90` (healthy), `pass_rate < 90 AND dmarc_verified_at IS NOT NULL` (attention), `dmarc_verified_at IS NULL` (unverified).
  - Empty-with-filter state copy ("No domains match the current filter — clear filter") differentiates from empty-without-filter ("Add your first domain").
  - Stat cards on `overview.html.twig` lines 186-228 also become clickable to their corresponding filtered lists (per audit): Monitored Domains → `/app/domains`, Reports → `/app/reports`, DMARC Pass Rate → `/app/reports?pass_rate=low` (reuses TASK-016's filter), Total Messages → `/app/reports` (no good destination, leave unlinked — document why), Unread Alerts (already linked), Reports this month (already linked).
  - 100% test coverage on the new query filter branches + the integration assertions that each banner count is wrapped in an `<a>` with the right href.
- Notes:
  **Architect plan (locked in, 2026-05-24)**

  **Confirmed from code reading:**
  - Banner count spans on `templates/dashboard/overview.html.twig` lines 32-35, 38-41, 44-47 — all `<span class="inline-flex items-center gap-1">`, all guarded by `{% if healthSummary.domainsXCount > 0 %}`. Inner dot-indicator `<span>` and count text stay unchanged.
  - Stat cards on `overview.html.twig`: line 186 "Monitored Domains" (unlinked), line 192 "Reports (30 days)" (unlinked), line 198 "DMARC Pass Rate" (unlinked), line 204 "Total Messages" (unlinked), line 210 "Unread Alerts" — already wrapped `<a href="{{ path('dashboard_alerts') }}" class="block">`, line 219 "Reports this month" — already wrapped `<a href="{{ path('dashboard_billing') }}" class="block">`. Established stat-card link pattern: wrap entire `<twig:StatCard>` in `<a class="block">`. NO `href` prop on the component.
  - `ReportsFilter` (`src/Value/ReportsFilter.php` lines 65-68) already supports `?pass_rate=low` via `passRateBand` → `passRateMax = 69.99`. No follow-up dependency for the DMARC Pass Rate stat card link.
  - `GetDomainOverview::forTeams()` produces `pass_rate` as `COALESCE(SUM(…)::float / NULLIF(SUM(rec.count), 0) * 100, 0)` — never NULL. PostgreSQL 16 supports SELECT-alias reference in HAVING for non-aggregate expressions.
  - `md.dmarc_verified_at` is a non-aggregate GROUP BY-eligible column on `monitored_domain` (confirmed in `MonitoredDomain` entity line 47). Usable in WHERE and HAVING.
  - `DomainHealthFilter` enum does NOT exist — create it.
  - `ListDomainsController` is at `src/Controller/Dashboard/ListDomainsController.php` (NOT `App/...`). Has no `Request` parameter today.
  - `PersonaBuilder::build()` creates a domain with `dmarcVerifiedAt = null`. Test setup must manually set + flush `dmarcVerifiedAt` to test the verified branches.
  - Filter chip pattern to mirror: `templates/dashboard/alerts.html.twig` lines 28-55 — `<div class="flex flex-wrap gap-2 mb-4">` with `<a class="btn btn-sm {{ active ? 'btn-primary' : 'btn-ghost' }}">` chips.

  **Files to create**

  1. `src/Value/DomainHealthFilter.php` — backed string enum:
     ```php
     enum DomainHealthFilter: string
     {
         case Healthy = 'healthy';
         case Attention = 'attention';
         case Unverified = 'unverified';
     }
     ```
     Enums are implicitly final; no `readonly final class` modifier applies.

  2. `tests/Unit/Value/DomainHealthFilterTest.php` — trivial enum coverage: `tryFrom('healthy')` / `tryFrom('attention')` / `tryFrom('unverified')` return the right case; `tryFrom('garbage')` returns null; each case carries its expected `->value`.

  3. `tests/Integration/Controller/DomainsFilterTest.php` — 8 `#[Test]` methods. Helper builds three personas/domains:
     - healthy: `dmarcVerifiedAt` set, one DMARC report with 10/10 pass → `pass_rate = 100`
     - attention: `dmarcVerifiedAt` set, one DMARC report with 3/10 pass → `pass_rate = 30`
     - unverified: `dmarcVerifiedAt = null`, no reports

     Tests: `domainListWithoutFilterShowsAllDomains`, `domainListFiltersByHealthyStatus`, `domainListFiltersByAttentionStatus`, `domainListFiltersByUnverifiedStatus`, `domainListEmptyWithActiveFilterShowsClearFilterLink`, `domainListEmptyWithoutFilterShowsAddDomainCta`, `invalidStatusQueryParamFallsBackToNoFilter`, `filterChipsRenderedOnDomainsPage`.

  4. `tests/Integration/Controller/DashboardOverviewLinksTest.php` — 9 `#[Test]` methods with the healthy + attention + unverified seed. Tests: each of the three banner counts is an `<a href="/app/domains?status=...">`; each clickable stat card has the right href (Monitored Domains → `/app/domains`, Reports → `/app/reports`, DMARC Pass Rate → `/app/reports?pass_rate=low`); Total Messages is NOT linked (assert no `<a>` ancestor on that card); Unread Alerts + Reports-this-month regression-guard the existing links.

  **Files to modify**

  5. `src/Query/GetDomainOverview.php`:
     - Add `countForTeams(array $teamIds): int` — lightweight unfiltered `SELECT COUNT(*) FROM monitored_domain WHERE team_id IN (:teamIds)`. Returns 0 when `$teamIds` empty. Powers the "zero domains total vs zero matching" branch.
     - Add `?DomainHealthFilter $statusFilter = null` to `forTeams()`. Build conditional WHERE/HAVING clauses:
       - null → no clause additions
       - `Unverified` → `WHERE … AND md.dmarc_verified_at IS NULL`, no HAVING
       - `Healthy` → no extra WHERE, `HAVING pass_rate >= 90`
       - `Attention` → `WHERE … AND md.dmarc_verified_at IS NOT NULL`, `HAVING pass_rate < 90`
     - SQL inject order: `WHERE md.team_id IN (:teamIds){$whereClause} GROUP BY ... {$havingClause} ORDER BY ...`.
     - Document the edge case in a SQL comment: a domain with `dmarcVerifiedAt` set and zero reports gets `pass_rate = 0` (COALESCE fallback) → falls into Attention. Correct: a verified domain receiving zero authenticated messages is actionable.

  6. `src/Controller/Dashboard/ListDomainsController.php`:
     - Add `Request $request` to `__invoke()`.
     - `$statusFilter = DomainHealthFilter::tryFrom($request->query->getString('status', ''))`.
     - `$totalDomainCount = $this->getDomainOverview->countForTeams($teamIdStrings)`.
     - `$domains = $this->getDomainOverview->forTeams($teamIdStrings, $statusFilter)`.
     - Pass `activeFilter`, `totalDomainCount` to template.

  7. `templates/dashboard/domains.html.twig`:
     - Insert filter chip row at top of `{% block content %}` (4 chips: All, Healthy, Need attention, Unverified). Mirror `alerts.html.twig` lines 28-55. Active chip gets `btn-primary` / `btn-warning` / `btn-error` per filter; inactive chips `btn-ghost`.
     - Replace single empty-state with three-way branch:
       - `{% if totalDomainCount == 0 %}` → `<twig:EmptyState>` with "No domains yet" + "Add your first domain" CTA → `dashboard_domain_add` route.
       - `{% elseif domains is empty %}` → `<twig:EmptyState>` with "No domains match the current filter" + "Clear filter" CTA → `dashboard_domains` (no params).
       - `{% else %}` → existing domain grid.

  8. `templates/dashboard/overview.html.twig`:
     - Lines 32-35, 38-41, 44-47: replace outer `<span class="inline-flex items-center gap-1">` with `<a href="{{ path('dashboard_domains', {status: 'healthy|attention|unverified'}) }}" class="inline-flex items-center gap-1 hover:underline">`. Inner dot indicator + count text unchanged.
     - Line 186 Monitored Domains: wrap in `<a href="{{ path('dashboard_domains') }}" class="block">`.
     - Line 192 Reports (30 days): wrap in `<a href="{{ path('dashboard_reports') }}" class="block">`.
     - Line 198 DMARC Pass Rate: wrap in `<a href="{{ path('dashboard_reports', {pass_rate: 'low'}) }}" class="block">`. Add `{# pass_rate=low — actionable subset, live from ReportsFilter (TASK-016). #}` comment above.
     - Line 204 Total Messages: leave unwrapped. Add comment: `{# Total Messages is intentionally unlinked — sum of dmarc_record.count rows aggregated across reports; no per-message drill-down view exists. Revisit if one's added. #}`
     - Lines 210, 219: unchanged (already correctly linked).

  **Edge cases (decided)**

  - Verified-but-zero-reports domain → Attention (intentional, actionable).
  - Pagination: not implemented on domains list today. When added, filter must roundtrip via `toQueryParams()` pattern from `ReportsFilter`. No action this PR.
  - `?status=garbage` → `tryFrom()` returns null → no filtering applied. Covered by test.

  **Non-goals**

  - No changes to `<twig:StatCard>` component.
  - No new stat card links beyond the four named above.
  - No changes to Unread Alerts / Reports this month cards (already correctly linked).
  - No `BlacklistStatusController` / `SenderInventoryController` work — TASK-031 owns that.
  - No pagination work on domains list — separate concern.

  **Build sequence**: create `DomainHealthFilter` → add `countForTeams()` + filter param to `GetDomainOverview` → modify `ListDomainsController` (Request, parse, pass) → update `domains.html.twig` (chips + 3-way empty state) → update `overview.html.twig` (banner anchors + 4 stat-card wraps + 2 explanatory comments) → unit test for enum → integration test for filter behaviour → integration test for overview links → phpunit + phpstan + cs-fixer (with `--allow-risky=yes`).

---

## TASK-033: No global "Add" affordance — adding a domain / mailbox / teammate from anywhere needs three clicks across three pages

- Status: done
- Area: dashboard
- Why: PO brief named ADD as one of the four user paths and asked: *"clear and always reachable?"*. Today: to add a domain from `/app/reports` the user must (1) navigate to Domains, (2) wait for the list to load, (3) click "Add domain". To invite a teammate from `/app/alerts` they must (1) find "Team" in the sidebar Settings section, (2) navigate, (3) scroll to the invite form. To connect a mailbox from `/app/billing` they must (1) navigate to Mailboxes, (2) click "Add mailbox". The "Add" affordance only exists as a page-specific header action on each list page. For a multi-domain team the most-used action — Add domain — should be one click from anywhere.
- Acceptance:
  - Add a single "**+ Add**" dropdown button to the top-bar `{% block header_actions %}` default content in `templates/dashboard/layout.html.twig` (around line 177, before the user email). daisyUI v5 `dropdown dropdown-end` with three menu items: **Add domain** → `dashboard_domain_add`, **Connect mailbox** → `dashboard_mailbox_add`, **Invite teammate** → `team_settings#invite` (or a dedicated `team_invite` route if that anchor doesn't exist).
  - The button is **always visible** on every dashboard page (i.e. the layout-default header action) — page-specific `{% block header_actions %}` overrides on `domains.html.twig`, `mailboxes.html.twig`, `dns_health_overview.html.twig` currently REPLACE the default; change them to additionally include the global dropdown OR drop the page-specific "+ Add domain" header buttons in favour of just the global dropdown (since they duplicate it).
  - Plan-limit guard: each menu item checks the relevant `PlanEnforcement::canAdd*` and renders disabled with a tiny `(limit reached — upgrade)` tooltip when the cap is hit. Disabled item shows `cursor-not-allowed`, no link, and a small `→ Upgrade` chip linking to `dashboard_billing`.
  - Mobile (<lg): the button is `btn btn-sm btn-primary btn-square` showing only the `+` icon to save bar width; the dropdown still works on tap.
  - Integration test: GET each of `/app`, `/app/reports`, `/app/alerts`, `/app/billing`, `/app/team`, `/app/quarantine` and assert the response body contains a link to each of `dashboard_domain_add`, `dashboard_mailbox_add`, and `team_settings`. This regression-guards the always-reachable invariant.
  - 100% test coverage on the plan-limit gating branches (each combination of can-add / can't-add).
- Notes:
  **Architect plan (locked in, 2026-05-24)**

  **Confirmed:**
  - Layout block insertion site: `templates/dashboard/layout.html.twig` line 177 — `{% block header_actions %}{% endblock %}` (empty default).
  - Three per-page overrides at `domains.html.twig` / `mailboxes.html.twig` / `dns_health_overview.html.twig` lines 6-11 — each replaces the layout default with a single "Add X" link, no `{{ parent() }}`.
  - Routes: `dashboard_domain_add` (`/app/domains/add`), `dashboard_mailbox_add` (`/app/mailboxes/add`), `team_settings` (`/app/team` — there is NO `#invite` anchor; the page scrolls to the invite form).
  - `PlanEnforcement` has `canAddDomain`, `canAddTeamMember`, `getDomainCount`, `getTeamMemberCount`. **NO `canAddMailbox`** — mailbox count isn't plan-gated anywhere; mailbox menu item is always enabled.
  - Admin gate: `TeamVoter::MANAGE_MEMBERS` (Owner + Admin roles only). `TeamMembership->role` gives the value; check via `in_array(role, [TeamRole::Owner, TeamRole::Admin])`.
  - **Pending invitations count toward seat cap.** `TeamSettingsController` uses `memberCount + pendingCount` as effective count. The dropdown must do the same — inject `TeamInvitationRepository` and compute pending count.
  - Twig extension precedent: `QuarantineCountExtension` — `implements GlobalsInterface`, try/catch wrap with safe-default fallback for unauthenticated / pre-onboarding states.
  - daisyUI v5 dropdown: use `<details class="dropdown dropdown-end">` + `<summary class="btn ...">` (modern v5 pattern) — different from the team-switcher's older `label`+`tabindex` pattern in the sidebar; the new top-bar dropdown uses the canonical v5 `details/summary` shape.

  **Critical decisions:**
  1. **Drop the three per-page header_actions overrides entirely.** Per-page "Add X" buttons would compete visually with the global dropdown for the same surface. Each list page already has an in-content empty-state CTA for contextual prominence. The global dropdown is the sole top-bar affordance everywhere.
  2. **Mailbox item: always enabled** (no plan limit exists).
  3. **"Invite teammate" hidden (not disabled) for non-admin/owner roles.** A Member or Viewer cannot invite even with seats available; showing a disabled item with an Upgrade link is misleading.
  4. **`canInvite = isTeamManager && (memberCount + pendingCount) < maxMembers`** — both the role AND the seat cap (including pending invites) must permit it.
  5. **`<details>/<summary>` dropdown** (not `label`/`tabindex`).
  6. `domainsListShowsAddButton` test in `DashboardPagesTest` (line 156-162) still passes — the layout-default dropdown renders `a[href="/app/domains/add"]`, so removing the per-page override doesn't break it.

  **Files to create**

  1. `src/Results/GlobalAddLimits.php` — `readonly final class` DTO:
     ```php
     public bool $canAddDomain,
     public int $domainCount,
     public int $maxDomains,
     public bool $canAddMailbox,            // always true
     public bool $isTeamManager,            // Owner or Admin
     public bool $canAddTeamMember,         // plan-seat (including pending) gate
     public int $effectiveMemberCount,      // members + pending invites
     public int $maxMembers,
     ```
     Static `null(): self` factory returning all-permissive zero values for unauthenticated/onboarding fallback.
     Add display methods: `domainLimitDisplay(): string` — returns `'∞'` when `$maxDomains === PHP_INT_MAX`, else `(string) $maxDomains`. Same for `memberLimitDisplay()`. Keeps Twig clean.
     Derived getter (or property) `canInvite: bool = $isTeamManager && $canAddTeamMember`.

  2. `src/Twig/GlobalAddDropdownExtension.php` — `final class … extends AbstractExtension implements GlobalsInterface`. Constructor injects `Security`, `DashboardContext`, `PlanEnforcement`, `PlanLimits`, `GetTeamPlan`, `TeamInvitationRepository`. `getGlobals()` calls `resolveForActiveTeam()`:
     - Guard on `Security::getUser() instanceof User`. Otherwise return `GlobalAddLimits::null()`.
     - Wrap rest in `try { ... } catch (\RuntimeException) { return GlobalAddLimits::null(); }` to handle no-membership / pre-onboarding states.
     - Get active membership via `DashboardContext::getActiveMembership()`. Read `team.id` + `role`.
     - `$plan = $this->getTeamPlan->forTeam($teamId)`.
     - `$canAddDomain = $this->planEnforcement->canAddDomain($teamId, $plan)`; `$domainCount = ...->getDomainCount(...)`; `$maxDomains = $this->planLimits->getMaxDomains($plan)`.
     - `$memberCount = ...->getTeamMemberCount($teamId); $pendingCount = count($invitationRepo->findPendingForTeam($teamId)); $effectiveMemberCount = $memberCount + $pendingCount; $maxMembers = $planLimits->getMaxTeamMembers($plan); $canAddTeamMember = $effectiveMemberCount < $maxMembers;`
     - `$isTeamManager = in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true)`.
     - Return `new GlobalAddLimits(...)`.
     - Returns `['global_add_limits' => $limits]`.

  3. `templates/components/GlobalAddDropdown.html.twig` — anonymous component (no `{% props %}` needed; reads `global_add_limits` global). Structure:
     ```twig
     <details class="dropdown dropdown-end">
         <summary class="btn btn-primary btn-sm gap-1" aria-label="Add">
             <svg ...><!-- + icon, w-4 h-4 --></svg>
             <span class="hidden lg:inline">Add</span>
         </summary>
         <ul class="dropdown-content menu bg-base-100 rounded-box border border-base-200 shadow-lg z-50 w-56 p-1 mt-1">
             {# Domain item #}
             <li>
                 {% if global_add_limits.canAddDomain %}
                     <a href="{{ path('dashboard_domain_add') }}" class="flex flex-col items-start gap-0.5">
                         <span>Add domain</span>
                         <span class="text-xs text-base-content/50">{{ global_add_limits.domainCount }}/{{ global_add_limits.domainLimitDisplay() }} used</span>
                     </a>
                 {% else %}
                     <span class="cursor-not-allowed opacity-60 flex flex-col items-start gap-0.5">
                         <span>Add domain</span>
                         <span class="text-xs text-warning">Limit reached — <a href="{{ path('dashboard_billing') }}" class="underline">upgrade</a></span>
                     </span>
                 {% endif %}
             </li>
             {# Mailbox item (always enabled) #}
             <li><a href="{{ path('dashboard_mailbox_add') }}">Connect mailbox</a></li>
             {# Teammate item (hidden for non-managers) #}
             {% if global_add_limits.isTeamManager %}
                 <li>
                     {% if global_add_limits.canAddTeamMember %}
                         <a href="{{ path('team_settings') }}" class="flex flex-col items-start gap-0.5">
                             <span>Invite teammate</span>
                             <span class="text-xs text-base-content/50">{{ global_add_limits.effectiveMemberCount }}/{{ global_add_limits.memberLimitDisplay() }} seats</span>
                         </a>
                     {% else %}
                         <span class="cursor-not-allowed opacity-60 flex flex-col items-start gap-0.5">
                             <span>Invite teammate</span>
                             <span class="text-xs text-warning">Limit reached — <a href="{{ path('dashboard_billing') }}" class="underline">upgrade</a></span>
                         </span>
                     {% endif %}
                 </li>
             {% endif %}
         </ul>
     </details>
     ```
     The button uses `btn-sm` for tight top-bar height. `<span class="hidden lg:inline">` hides the "Add" label on mobile so the trigger collapses to icon-only. `dropdown-end` aligns menu to right edge to prevent off-screen clipping on mobile.

  4. `tests/Integration/Controller/GlobalAddDropdownTest.php` — 8 `#[Test]` methods:
     - `dropdownRendersOnEveryDashboardPage` (data-provider with 6 URLs)
     - `addDomainItemEnabledUnderLimit`
     - `addDomainItemDisabledAtLimit`
     - `addMailboxItemAlwaysEnabled`
     - `inviteTeammateItemHiddenForMemberRole` (login as Member-role user)
     - `inviteTeammateItemEnabledForAdminUnderLimit`
     - `inviteTeammateItemDisabledForAdminAtMemberCap` (members + pending = max)
     - `dropdownAbsentForUnauthenticatedRequest` (302 redirect, no errors)

  **Files to modify**

  5. `templates/dashboard/layout.html.twig` line 177 — replace `{% block header_actions %}{% endblock %}` with `{% block header_actions %}<twig:GlobalAddDropdown />{% endblock %}`.

  6-8. Delete lines 6-11 from `templates/dashboard/domains.html.twig`, `templates/dashboard/mailboxes.html.twig`, `templates/dashboard/dns_health_overview.html.twig` (entire `{% block header_actions %}...{% endblock %}` override).

  **Non-goals**: no fourth menu item, no user-email dropdown changes, no route changes, no `PlanEnforcement` refactor, no FAB on mobile. Just one new extension + DTO + component + layout-default wiring.

  **Build sequence**: DTO → Extension → Component → Layout default → Remove 3 page overrides → Tests → Quality gates.

---

## TASK-034: DNS Health overview cards are 80% non-interactive — only one tiny "View details" link per card, badges and grade are dead text

- Status: done
- Area: dashboard
- Why: Clickable-cards audit finding. `templates/dashboard/dns_health_overview.html.twig` renders one card per domain. Inside each card: domain name (dead text), grade badge `A`/`B`/`C` (dead text), four SPF/DKIM/DMARC/MX status badges (dead text), and a single "View details" anchor at the card bottom. The card itself isn't a link, the grade isn't a link, the per-protocol badges aren't links. Contrast with `templates/dashboard/domain_detail.html.twig` (lines 31-46) where the same four badges DO deep-link to `#health-spf` etc. anchors. The DNS Health overview is supposed to be the **glanceable** answer to "is anything wrong with my DNS?" — every visual signal on it must drop you on the broken thing in one click.
- Acceptance:
  - The entire card on `dns_health_overview.html.twig` becomes clickable via the stretched-link pattern (per TASK-018) — wrap the card in `<a href="{{ path('dashboard_domain_health', {id: domain.domainId}) }}">` or apply the `position: relative` + `<a class="absolute inset-0">` idiom so the whole card surface is the affordance.
  - The grade badge inside the card becomes a direct link to `#health-score` anchor on the per-domain page.
  - The four SPF/DKIM/DMARC/MX badges become `<a>` tags pointing at `dashboard_domain_health` with the corresponding `#health-spf` / `#health-dkim` / `#health-dmarc` / `#health-mx` fragment — exactly mirroring the deep-link pattern already shipped on `domain_detail.html.twig` (TASK-013).
  - The "View details" button becomes redundant when the whole card is clickable; either remove it or convert it to a secondary action like "DNS history" → `dashboard_domain_dns_history`.
  - Card hover state matches the daisyUI v5 `DomainCard.html.twig` precedent (`hover:shadow-md hover:border-primary/30 transition-all`) so the card visually communicates clickability.
  - Mobile: stretched-link anchor doesn't intercept badge clicks — apply `relative z-10` to the badge `<a>` tags so badge-click drills to the protocol anchor, card-body-click drills to the per-domain page.
  - 100% test coverage on the new anchor assertions (each badge has a deep-link href; the card-wrapping anchor is present; the badge clicks resolve to the anchor URLs, not the parent card URL — verify by asserting `z-10` class on inner anchors).
- Notes:

---

## TASK-035: Mailboxes page is a dead-end table — rows aren't clickable, no inbox preview, no per-mailbox poll history

- Status: done
- Area: dashboard
- Why: TRIAGE + DEEP-DIVE path audit. `templates/dashboard/mailboxes.html.twig` shows a five-column table (Host, Type, Status, Last Polled, Last Error) with a "Re-test" button per row. The rows aren't clickable. Worse, even on success there is no way to answer the most basic operator questions: *"how many envelopes has this mailbox pulled in?"*, *"what was the last DMARC report that came through here?"*, *"is this mailbox sending its envelopes to my domains or to quarantine?"*. Per the PO's "make value visible" lens this is the biggest hidden-data offender in the app — `received_report_email` rows are tied to a `mailbox_connection_id` but the UI never surfaces that relationship. A non-technical user looking at "Last Error: —" has no signal that the mailbox is actually doing useful work.
- Acceptance:
  - New per-mailbox detail page `dashboard_mailbox_detail` at `/app/mailboxes/{id}` rendering: connection details (host/port/encryption/folder), last-poll metadata, **a small stat row** ("Envelopes pulled — total / last 30 days / last 7 days", "Reports parsed", "Envelopes quarantined"), and a 20-row recent-envelopes table (Received at · From · Subject · Status: parsed/quarantined). Each envelope row links to either the parsed report or the quarantine detail (`dashboard_report_detail` / `dashboard_quarantine_detail`).
  - `mailboxes.html.twig` rows become clickable via the stretched-link pattern (TASK-018) to the new detail page. The "Re-test" action stays as a secondary inline button (with `relative z-20` to win pointer events over the stretched link).
  - The stat row's count cells on the mailbox detail page are clickable to the corresponding filtered list (per the clickable-cards rule): "Reports parsed" → `dashboard_reports` filtered by `?mailbox={id}` (extend TASK-016's `ReportsFilter` with a `mailboxId` field), "Envelopes quarantined" → `dashboard_quarantine` filtered by `?mailbox={id}` (extend the quarantine query accordingly).
  - The mailbox listing on `mailboxes.html.twig` gains a small inline activity summary per row: "12 envelopes / 11 reports / 1 quarantined (30d)" so the user can answer "is this mailbox doing useful work?" at the list level without drilling in.
  - 100% test coverage on the new query + the cross-tenant 404 branches + the filtered-list integration paths.
- Notes:

---

## TASK-036: Quarantine page has no filter chips — the three reasons that get reports parked are the obvious axis, and each badge should be clickable

- Status: done
- Area: dashboard
- Why: Clickable-cards audit + IA review. `templates/dashboard/quarantine.html.twig` lists envelopes with a "Reason" column rendering one of three coloured badges: `Unknown domain` / `Unverified domain` / `Plan overage`. The badges are dead text. For a team with 50+ parked envelopes across two reasons (a typical state — Plan overage piles up monthly, Unknown domain piles up from typo / fake-sender traffic), the user has no way to isolate "show me only the plan-overage rows so I can decide whether to upgrade" or "show me only the unknown-domain rows so I can add domains in bulk". Quarantine is also the canonical "data we received but parked" surface — visibility into reason-mix is the first decision the user makes upon arriving.
- Acceptance:
  - Filter chip row at top of `quarantine.html.twig` mirroring the `alerts.html.twig` pattern (lines 28-55): **All · Unknown domain · Unverified domain · Plan overage**. Each chip carries the reason count as a small `(N)` after the label.
  - Each reason badge in the table body becomes an `<a>` linking to the same-filter view (clicking "Plan overage" on row 3 = clicking the "Plan overage" chip up top). Apply `relative z-20` so the badge click wins over the stretched-row anchor.
  - URL-driven filter via `?reason=unknown_domain|unverified_domain|plan_overage`. Empty / `all` shows everything (current default).
  - `GetQuarantineList::forTeam()` and `countForTeam()` both gain a `?string $reasonFilter = null` parameter. Counts are SQL-side, not PHP-filtered, so the chip badges stay accurate under pagination.
  - The "**Reason: Plan overage**" filter view shows a small inline upsell card above the table (re-uses the billing page's PlanOverage warning copy): "These reports were received after you hit this month's cap. Upgrade to unlock them." with link to upgrade flow. Surfaced inline because the user is already looking at the offending rows.
  - The "**Reason: Unknown domain**" filter view shows an inline tip card: "These reports were sent for domains you haven't added yet. Add the domain to start receiving reports — existing parked reports auto-release." (Reuses TASK-020's add-domain-from-quarantine flow.)
  - Empty-with-filter copy differentiates from empty-without-filter ("No reports in quarantine — every report we received has been parsed").
  - 100% test coverage on the new query filter branches + each chip's count assertion.
- Notes:

---

## TASK-037: Domain detail teaches nothing about what `p=none` / `p=quarantine` / `p=reject` MEAN — the most-visible badge in the app is unexplained

- Status: done
- Area: domains
- Why: Make-value-visible audit. The DMARC policy badge (`p=none`, `p=quarantine`, `p=reject`) is rendered on three surfaces: `templates/dashboard/domain_detail.html.twig` (line 16), `templates/dashboard/report_detail.html.twig` (multiple places), and `templates/dashboard/domain_health.html.twig` (in the recommendations). It's a tiny badge with no tooltip, no explanation, no "what should I do next?" callout. A Marketing-Maria persona looking at `p=none` for the first time has no idea this is the WEAKEST DMARC policy — she'd assume "none = no problems". This is exactly the moment Sendvery is supposed to translate XML jargon to plain English, and it doesn't. The next-tier upgrade ("move to p=quarantine") is also a content goal in `docs/05-monetization.md` — the moment to surface that in-product is when the user is staring at their current policy.
- Acceptance:
  - New `templates/components/DmarcPolicyExplainer.html.twig` Twig component rendering a `card bg-base-100 border` panel with: large current-policy badge, plain-English title ("You're at **p=none** — Monitor-only mode"), 2-3 sentence explanation, a horizontal progress-row showing the three policy tiers as connected dots (`none → quarantine → reject`), and a "What's next" suggestion box with concrete copy per current policy:
    - **p=none**: "You're collecting data but not enforcing. Once your DMARC pass rate is consistently above 90% for ~4 weeks, move to `p=quarantine; pct=10` to start gradual enforcement on suspicious mail."
    - **p=quarantine**: "Suspicious mail is going to spam. Once your DKIM/SPF alignment is rock-solid (pass rate >95%), move to `p=reject` to fully block spoofed mail."
    - **p=reject**: "You're at the strongest DMARC posture. Spoofed mail is blocked outright. Monitor for legitimate mail accidentally caught by enforcement."
  - Each suggestion links to the corresponding KB article: `learn/migrate-dmarc-from-none-to-reject` (already exists per TASK-007). The component's "Read the migration guide →" link is the in-app entry to that article.
  - Insert the component on `domain_detail.html.twig` between the quick-stats grid (line 86-95) and the charts row — so it's directly under the metrics that justify the recommendation (a 99% pass-rate domain with `p=none` SHOULD nudge to quarantine; a 60% pass-rate domain with `p=none` should NOT — see logic below).
  - Logic lives in `src/Services/DmarcPolicyAdvisor.php` testable service — pure-computation, returns a `DmarcPolicyAdvisorResult` DTO with `currentPolicy`, `recommendedNextPolicy`, `eligibleForNextTier: bool`, `reasonText: string`. Eligibility rule: at `p=none` → eligible when 4-week trailing pass rate ≥ 90% AND at least 3 reports parsed; at `p=quarantine` → eligible when pass rate ≥ 95%; at `p=reject` → always "you're at strongest tier".
  - When NOT eligible (still building data), the suggestion box becomes informational: "Still collecting data. Move to the next tier once your pass rate stabilises above 90%."
  - 100% test coverage on the advisor service (all five branches: no-policy / p=none-not-ready / p=none-ready / p=quarantine-not-ready / p=quarantine-ready / p=reject) + the component renders for each branch.
- Notes:

---

## TASK-038: Domain detail "Top Senders" chart is a chart-of-mystery — no labels, no authorization status, not clickable, value is hidden

- Status: done
- Area: domains
- Why: Make-value-visible audit. `templates/dashboard/domain_detail.html.twig` (lines 106-123) renders an ApexCharts donut/bar of "Top Senders by message volume." There's no legend showing which sender is which, no indication of which senders are Authorized vs Unknown (the data is in `known_sender` and we already query it for `report_detail.html.twig` per TASK-017), no link from any chart segment to the sender's detail row. The single most actionable insight a user can get from DMARC data — "Mailchimp is sending 40% of my mail and 8% of it fails DKIM" — is literally on this page but unreadable. This is the canonical "data we have, magic we're hiding" gap.
- Acceptance:
  - Below the existing chart, add a **labelled sender summary table** (top 5 senders, sorted by message volume): columns *Sender (org or hostname or IP) · Messages · DKIM pass % · SPF pass % · Status (Authorized/Unknown badge)*. Reuses the `ReportSenderGroupResult` shape from TASK-017 but aggregated across all reports for the domain (new query `GetTopSendersForDomain`).
  - Each row in the table is a stretched-link anchor to `dashboard_sender_inventory` with a `#sender-{id}` fragment that scrolls to the matching row on the sender list. (Requires adding `id="sender-{{ sender.id }}"` to each row on `sender_inventory.html.twig`.)
  - The chart segments get matching labels (org/hostname/IP) and the colour palette pairs Authorized senders in `--color-success` and Unknown senders in `--color-warning` — so the visual signal "your bulk-mail volume comes mostly from authorized sources" is glanceable.
  - A small **"Senders by authorization status"** stat row appears above the chart: "**X** authorized · **Y** unknown · **Z** unique IPs" — each count is clickable and filters the sender inventory page (uses the existing `?filter=authorized|unauthorized` query param).
  - Empty-state copy (no sender data yet): instead of just hiding the chart, render an educational placeholder ("DMARC reports tell us which servers are sending email as your domain. Sender breakdown appears here once Gmail/Outlook send their first report — usually within 24 hours of publishing DMARC.") with a link to `learn/what-is-dmarc`.
  - 100% test coverage on the new query + the table's stretched-link + the chart's authorization-colour-coding (assert `--color-success` / `--color-warning` references in the rendered chart config).
- Notes:

---

## TASK-039: Sidebar groups operational, ingestion, and system surfaces in one flat list — the only divider is "Settings" but it covers Team/Billing/Preferences too

- Status: done
- Area: dashboard
- Why: IA audit, four-paths review. `templates/dashboard/layout.html.twig` lines 78-142 render a single flat nav list with one `<div class="divider">Settings</div>` separator at line 124. The OPERATIONAL surfaces (Dashboard / Domains / Reports / Quarantine / Alerts / DNS Health / Mailboxes) sit above the divider in one flat block of 7 items; SYSTEM surfaces (Team / Settings / Billing) sit below. But within the OPERATIONAL block, "Mailboxes" is an ingestion-side tool while everything else is a viewing-side surface — they're conceptually different. The PO brief asked specifically: *"is the sidebar order sensible? Are operational vs system surfaces visually separated?"*. Current state: yes there's one divider, but no grouping/eyebrow labels, and "Mailboxes" feels lost in the operations block.
- Acceptance:
  - Group the sidebar into three labelled sections with small uppercase eyebrow labels (`text-[10px] uppercase tracking-wider text-base-content/40`):
    1. **OVERVIEW** — Dashboard
    2. **DOMAINS** — Domains, DNS Health
    3. **DATA** — Reports, Quarantine, Alerts
    4. **INGESTION** — Mailboxes
    5. **SETTINGS** (existing divider, keep label) — Team, Settings, Billing
  - The visual treatment for eyebrow labels matches the existing "Team" section label on lines 28-29 (small uppercase, low-contrast). Use plain `<div>` not divider — dividers between every group is too noisy.
  - Sidebar order within each section preserved from current order. The only re-grouping is the eyebrow labels.
  - Active-state highlighting unchanged — operational/system grouping is purely cosmetic.
  - Mobile (<lg): same grouping renders cleanly inside the hamburger overlay.
  - Integration test: assert each eyebrow label is present in the rendered sidebar; assert the 5 eyebrow labels appear in the documented order.
- Notes:

---

## TASK-040: Recent-Reports / Domain-Health cards on `/app` show numbers but no in-card filters — "View all" is the only escape hatch

- Status: done
- Area: dashboard
- Why: Clickable-cards + IA audit. `templates/dashboard/overview.html.twig` lines 268-348 render two side-by-side cards: "Recent Reports" (5 latest, table) and "Domain Health" (top 5 domains, list). Both cards' header has a single "View all" link, and the rows inside are clickable to the report/domain detail page. What's missing: there are no per-card filter chips to narrow the view in-place, AND the column headers (Pass Rate, Reports count) aren't sortable. A user scanning these cards has no way to answer "which domains are failing most?" — they have to click "View all" → land on the full domains list → and only then get sorting via the columns.
- Acceptance:
  - On the "Recent Reports" card header, add a small inline filter dropdown next to "View all": **Last 7 days · Last 30 days · Last 90 days** (default 7d). URL-state persisted via `?recent_reports_range=7d|30d|90d` on `/app`. Re-fetches the card via existing Doctrine query parameterised by the date range.
  - On the "Domain Health" card header, add a small sort toggle: **Worst first · Best first · Most reports** (default Worst first — surfaces problems). URL-state via `?domain_health_sort=`.
  - On the "Recent Reports" table, add a small clickable filter chip below the table: "Show only failing (<70% pass)" — toggles a per-card filter to surface problems. URL-state via `?recent_reports_failing=1`.
  - In the "Domain Health" card, each domain row gains a small "pass rate sparkline" — a tiny 30-day SVG trend so the user can see "this domain is degrading" without clicking through.
  - The two "View all" links continue to work as escape hatches to the full list pages — the in-card filters supplement them, don't replace them.
  - 100% test coverage on the new filter branches + sparkline rendering (assert `<svg>` and the right number of data points).
- Notes:


---

## TASK-041: Domain detail page stacks two competing navigation rows — five legacy header buttons sit directly above the new sibling-tab strip

- Status: done
- Area: dashboard
- Why: First-impression UX gap on the single most-visited authenticated page. `templates/dashboard/domain_detail.html.twig` lines 49-65 still render the pre-TASK-031 navigation: a horizontal row of five `btn btn-ghost btn-sm` buttons ("All reports / DNS History / DNS Health Check / Senders / Blacklist") inside the page header. Line 68 then renders `<twig:DomainWorkspaceTabs active="overview" />` — the canonical Overview / Reports / Senders / DNS / Blacklist / History tab strip introduced by TASK-031. The five header buttons and the six tabs link to four of the same destinations (Reports, Senders, DNS, Blacklist) and one near-duplicate (the legacy "DNS Health Check" button vs the new "DNS" tab both target `dashboard_domain_health`; the legacy "DNS History" button vs the new "History" tab both target `dashboard_domain_dns_history`). The result is two parallel nav rows stacked on top of each other on every domain Overview page — a paying customer would read this as unfinished work. The five other domain-workspace surfaces (`domain_reports`, `domain_health`, `sender_inventory`, `blacklist_status`, `domain_dns_history`) render only the tab strip and are clean; the legacy buttons were simply never removed from `domain_detail` when TASK-031 landed.
- Acceptance:
  - Remove the five-button `.flex.items-center.gap-2` row at lines 49-65 of `templates/dashboard/domain_detail.html.twig`. `<twig:DomainWorkspaceTabs active="overview" />` (line 68) becomes the sole sub-nav surface — matching the other five domain-workspace templates.
  - The page header right-rail is allowed to retain page-scoped actions only (e.g. PDF export, share-link copy) if any are added later; sibling-page navigation belongs exclusively on the tab strip.
  - Snapshot/integration test: render `/app/domains/{id}` and assert the rendered HTML contains exactly ONE element matching `[role="tablist"]` AND contains zero header `btn btn-ghost btn-sm` anchors whose href matches one of the five workspace routes (`dashboard_domain_reports`, `dashboard_domain_dns_history`, `dashboard_domain_health`, `dashboard_sender_inventory`, `dashboard_blacklist_status`). The same assertion should be added to the other five domain-workspace templates so a regression on any of them fails CI — pairs nicely with the existing `NoOrphanedDashboardRouteTest`.
  - Visual check: the header collapses to (domain name + status badges + protocol badges) on the left, nothing on the right; the tab strip becomes the visual anchor for sub-surface navigation.
- Notes: The fix is a deletion, not new work. Total LOC delta is ~17 lines removed + ~15-20 lines of new regression test. Suggested implementation effort: 30-45 minutes.

---

## RUN SUMMARY — 2026-05-24 third autonomous CX loop

### Shipped (13 tasks)

| # | Task | Commit | Area | Headline change |
|---|---|---|---|---|
| 023 | Homepage testimonials | `9a5a5b3` | marketing | New `TestimonialsSection` component + `config/placeholders.php` with 6 placeholder testimonials (3 visible, 3 bench), all marker-tagged; `PlaceholdersExtension` exposes a Twig global; unit test enforces marker parity so a partial pre-launch swap fails CI. |
| 024 | Homepage founder bio | `cbc3700` | marketing | New `FounderBio` component slotted between testimonials and Open Source Callout; deliberately differs from `/what-is-sendvery`'s italic blockquote (no italic, no `<blockquote>`, no `text-5xl` quote, larger avatar). Photo + LinkedIn key reserved in placeholders config; both null today. |
| 025 | GitHub stats trust strip | `66b9147` | marketing | Three null-safe insertions wiring the existing `github_stats` Twig global into the homepage hero badges, the "Star on GitHub" button label, and a new AGPL+stars badge in Technical Credibility — all silently absent when the cache is empty. |
| 026 | Section-header system | `e42078c` | marketing | Extracted `<twig:SectionHeader>` component (eyebrow + H2 + lede) and replaced 9 inline `<h2 class="text-2xl md:text-3xl font-bold">` blocks on the homepage; TestimonialsSection + FounderBio also converted so 11 sections share the new rhythm. Hero H1 / Problem Statement / final CTA intentionally untouched. |
| 029 | Lock emoji | `11718b8` | marketing | Replaced the `&#128272;` lock emoji on section 9 with a hand-tuned lucide-style inline SVG; full marketing-surface audit found no other section-icon emojis. |
| 030 | Brand mark SVG | `896d60d` | marketing | New 24×24 envelope-with-check mark next to the wordmark in `Nav.html.twig` and `Footer.html.twig`, inlined via `currentColor` so it inherits the surrounding `text-primary`; same SVG also committed as `assets/images/logo-mark.svg` for external embeds. |
| 031 | Reach Sender + Blacklist | `fb6ba6e` | domains | New `DomainWorkspaceTabs` component (Overview / Reports / Senders / DNS / Blacklist / History) inserted into all 6 domain-workspace templates; the previously-unreachable Sender Inventory and Blacklist Status surfaces now have entry points from the domain detail page (header buttons, Unique-Senders stat-card wrap, "View all senders →" in Top Senders, anchor on the Blacklist progress row). `NoOrphanedDashboardRouteTest` guard catches future orphans at CI time. The Reports controller also tightened cross-tenant access enforcement (404 instead of empty 200). |
| 032 | Clickable cards on `/app` | `0c5bb05` | dashboard | The owner's explicit example ("1 domain needs attention" → drop the user on the offending domain). New `DomainHealthFilter` enum + `?status=healthy/attention/unverified` query filter on `GetDomainOverview::forTeams()` + filter chip row on `/app/domains`. Three banner counts and three previously-static stat cards (Monitored Domains / Reports / DMARC Pass Rate) became anchor wrappers. |
| 033 | Global "+ Add" affordance | `fdc97c1` | dashboard | New `GlobalAddDropdown` component with three menu items (Add domain / Connect mailbox / Invite teammate) in the layout-default top-bar action slot, plan-limit gated via a new `GlobalAddDropdownExtension` reading `PlanEnforcement` + pending-invite counts. "Invite teammate" hidden for non-Owner/Admin roles. Three duplicated per-page "+ Add X" buttons (domains, mailboxes, dns-health) removed in favour of the global affordance. |
| 034 | DNS Health cards clickable | `3589578` | dashboard | Stretched-link pattern (TASK-018) applied to every card in `/app/dns-health`; each protocol badge (SPF/DKIM/DMARC/MX) is its own deep-link to `#health-spf` / `#health-dkim` / `#health-dmarc` / `#health-mx` on the per-domain page. `relative z-10` on inner badges so the card-wrap anchor doesn't eat their clicks. |
| 036 | Quarantine reason filter | `6081291` | dashboard | New `QuarantineReasonFilter` enum + filter chip row (All / Unknown domain / Unverified domain / Plan overage with `(N)` counts from a new `countByReason`). Each row's reason badge becomes an anchor to its filtered view. Reason-specific inline help cards (plan_overage upsell + unknown/unverified tips). Shared `visibilitySql()` extraction keeps the cross-tenant union-of-rules scoping in lockstep across `forTeam`, `countForTeam`, `countByReason`. |
| 038 | Top Senders chart enhancement | `4cdd786` | domains | New `GetTopSendersForDomain` query (replacing the single-caller `GetDomainSenderBreakdown`) with a sibling `summaryForDomain()` method. Stat row above the chart ("X authorized · Y unknown · Z unique IPs", each clickable to the filtered Sender Inventory); chart redesigned from stacked Pass/Fail to single-series distributed bar with per-bar authorization color coding via `var(--color-success)` / `var(--color-warning)`; new 5-row labelled table below the chart (Sender / Messages / DKIM% / SPF% / Status badge) with stretched-link rows to `#sender-{id}` fragments on the inventory page. Educational empty-state for domains with no DMARC data. |
| 039 | Sidebar grouping | `6eccad1` | dashboard | The flat 10-item sidebar list became 5 eyebrow-labelled groups (Overview / Domains / Data / Ingestion / Settings); DNS Health moved up into the Domains group so operational items cluster by function. Regression-guard test asserts the eyebrows render in the documented order. |

**Suite at run end:** 1808 tests, 4956 assertions, all green. PHPStan clean. PHP-CS-Fixer clean (0 of 834 files). 100% line coverage on every new file.

### Blocked: 0

Every architect → developer → reviewer cycle landed cleanly. Reviewer rounds caught and fixed 6 substantive defects during the run:
- TASK-023: convention-test marker count was `>=` instead of `==`, and the banner comment in `placeholders.php` quoted the marker string literally — net effect: one real entry could be swapped without removing its marker without failing CI. Fixed both.
- TASK-031: original architect plan missed that `ListDomainReportsController` doesn't pass a `domain` object to its template — without the controller change the new `<twig:DomainWorkspaceTabs>` would throw `Undefined variable "domain"` on `/app/domains/{id}/reports`. Caught and added the prerequisite.
- TASK-031: the Senders route uses `{domainId}` while every other domain-workspace route uses `{id}` — most likely correctness bug, called out in architect notes and verified in tests.
- TASK-033: `GlobalAddDropdownExtension` called `PlanEnforcement::canAddDomain()` AFTER already computing `domainCount` and `maxDomains` — redundant `SELECT COUNT(*)` query on every authenticated dashboard render. Replaced with `$domainCount < $maxDomains`.
- TASK-036: `NoOrphanedDashboardRouteTest` originally listed `dashboard_billing_upgrade` + `dashboard_export_domain_pdf` as defensive exclusions — both ARE template-linked, so the exclusion suppressed the exact regression the test was designed to catch. Removed; test still passes cleanly.
- TASK-038: cross-tenant isolation tests missing on `GetTopSendersForDomain` (forDomain + summaryForDomain). The guard SQL existed but the regression net didn't. Added both.

### Deferred for follow-up (NOT in scope for this run)

Remaining `proposed` tasks for a future autonomous run, ordered by judged priority:

1. **TASK-037 — DMARC policy explainer** (feature depth, high marketing-value-visible win). The `p=none` / `p=quarantine` / `p=reject` badge is the most-visible badge in the app and is completely unexplained. New `DmarcPolicyAdvisor` service + `DmarcPolicyExplainer` component with plain-English nudge to next tier; integrates on `domain_detail`, `report_detail`, `domain_health` recommendations. Largest remaining task.
2. **TASK-035 — Mailboxes page is a dead-end table** (paths-b feature depth). New per-mailbox detail page with envelopes-pulled stats + recent-envelopes table + links into filtered reports/quarantine. Substantial — new route + new query + new template.
3. **TASK-027 — Homepage product preview** (marketing, currently no product surface shown above the fold of "How it works"). New section with a non-duplicate-of-`/what-is-sendvery` HTML mock framed as a per-domain detail view with annotation callout. Medium-large scope.
4. **TASK-028 — De-duplicate homepage feature grids** (marketing polish). Sections 6 ("Feature Highlights" via `<twig:FeatureCard>`) and 7 ("Security Expertise" via `<twig:ToolCard>`) are two consecutive 4-card grids with overlapping intent. Architect call: either merge into one canonical grid or reframe section 7 as a denser "What problems does this catch?" text grid. Reduces section count from 13 to 12.
5. **TASK-040 — In-card filters on `/app` Recent Reports + Domain Health cards** (paths-a TRIAGE refinement). Adds date-range dropdowns, sort toggles, failing-only chip, 30-day sparklines per domain. Substantial — multiple controller params + new query branches + SVG sparkline rendering.

### Architectural notes added this run

- **`PlaceholdersExtension` pattern (TASK-023 + TASK-024)** — `config/placeholders.php` is the single launch-swap surface. New placeholder content (testimonial entries, founder fields) goes through this file; the convention test guarantees the swap convention holds. When the human swaps in real testimonials at launch, two integration tests (`firstThreeTestimonialNamesAreVisible`, `benchTestimonialNamesAreNotRendered`) need their hardcoded names updated alongside.
- **`<details>/<summary>` daisyUI v5 dropdown pattern (TASK-033)** — `GlobalAddDropdown` uses `<details class="dropdown dropdown-end">` + `<summary class="btn ...">`, the canonical v5 top-bar dropdown shape. Different from the sidebar team-switcher's older `label`/`tabindex` pattern. Future top-bar dropdowns should follow the new pattern.
- **`DomainWorkspaceTabs` component contract (TASK-031)** — six tabs always render in the same order on every domain-workspace surface. Adding a new sibling page to the workspace means adding a 7th tab here AND insertion of the component in the new template. The `NoOrphanedDashboardRouteTest` guard ensures the new page's route doesn't end up orphaned.
- **`NoOrphanedDashboardRouteTest` philosophy (TASK-031)** — defensive exclusions in `EXCLUDED_ROUTE_NAMES` should only list routes that are GENUINELY not template-linked by design (Stripe callbacks, redirect-only routes). Listing a template-linked route "defensively" suppresses the exact regression the test catches.
- **Filter-chip pattern (TASK-032 + TASK-036)** — established at three surfaces now: alerts (TASK-015), domains (TASK-032), quarantine (TASK-036). Pattern: `<div class="flex flex-wrap gap-2 mb-4">` with `<a class="btn btn-sm {{ active ? colorClass : 'btn-ghost' }}">` chips; `?status=` / `?reason=` / `?filter=` URL params; `tryFrom()` on a backed enum for the parse; a `?Filter $param = null` argument on the Query method; a three-way empty-state branch (zero total / zero matching / has matching) in the template; pagination links carry the filter forward; an architect/reviewer guard test asserts all chip hrefs render. The fourth instance should consider unifying the chip rendering into a `<twig:FilterChips>` component.
- **Stretched-link + badge z-index (TASK-018 + TASK-034 + TASK-036)** — the canonical idiom is `relative` on the wrapper + `<a class="absolute inset-0 z-10">` for the stretched anchor + `relative z-20` on any inner interactive child that needs to win clicks. Now applied on DomainCard, DNS Health Overview cards, and Quarantine table rows.
- **`SectionHeader` rhythm (TASK-026)** — the homepage uses `text-3xl md:text-4xl font-bold tracking-tight` for body-section H2s, distinct from the hero's `text-4xl md:text-5xl lg:text-6xl font-extrabold` and the final CTA's `text-3xl md:text-4xl font-bold` (no `tracking-tight`). Eyebrow labels are `text-xs font-semibold uppercase tracking-[0.18em] text-primary mb-3`. Lede paragraphs are `text-base-content/65 text-lg max-w-2xl mx-auto`. New homepage sections MUST use `<twig:SectionHeader>`.
- **Per-bar authorization color tokens (TASK-038)** — `var(--color-success)` / `var(--color-warning)` literals in the ApexCharts `colors: [...]` array. Browsers resolve the CSS variable at SVG paint time and the rendered HTML self-documents the rule. The `topSendersChartConfigUsesAuthorizationColorTokens` test asserts the literal substrings in the response body.

### Stop reason

Voluntary natural checkpoint. The two seed focus areas the human owner explicitly named — MARKETING trust/social-proof and DASHBOARD IA-around-the-four-paths + clickable-cards — are substantially complete. The remaining 5 tasks are all 1-3 hour substantial pieces and benefit from a fresh run with the user's review of the 13 shipped commits first.

---

## RUN SUMMARY — 2026-05-24 fourth autonomous CX loop (continuation)

### Shipped (6 tasks, completing the backlog)

| # | Task | Commit | Area | Headline change |
|---|---|---|---|---|
| 037 | DMARC policy explainer | `b49c562` | domains | New `DmarcPolicyAdvisor` service + `DmarcPolicyExplainer` component on `domain_detail` — plain-English policy state, 3-tier progress dots, concrete next-step nudge gated by trailing-window pass rate ≥ 90% (none → quarantine) / ≥ 95% (quarantine → reject), KB migration-guide link when eligible. New `GetDomainDetail::getRecentActivity()` ensures both the pass-rate gate and report-count gate measure the same 30-day population (lifetime average was the wrong input). `DmarcPolicy::tryFrom` (not `from`) hardens the controller against unrecognised DB values. |
| 035 | Mailbox detail page | `36049dc` | dashboard | New `/app/mailboxes/{id}` route + `ShowMailboxDetailController` + `GetMailboxDetail` query showing connection metadata, three-card stat row (envelopes pulled 30d / reports parsed / envelopes quarantined), 20-row recent envelopes table with parsed/quarantined deep links. List rows became stretched-links to the detail page; each row gained an inline "12 envelopes / 11 reports / 1 quarantined (30d)" activity summary via a single batch query. `ReportsFilter` gained `mailboxId` (joins via `EXISTS` on `received_report_email.source_envelope_id` since `dmarc_report` has no direct mailbox FK); `GetQuarantineList::forTeam` and `countByReason` gained `mailboxFilter` so the quarantine chip counts and chip hrefs honour the active mailbox filter rather than silently dropping it. |
| 028 | Risks grid replaces duplicate ToolCard grid | `541230d` | marketing | Section 7 used to be a second 4-card grid that visually echoed section 6 and functionally duplicated the nav Tools dropdown. It became a denser 4-cell text grid of concrete failure modes ("DKIM key expired after a DNS migration", "SPF crept over the 10-lookup limit", "Marketing tool added to SPF without DKIM", "Subdomain inherits weaker policy than apex") — no cards, no icons, no tool links. The four tool destinations remain reachable via the nav + footer (no link rot). Homepage now has ONE feature-cards grid, not two. |
| 027 | Homepage product preview | `03a55bd` | marketing | New "Your dashboard, one screen" section inserted between Problem Statement and How it Works, rendering a per-domain detail-view HTML mock inside a browser-chrome frame (URL bar showing `app.sendvery.com/app/domains/acme.io`). Includes a single A grade badge, four DNS status pills, a pass-rate sparkline (pure CSS gradient bar), three recent-report rows, and a desktop-only annotation callout with a thin diagonal connector line pointing at the grade badge. Distinct treatment from `/what-is-sendvery`'s rotated multi-domain table. |
| 040 | In-card filters + sparklines on `/app` | `07385bd` | dashboard | The Recent Reports and Domain Health cards became actually interactive. Recent Reports header gains a Last 7 / 30 / 90 day dropdown (default 7d, URL state `?recent_reports_range=`); footer gains a "Show only failing (<70% pass)" toggle chip (`?recent_reports_failing=1`). Domain Health header gains a Worst / Best / Most reports sort dropdown (default Worst — surfaces problems, `?domain_health_sort=`). Each Domain Health row gains a 30-day pass-rate sparkline (inline SVG, pure CSS, no chart lib) sourced from a new `GetDomainPassRateTrend` query that produces 10 three-day buckets per domain in a single SQL. Best sort uses `NULLS LAST` so zero-record domains don't outrank genuine 100%-pass-rate domains. |
| 041 | Drop legacy header buttons on domain detail | `<this-commit>` | dashboard | Fresh-eyes audit catch — TASK-031 had added the `<twig:DomainWorkspaceTabs>` strip but left the legacy five-button header row in place, creating two competing nav rows stacked vertically on the most-visited authenticated page. Removed the legacy buttons; the sibling-tabs strip is now the sole cross-surface affordance for the domain workspace. Updated the TASK-031 regression test from "header has buttons" to "tabs have the right hrefs AND legacy buttons don't come back" to lock the post-TASK-041 invariant. |

**Suite at run end:** 1890 tests, 5235 assertions, all green. PHPStan clean. PHP-CS-Fixer clean. 100% line coverage on every new file.

### Blocked: 0

Reviewer rounds caught and fixed 4 substantive defects in this continuation:
- TASK-037: `DmarcPolicy::from()` on a raw DBAL string threw `\ValueError` on values the enum didn't recognise. Changed to `tryFrom() ?? None` so legacy rows / future spec revisions don't blow up the page.
- TASK-037: lifetime pass rate fed alongside 30-day trailing report count — different populations. Added `GetDomainDetail::getRecentActivity()` returning both `reportsCount` and `passRate` for the same window so the advisor sees consistent inputs.
- TASK-035: quarantine reason chips silently dropped the `?mailbox` filter when clicked AND showed team-wide totals (not mailbox-scoped). Fixed by passing `$mailboxFilter` to `countByReason()` and threading `mailbox: mailboxFilter` into every chip's `path()` call.
- TASK-040: Best sort lacked `NULLS LAST` — under a future refactor that removed the `COALESCE`, zero-record domains would float to the top in `DESC` order. Made the SQL self-documenting and added a regression test.

### All four seed focus areas — final state

1. **Marketing — homepage hero & value clarity**: hero copy + value prop holds. New product-preview mock (TASK-027) is the first thing a scrolling visitor sees after Problem Statement, replacing three tiny illustration icons. ✅
2. **Marketing — trust & social proof**: testimonials section (TASK-023), founder bio (TASK-024), GitHub-stats trust strip (TASK-025), brand-mark SVG (TASK-030), section-header rhythm (TASK-026), lock-emoji removed (TASK-029), duplicate feature grids deduped (TASK-028). ✅
3. **Dashboard — IA around four user paths**:
   - TRIAGE (`/app`): clickable banner counts + clickable stat cards + in-card date-range / sort / failing-only filters + per-domain sparklines. (TASK-032, TASK-040)
   - DEEP-DIVE (`/app/domains/{id}` + 5 siblings): `<twig:DomainWorkspaceTabs>` provides single cross-surface nav (TASK-031); legacy duplicated header buttons removed (TASK-041); DMARC policy explainer on detail page (TASK-037); Top Senders chart with labels + authorization colors (TASK-038); DNS Health overview cards clickable end-to-end (TASK-034). ✅
   - ADD: global "+ Add" dropdown in the layout-default top bar (TASK-033), plan-limit gated, hides "Invite teammate" for non-admin roles, pending invitations count toward seat cap. ✅
   - SYSTEM: sidebar regrouped into 5 labelled sections (Overview / Domains / Data / Ingestion / Settings) so system surfaces are quiet but reachable (TASK-039). ✅
4. **Feature depth — make value visible**: DMARC policy explainer (TASK-037), Top Senders chart enhancement (TASK-038), mailbox detail page with envelope stats (TASK-035), quarantine reason filter chips (TASK-036), 30-day pass-rate sparklines per domain (TASK-040). ✅

### Stop reason

**Backlog is genuinely empty.** Fresh Product-agent audit run on the four seed areas surfaced exactly one residual gap (TASK-041), which was shipped in this same continuation. The audit verdict was "nothing else found" — the four user paths feel coherent and the marketing surface holds together as a coherent narrative.

### Remaining launch-readiness items (NOT autonomous-run scope)

These are human-touch items the human owner needs to handle before launch, not CX defects:
1. **Real testimonials** — swap the 6 placeholder entries in `config/placeholders.php` for real customer quotes. The convention test enforces marker parity so a partial swap fails CI.
2. **Real founder photo** — set `founder_photo` in `config/placeholders.php` to a real URL. The integration tests `linkedinChipIsAbsentWhenConfigIsNull` and `initialsPlaceholderAvatarIsRenderedWhenFounderPhotoIsNull` will need to be updated when this happens (they document the pre-launch state).
3. **Real product screenshot** — TASK-027's HTML mock is intended to be swapped for an `<img>` once a real dashboard screenshot exists. Single `<div>` replacement, no other changes.
4. **GitHub-stats refresh cron line** — `sendvery:opensource:refresh-github-stats` was shipped in TASK-011 but the actual cron entry lives in `~/www/spare.srv/deployment/crontab` (outside this repo). Add `0 */6 * * * sentry-cli monitors run sendvery-github-stats -- docker compose run --rm worker bin/console sendvery:opensource:refresh-github-stats` next deploy. Until then the stats strip silently omits itself (by design — never renders fake numbers).
5. **Real OG brand logo** — `GdOgImagePainter` falls back to a wordmark text mark when `assets/images/og-logo.png` is absent. Drop a 240×60 transparent PNG there to upgrade every OG card with zero code change.

### Combined run stats (2026-05-24, this session + continuation)

**19 tasks shipped over two consecutive autonomous runs:** TASK-023 → TASK-041. 26+ commits to `main`. Suite grew from 1666 to 1890 tests (+224); ~1000 new assertions; ~3000+ lines of new test coverage; new Twig components, Twig globals, query classes, value enums, and one new dashboard route. 100% line coverage on every new file. Zero blocked tasks. Zero quality-gate skips.

The owner's two explicit seed asks — "make the marketing site look professional" + "give the dashboard real IA around the four paths" — are both substantially complete. The remaining launch work is content swaps the human owner controls.
