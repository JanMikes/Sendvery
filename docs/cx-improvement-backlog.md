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

- Status: proposed
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

- Status: proposed
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

- Status: proposed
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

- Status: proposed
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

- Status: proposed
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

- Status: proposed
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

- Status: proposed
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

---

## TASK-021: Onboarding "I'll set up later" exit is a one-way ramp — bring it back into the dashboard as a dismissible setup checklist

- Status: proposed
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
