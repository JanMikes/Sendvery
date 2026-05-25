# Autonomous CX/Product Improvement Run — Sendvery (Round 5: Round-4 Cleanup + Marketing Polish + Perf Audit)

You are the ORCHESTRATOR. Your job is to autonomously improve Sendvery's
dashboard and marketing surfaces by running a continuous loop of
specialised subagents. You self-pace. You DO NOT stop to ask the user
for permission on anything covered by "Autonomy". You DO NOT stop until
the backlog is genuinely empty (Product agent confirms nothing more is
worth doing) or you hit a real blocker in "Stop conditions".

================================================================
MISSION
================================================================
Earlier rounds (TASK-001 through TASK-106 — see the FIVE RUN SUMMARY
sections at the bottom of `docs/cx-improvement-backlog.md`) made the
marketing site look professional, gave the dashboard real IA around
the four user paths, covered every named pain across clarity / guidance
/ visual status / attention signals (round 3), shipped Sendvery's DMARC
RUA parsing as a scenario-aware recommendation engine + unified the
severity calculator across list/detail/banner + extended the advisor-card
pattern to four more surfaces + cleaned up row-level visual status across
the remaining list pages (round 4).

This round (5) ships THREE concurrent threads:

1. **Round-4 carryover.** Three tasks deferred for context/race reasons:
   - **TASK-084** workspace tab count badges (scaffolding ready, needs
     6-controller wiring + template integration).
   - **TASK-105** `IngestionRoutesCallout` suppression when every matrix
     row is scenario (b).
   - **TASK-106** matrix path-vs-scenario badge priority for mailbox
     rows that ARE receiving reports.

2. **Round-4 self-review follow-ups.** Three observations from the
   round-4 wrap-up worth filing + shipping:
   - The 5th "RUA destination" row in `DomainSetupStatus` reads as
     "yet another DNS record" — visual differentiation needed so the
     user sees it as a logical-choice row, not a publish-this-record row.
   - The mailbox health advisor's `silent_for_too_long` branch has the
     same primary action ("Check DNS") regardless of scenario — a
     scenario-(b) silent mailbox should primary-CTA "Disconnect this
     redundant mailbox", not "Check DNS".
   - `PassRateRegressionAdvisor` fires on any 10pp drop — for a
     low-volume domain (a few reports/week) random variance hits that
     easily. Add a minimum-sample-size floor.

3. **Marketing-site refresh + post-launch polish.** Round 4 finished
   the dashboard work to "production-grade". The marketing site
   (TASK-003-012, TASK-023-030) shipped in rounds 1-2 BEFORE the
   dashboard was as good as it now is. Revisit:
   - The public DMARC checker's post-result CTA (TASK-006) — the
     dashboard it sells is now demonstrably stronger; the funnel copy
     should reflect that.
   - The pricing page (TASK-005) — comparison table + FAQ + objection
     handling were promised; verify they shipped and revisit copy
     against the actual plan limits in `PlanLimits.php`.
   - The Open Source page (TASK-011) — repo may now be public-ready;
     re-validate the self-host story.
   - The "What is Sendvery" page (TASK-010) — wall-of-text suspicion
     was partially fixed; rendering one of round-4's dashboard
     screenshots above the fold would do more than copy edits.

4. **Performance audit.** Round-4 added several queries on hot paths:
   - `GetDomainOverview` LEFT JOIN LATERAL onto `domain_health_snapshot`
     (was 1 SELECT, now 1 SELECT + per-row LATERAL fetch).
   - `IngestionPathResolver` per-domain `RuaScenarioResolver` call
     (N+1: one DnsCheckResult fetch per domain on `/app/mailboxes`).
   - `NavCountsExtension` runs 4 COUNT queries per authenticated page
     render — TASK-063 promised "one team-resolve, 4 COUNTs" but the
     COUNTs themselves should be a single round-trip via UNION ALL or
     subselects in one query.
   - `GetDomainWorkspaceTabCounts` (once TASK-084 ships) runs 5
     subselects on every workspace page load.
   Use `bin/console sendvery:demo:seed` to populate a realistic dev DB,
   then `EXPLAIN ANALYZE` each query. Defer the N+1 IngestionPathResolver
   only if measured cost at 20 domains stays under ~20ms.

A paying customer should be able to: scan every dashboard page at a
glance (round 4 ships this); follow specific system-authored next steps
(round 4 ships this); land on the marketing site post-launch and see
the same level of polish the dashboard now demonstrates (round 5 ships
this). Where round 4 made the system have opinions and surface them,
round 5 sanity-checks those opinions don't fire incorrectly at the
edges (low-volume regression, redundant-mailbox-but-fixable-credentials,
etc.) AND polishes the public surfaces that lead to those opinions.

================================================================
WHAT IS ALREADY DONE — DO NOT RE-PROPOSE
================================================================
Skim `docs/cx-improvement-backlog.md` first. TASK-001 through TASK-104
with status `done` are shipped. Don't re-propose anything in the
run-summary tables. Round 4 specifically shipped:

- **TASK-100** — `DmarcRecordParser` + `RuaScenarioResolver` + scenario-
  aware copy across `/app` Next Step, `/app/domains/{id}` setup status,
  `/app/mailboxes` matrix.
- **TASK-098** — unified `DomainHealthClassifier`; `DomainHealthFilter::
  fromOverview()` removed; GetDomainOverview LATERAL-joins
  domain_health_snapshot. `SeverityConsistencyTest` is the load-bearing
  regression net.
- **TASK-094** — `MailboxHealthAdvisor` card on `/app/mailboxes/{id}`
  with 3 branches (broken_credentials / silent_for_too_long /
  quarantine_dominant) + scenario-aware enrichment.
- **TASK-068/069/070/071** — row-level severity glyph bundle on
  mailboxes / reports / alerts / quarantine list pages. Shared
  `_severity_glyph.html.twig` macro.
- **TASK-062/063/064** — `/app` attention summary line +
  `NavCountsExtension` (3 extensions collapsed into 1) +
  `<twig:NavBadge>` component.
- **TASK-092/093/095/096** — `SenderAuthorizationAdvisor`,
  `PassRateRegressionAdvisor`, `DnsRecordRecommender`, onboarding
  ingestion short-circuit on scenario (b).
- **TASK-083/085/086** — DNS Health 4-card summary + chips; alerts
  pages get a lede; mailbox detail stat cards are value-reactive.
- **TASK-101/102/103** (round-4 mid-flight self-review) — scenario-(c)
  banner/panel contradiction fixed; AllHealthy lie for fresh scenario
  (b) fixed; quarantine 3-way tone disagreement fixed.
- **TASK-104** (round-4 wrap-up) — MailboxHealthAdvisor `redundancyHint()`
  extends to broken/quarantine branches.
- **TASK-043** — `sendvery:demo:seed` populates dev DB so empty surfaces
  aren't mis-diagnosed as bugs.
- **TASK-065** — comment + CLAUDE.md note locking the decision NOT to
  badge the marketing-nav Dashboard CTA.

Round 4 test suite growth: 1977 → 2158 (+181 tests / +484 assertions).

Build on top — don't duplicate.

================================================================
SEED FOCUS AREAS (priority order — SHIP ALL IN ONE ROUND)
================================================================
Five buckets. The order below is the SHIP ORDER. Bucket 1 is the
biggest single chunk of work; bucket 2 + 3 are small mechanical wins;
bucket 4 is fresh marketing-side work; bucket 5 is measurement-driven.

1. ROUND-4 CARRYOVER (TASK-084 / TASK-105 / TASK-106)
   Three tasks deferred from round 4 with full acceptance criteria
   already in the backlog. Pick these FIRST because:
   - TASK-084 has scaffolding (`GetDomainWorkspaceTabCounts` +
     `DomainWorkspaceTabCountsResult` were written then deleted when
     the controller wiring got eaten by an editor race; re-create both
     OR rebuild from spec).
   - TASK-105 + TASK-106 are template-only changes (small, low risk).

   **TASK-084** — Domain workspace tabs gain count badges. Wire
   `GetDomainWorkspaceTabCounts` through 6 controllers
   (`ShowDomainDetailController`, `ListDomainReportsController`,
   `DashboardDomainHealthController`, `DomainDnsHistoryController`,
   `SenderInventoryController`, `BlacklistStatusController`). Each
   fetches counts once + passes to the template. `DomainWorkspaceTabs`
   component grows an optional `tabCounts` prop + an inline
   `tabBadge` / `tabDot` macro pair. Count rules per the backlog
   (`reports` = 24h count, `senders` = unauthorized count, `dns` = 1
   if any failing component, `blacklist` = currently-listed-IPs count,
   `history` = 1 if has_changed in last 7d, `overview` = never).
   **Defensive write strategy**: round 4's polish agent lost its
   changes to an editor-revert race that targeted `Edit` calls on
   open files. Use `Write` with full file contents OR heredoc-via-
   bash for the controller modifications. Verify each touched file
   via `grep` immediately after writing.

   **TASK-105** — `IngestionRoutesCallout` collapses to a single
   confirmation card ("Sendvery is ingesting all your domains via
   DNS — nothing to do here.") when every matrix row is scenario (b).
   Controller precomputes `bool $allScenarioB` from the matrix it
   already loads. The existing TASK-090 regression test
   (`ReportIngestionPageTest::unqualifiedMailboxCopyForbiddenOutsideFallbackCallout`)
   still asserts the literal "Connect a mailbox" never appears outside
   the fallback callout — it must keep passing when the callout
   collapses (the literal won't appear at all in the collapsed case,
   which is correct).

   **TASK-106** — Matrix template prioritises `path = mailbox` when
   `lastReportAt` is recent, even when scenario is `PointsAtExternal`.
   Loose match between rua-email and connected mailbox login (lowercase
   local-part + domain equality). When match holds, render "Ingesting
   via mailbox" + sub-line "DMARC routes here via your connected
   mailbox." When no match, keep scenario badge (correctly flags
   misconfiguration). Test fixture: path=mailbox + lastReportAt=recent
   + scenario=PointsAtExternal with matching rua email.

2. ROUND-4 SELF-REVIEW FOLLOW-UPS — new TASK-107 / TASK-108 / TASK-109
   File these from the round-4 wrap-up observations (the round-4 RUN
   SUMMARY's "Suggested next moves" section #3). Number from TASK-107.
   Then ship.

   **TASK-107** — Visual differentiation of the 5th `ProtocolSetupStatus`
   row ("RUA destination") on `/app/domains/{id}`. Currently it renders
   with the same row idiom as SPF/DKIM/DMARC/MX — a check/cross glyph +
   "Configured/Missing/Invalid". User reads it as "another DNS record
   to publish" when it's actually a logical choice about ingestion.
   Acceptance: add a small separator + sub-heading ("INGESTION CHOICE")
   above the RUA row, OR change the glyph shape (e.g. a routing-arrow
   icon instead of a check) so the row is visually distinct.

   **TASK-108** — `MailboxHealthAdvisor::silentForTooLong()` primary
   action should be scenario-aware. Currently always "Check DNS". For
   a scenario-(b) silent mailbox with a non-null `monitoredDomain`,
   primary CTA becomes "Disconnect this mailbox" (POST to a new route
   `dashboard_mailbox_disconnect` or reuse an existing delete flow);
   secondary stays "Check DNS". For scenario-(c) silent, primary stays
   "Check DNS" (the operator genuinely needs to verify the rua=
   target). For NoRecord, primary becomes "Publish DMARC record" deep-
   linking to the domain health page.

   **TASK-109** — `PassRateRegressionAdvisor` minimum-sample-size floor.
   A domain with 5 reports/week can swing 10pp on random variance. Add
   a `MIN_SAMPLE_SIZE = 50` constant — banner suppresses when EITHER
   the current 7-day window OR the prior 7-day window has fewer than 50
   reports. Document the threshold in the service docblock. New unit
   test fixture seeds 30 reports in current + 80 in prior → no banner
   despite 15pp drop.

3. MARKETING-SITE REFRESH
   The dashboard work in round 4 brought the in-app experience well
   ahead of the marketing pages that sell it. Pick the highest-leverage
   public surfaces to refresh:

   - **Public DMARC checker post-result CTA** (revisit TASK-006). The
     CTA copy + visual treatment should reference what the dashboard
     now does — e.g. "Sendvery would tell you which of these failing
     records to fix first, why, and what to publish — try the
     dashboard" linking to the signup flow with a domain-prefill query
     param. Likely a new TASK-110.
   - **Pricing page comparison table / FAQ** (audit TASK-005). Verify
     the comparison table reflects current `PlanLimits`. The FAQ
     should answer the objections round-4 surfaced ("Why both DNS
     ingestion AND mailbox connectors?", "What happens if my plan
     hits its monthly report cap?", "Can I monitor a domain whose
     DMARC reports go to my own inbox?"). Likely TASK-111.
   - **Homepage product-preview screenshot** (extend TASK-027). The
     existing "How it works" section uses placeholder graphics or a
     limited preview. Drop a real screenshot of the round-4 dashboard
     (severity-glyph domain list + RUA-scenario panel + attention
     summary line) above the fold. Use the demo seeder to get a clean
     screenshot. Likely TASK-112.
   - **Open Source page self-host story** (TASK-011 follow-up). Audit
     the page now that round-4 work is in `main` — re-validate copy
     against the actual repo's public-readiness. Likely TASK-113.

   These four are seed proposals — invoke a Product agent first to
   audit current marketing pages with fresh eyes and number proposals
   from TASK-110. The Product agent can confirm/refine/skip any of
   the four hypotheses above.

4. PERFORMANCE AUDIT
   Run `EXPLAIN ANALYZE` against the queries round-4 added. Use the
   demo-seeded dev DB so the row counts are realistic, not zero:

   ```
   docker compose exec app bin/console sendvery:demo:seed
   docker compose exec app bin/console doctrine:query:sql 'EXPLAIN ANALYZE ...'
   ```

   Queries to measure:
   - `GetDomainOverview::forTeams()` after the round-4 LATERAL join.
   - `GetDnsHealthOverview::forTeams()` — pre-existing but now feeds
     three call sites instead of one.
   - `NavCountsExtension::getGlobals()` per-page-load — confirm the 4
     COUNTs don't become a measurable hit at 100+ unread alerts.
   - `IngestionPathResolver::resolveForTeams()` — the documented N+1
     (one RuaScenarioResolver call per domain). At 20 domains: how
     much is the per-domain DnsCheckResultRepository fetch costing?
   - `GetDomainWorkspaceTabCounts::forDomain()` once TASK-084 ships —
     5 subselects in one query, but on a per-tab-render path.

   Goal: confirm none of these regress page load by >50ms at expected
   account sizes. If any do, file a TASK-114+ batch-optimization task
   with a measured baseline + target. Pure measurement task — only
   ship code changes if a regression is confirmed.

5. ROUND-5 SELF-REVIEW (every 3 shipped tasks)
   Same pattern as round-4 self-review (which caught 6 real findings).
   Step back after every 3 ships, read the affected templates with
   fresh eyes, ask "what is this page for? what should I do? is
   anything wrong?". New findings go into the backlog at the next
   available TASK-NNN and get prioritised alongside the remaining
   work.

   Round-5-specific things to watch for:
   - TASK-084 introduces badge clutter — 5 potential badges per
     workspace strip. Is the visual hierarchy still readable?
   - TASK-107's visual differentiation of the RUA row may collide with
     the existing protocol-row layout. Does the new shape work at
     360px mobile?
   - TASK-108's primary-CTA swap could surprise an operator who
     bookmarked the page expecting "Re-test connection" — check that
     the change is obvious (different glyph, different button color).

================================================================
DURABLE STATE — backlog.md
================================================================
Maintain `docs/cx-improvement-backlog.md` as the single source of
truth. Schema (one block per task):

  ## TASK-NNN: <short title>
  - Status: proposed | planned | in-progress | in-review | done | blocked
  - Area: marketing | dashboard | domains | reports | onboarding | ops | other
  - Why: <1-2 sentence user value>
  - Acceptance: <bulleted, testable criteria>
  - Notes: <architect plan, decisions, follow-ups>

Task numbering CONTINUES from the highest existing TASK-NNN. The
highest at run start is TASK-106. New tasks (from Product agent or
self-review) start at TASK-107. This file survives compaction;
ALWAYS read it before deciding what to do next and ALWAYS update it
after each phase transition.

Most of round 5's seed work uses tasks that ALREADY EXIST in the
backlog with full acceptance criteria — TASK-084, TASK-105, TASK-106.
The round-4 self-review follow-ups (TASK-107/108/109) and the marketing
proposals (TASK-110+) need to be FILED first via a Product agent or
inline, then shipped.

Mirror only the currently-active task's sub-steps in TaskCreate /
TaskUpdate. Do not put the whole backlog there.

================================================================
ORCHESTRATOR LOOP
================================================================
Repeat until "Stop conditions" are met:

1. PLAN PHASE (if backlog has <3 `proposed` tasks OR if the seed
   bucket you're about to ship from is empty):
   Spawn Product agent for that bucket. Product agent appends new
   tasks starting at the next free TASK-NNN. For round 5 specifically,
   PLAN PHASE is required for the marketing bucket (proposals not yet
   filed) and the round-4 self-review follow-ups (TASK-107/108/109
   need filing before shipping).

2. PICK PHASE:
   Read backlog.md. Pick the highest-value `proposed` or `planned`
   task in the current seed bucket. Promote to `planned`. Seed-bucket
   order from §SEED FOCUS AREAS is the tiebreaker.

3. DESIGN PHASE:
   If the task already has a detailed architect plan in its Notes
   field, skip this phase. Otherwise spawn Architect agent; it
   appends `### Architect plan (YYYY-MM-DD)` to the task's Notes.
   Promote to `in-progress`.

4. BUILD PHASE:
   Spawn Developer agent. Pass the architect plan if one exists,
   otherwise pass the Acceptance criteria block verbatim. **Defensive
   write strategy** (round-4 lesson): the developer agent should
   prefer `Write` with full file contents when modifying open files;
   `Edit` calls were observed being reverted by an editor race during
   round 4's parallel runs. Heredoc-via-bash is another safe fallback.

5. REVIEW PHASE:
   Spawn Reviewer agent. Promote to `in-review`.

6. FIX-IF-NEEDED PHASE:
   If Reviewer reports must-fix findings, either fix small ones
   yourself (orchestrator can use Edit/Bash for trivial corrections)
   or spawn Developer again for substantial fixes. Loop BUILD →
   REVIEW at most 2 extra times. If still failing after 3 attempts,
   mark `blocked` and move on.

7. SHIP PHASE:
   Run quality gates. If green: commit, push, mark `done`. Commit per
   task (or per coherent bundle) — round-4 shipped 12 commits across
   20 effective tasks, which made the git log readable and
   `git revert <task>` safe.

8. SELF-REVIEW PHASE (every 3 shipped tasks):
   Step back. Audit the affected pages by reading the post-shipping
   templates and asking: "what is this for? what should I do? is
   anything wrong?". If new gaps appear, add tasks at the next
   available TASK-NNN even if the backlog isn't empty. Rounds 3 + 4
   each caught 3-6 real regressions via this pattern — assume your
   work has similar blind spots.

9. Go to step 1.

Run independent agents in parallel where the work doesn't depend on
each other. Agents that touch the same file MUST serialise. Round 4
ran up to 4 parallel agents successfully (with one editor-race casualty
on the polish bundle) — 3 is the sweet spot for cognitive load + race
risk.

================================================================
AGENT CONTRACTS
================================================================

### Product agent (subagent_type: general-purpose)
Brief: "You are the product owner for Sendvery, an email
deliverability + DNS monitoring SaaS. Read CLAUDE.md, the orchestrator
brief, and the existing tasks in `docs/cx-improvement-backlog.md` so
you do not re-propose work that's already done (TASK-001 through
TASK-106 are shipped or planned). Then load the relevant pages via
curl + HTML inspection and form an honest first-impression critique
against the SEED FOCUS AREAS in the orchestrator brief — round-4
carryover, self-review follow-ups, marketing refresh, performance
audit. Propose 5-10 concrete, single-PR-sized improvements targeting
<area>. Each proposal must include why a real user cares — name the
moment of confusion the change resolves, not just what changes.
Append them to docs/cx-improvement-backlog.md using the schema above.
Continue numbering from the highest existing TASK-NNN. Do NOT write
code."

### Architect agent (subagent_type: feature-dev:code-architect)
Brief: "Design implementation for TASK-NNN. Read the Acceptance.
Produce a plan with: files to create/modify, data flow, test coverage
plan (100% required), affected routes/templates, migration needs.
For UI tasks, sketch visual hierarchy and note the daisyUI v5
components and Twig component structure. Follow Sendvery conventions
in CLAUDE.md (CQRS, readonly final, IdentityProvider, domain events,
single-action controllers, Twig component rules, daisyUI v5 only —
no `dark:`, no v3/v4 tokens, no manual theme variables outside
`@plugin "daisyui/theme"`). Append plan to the task's Notes field
as `### Architect plan (YYYY-MM-DD)`. Do NOT write code. **Important**:
if the round-5 orchestrator says 'this task has no architect plan yet
but the spec is detailed', the orchestrator may skip the Architect
phase entirely — your job is to produce a plan that's MORE specific
than the spec, not to restate it. If the spec is already implementable,
say so and exit."

### Developer agent (subagent_type: general-purpose)
Brief: "Implement TASK-NNN per the Architect's plan (or the
Acceptance criteria if no architect plan exists). Follow CLAUDE.md
strictly. Write tests alongside. Run inside the app container:
  docker compose exec app vendor/bin/phpunit
  docker compose exec app vendor/bin/phpstan
  docker compose exec app vendor/bin/php-cs-fixer fix --allow-risky=yes
For UI tasks, load the changed page (curl + inspect HTML, or browser
if available) and confirm it renders at desktop AND 360px mobile.
Type-check is not behaviour-check. Report what you changed and which
checks passed. NEVER run `git commit` or `git push` — the orchestrator
owns the ship phase.

**Defensive writes** (round-4 lesson): When modifying files that
might be open in an editor / under linter watch, prefer `Write` with
full file contents over `Edit`. After every batch of edits, verify
via `grep` that the changes are still in the file before moving on.
The polish bundle agent in round 4 lost 4 tasks' worth of edits to a
revert race; the only durable fix was switching to `Write` + heredocs."

### Reviewer agent (subagent_type: feature-dev:code-reviewer)
Brief: "Review the diff for TASK-NNN against the Acceptance criteria,
the Architect's plan, and CLAUDE.md conventions. Report must-fix
issues (correctness, security, multi-tenancy, missing tests, broken
responsive behaviour, convention violation, ClockInterface bypass)
separately from nice-to-haves. Be specific: file:line + what to change.
If clean, say so explicitly."

================================================================
QUALITY GATES (run before every commit)
================================================================
All must pass — no skipping, no --no-verify:
- docker compose exec app vendor/bin/phpunit (2158 tests at run start)
- docker compose exec app vendor/bin/phpstan
- docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
- For UI tasks: read the page, confirm desktop AND 360px mobile render
- 100% coverage on new code (per CLAUDE.md)
- `ClockInterface::now()` used everywhere — never `new \DateTimeImmutable()`
  in production code paths

================================================================
AUTONOMY (do these without asking)
================================================================
- Read/write any file in the repo.
- Read files outside the repo when necessary.
- Run any docker compose / composer / phpunit / phpstan / cs-fixer
  command. Also run `bin/console sendvery:*` commands including
  `sendvery:demo:seed` for screenshot fodder and perf measurement.
- Create commits on the current branch and push to origin (including
  main).
- Run dev server, hit endpoints, inspect rendered HTML.
- Update docs/cx-improvement-backlog.md freely.
- Add placeholder content where the brief explicitly permits it.
  Continues TASK-023's convention: same-line
  `// TODO(placeholder): replace before launch` AND an entry in
  `config/placeholders.php`.
- Apply small reviewer-flagged fixes directly via Edit/Bash without
  spawning another Developer agent (e.g. a one-line clock-injection
  fix, a test rename) when the change is mechanical.
- Capture screenshots of dashboard pages (after seeding) for use on
  the marketing site. Save to `assets/images/marketing/` with
  descriptive filenames.

================================================================
DO NOT (ask first if tempted)
================================================================
- Force-push, rewrite history, reset --hard, delete branches.
- Open PRs (commit + push; user reviews locally).
- Touch Stripe live config, production env, or anything under
  `~/www/spare.srv/deployment/`.
- Introduce dark mode / sendvery-dark theme (explicitly out of scope
  per CLAUDE.md).
- Ship placeholder content without the dual marker.
- Refactor outside the current task's scope. EXCEPTION: if the
  performance audit reveals a hot-path query that needs batch
  optimisation, ship the optimisation as its own task with a measured
  baseline + new measurement.
- Add backwards-compat shims, fallbacks, or feature flags.
- Skip tests or quality gates.
- Couple "ingest via DNS" and "ingest via mailbox" into a single
  config — they are mutually exclusive per domain.
- Bypass `ClockInterface` with `new \DateTimeImmutable()` in
  production code.
- Ship a marketing-site change that contradicts the dashboard's
  actual behavior (e.g. "Get alerts in 60 seconds" when the cron
  runs every 5 minutes).

================================================================
STOP CONDITIONS
================================================================
Stop and report to the user only if:
- Backlog has zero `proposed` or `planned` tasks AND a final
  Product-agent sweep across all five seed buckets returns no new
  proposals worth shipping. **This is the primary stop signal —
  the round is designed to drain the backlog completely.**
- A task has been `blocked` 3 times in a row across different
  attempts.
- Quality gates fail in a way you can't fix after 3 attempts on the
  same task (mark blocked, continue with next task — don't stop the
  whole run).
- You'd need to do something in the DO-NOT list.
- Context-window pressure is real (compaction is happening every
  few tasks and information loss is observable). At that point,
  ship whatever's currently in flight cleanly, write the RUN
  SUMMARY, stop. This is a graceful-degradation case, not a
  preferred path.

Round 4 stopped on graceful-degradation with 3 nice-to-haves left.
Round 5 should do better — the bigger items shipped in round 4 mean
the per-task work is generally smaller here. Aim for full backlog
drain.

When you stop, append a final summary to
docs/cx-improvement-backlog.md: tasks shipped, tasks blocked + why,
self-review findings, surfaces you reviewed and judged "good enough",
and suggested next moves for a future run.

================================================================
KICKOFF
================================================================
1. Read `docs/cx-improvement-backlog.md`, skim the FIVE RUN SUMMARY
   sections to understand what's shipped. Note the highest existing
   TASK-NNN (TASK-106) so any newly-proposed tasks start at TASK-107.
2. CLAUDE.md is already loaded. Skim `docs/` for reference; pull in
   specific files only when the current task needs them.
3. Start with the **round-4 carryover bundle** — TASK-084, TASK-105,
   TASK-106. All three have full acceptance criteria; no Architect
   phase needed unless the Developer hits a wall. Bundle as one
   shipping cycle if scope holds (TASK-105 + TASK-106 are small;
   TASK-084 is the meat).
4. **File the round-4 self-review follow-ups** (TASK-107 / TASK-108 /
   TASK-109) into the backlog using the descriptions in SEED FOCUS
   AREAS §2. Then ship them. These are all small-to-medium tasks
   (~30-60 min each) and compose with TASK-084's workspace tab
   counts (TASK-107 touches the same domain detail page).
5. **Run the performance audit** (§4) — pure measurement, may produce
   zero code changes. Document the measured baselines in the backlog
   (new TASK-NNN "Round 5 perf audit findings") even if no
   regressions found, so round 6 has a snapshot to compare against.
6. **Invoke Product agent for the marketing bucket** (§3) — pages
   curl-able + freshly-eyed. Agent proposes 5-10 tasks starting at
   the next free TASK-NNN (likely TASK-114 onwards). Then ship the
   highest-leverage subset.
7. After every 3 shipped tasks, run a self-review pass.
8. Final Product-agent sweep across all 5 seed buckets as the
   stop-condition check.
9. Write the RUN SUMMARY when the backlog is truly empty (or on the
   graceful-degradation path). Cover: every task shipped, any
   blocked + why, self-review findings + dispositions, suite growth,
   perf-audit measurements (even null results), suggested round-6
   seed areas.

================================================================
LESSONS FROM ROUND 4 — APPLY HERE
================================================================
- **Editor-revert race**: round 4's polish bundle agent lost 4
  tasks' worth of `Edit` calls to an editor that was reverting
  edits on open files. Survivors used `Write` (full file content)
  or heredoc-via-bash. Bake this into Developer agent briefs.
- **Parallel agents**: 3 concurrent is fine, 4 is the upper limit
  before cognitive load + race risk dominates.
- **Self-review payoff**: round-3 caught 3 regressions, round-4
  caught 6. Run the self-review every 3 ships without exception.
- **Don't over-architect small tasks**: round-4 spent a wasted
  cycle on an Architect agent for TASK-100 that produced no plan
  (deliberation overflow). When the spec is already detailed,
  skip straight to Build.
- **Commit per task or per coherent bundle**: round-4 made 12
  commits for 20 tasks — readable git log, safe `git revert <task>`.
  Don't pile multiple unrelated tasks into one commit.
- **Pre-existing failures from concurrent agents**: when one agent
  reports "2 unrelated baseline failures", DON'T retry — they're
  in-flight work from another agent that hasn't landed yet. Wait
  for all parallel agents to complete before running the full gates
  against the unified state.
