# Autonomous CX/Product Improvement Run — Sendvery (Round 4: Consistency, Guidance Depth & Polish)

You are the ORCHESTRATOR. Your job is to autonomously improve Sendvery's
dashboard and marketing surfaces by running a continuous loop of
specialised subagents. You self-pace. You DO NOT stop to ask the user
for permission on anything covered by "Autonomy". You DO NOT stop until
the backlog is genuinely empty (Product agent confirms nothing more is
worth doing) or you hit a real blocker in "Stop conditions".

================================================================
MISSION
================================================================
Earlier rounds (TASK-001 through TASK-099 — see the four RUN SUMMARY
sections at the bottom of `docs/cx-improvement-backlog.md`) made the
marketing site look professional, gave the dashboard real IA around
the four user paths, and (in round 3) covered every named pain across
clarity, guidance, visual status, attention signals, and the
production-affecting Domain Health snapshot writer.

This round ships the remaining backlog in one continuous run. The
goal is `proposed` count → 0 by end of round. ~20 fully-specified
tasks plus the new TASK-100 DMARC RUA auto-detection feature. The
orchestrator self-paces, runs independent work in parallel, and
keeps going until the backlog is genuinely empty. There is no
artificial cycle cap — round 3 stopped at 11 cycles for in-context
fatigue reasons that don't apply when the next round starts fresh;
the right stop signal is "backlog empty" plus a final self-review
that returns no new findings worth shipping.

Work breakdown:

- **Consistency across surfaces.** Round 3 shipped two independent
  severity calculators — `DomainHealthFilter::fromOverview()` (list
  page) and `DomainSetupStatusResolver` (detail page). The same domain
  can render green on `/app/domains` and yellow on `/app/domains/{id}`.
  The self-review caught this; the fix was scoped out of round 3.
- **Guidance depth.** Round 3 shipped two top-level "what to do next"
  advisors (DMARC policy explainer, Next Step card). The pattern
  generalises to several more surfaces (mailbox health, sender
  authorize/revoke, pass-rate regression, literal DNS-record copy).
- **Row-level visual status.** Round 3 nailed the domain list and
  domain detail. Mailbox / reports / alerts / quarantine rows still
  have no leading severity indicator.
- **Polish + cleanup.** Per-page clarity sweeps, navigation refactors
  to collapse the three count extensions into one, and a local-dev
  demo seeder so future autonomous runs stop mis-diagnosing empty
  surfaces as bugs.

A paying customer should be able to:
- Open any dashboard surface and have severity / status answered
  identically on the list view, the detail view, and any nav badge.
- Get a system-authored "next step" wherever an obvious answer exists,
  not just on the two surfaces that shipped one in round 3.
- Scan a list of mailboxes, reports, alerts, or quarantined items and
  see at a glance which rows need action — same idiom as the domain
  list now uses.
- See the same sidebar attention signals without four small COUNT
  queries firing on every authenticated page render.

Where the system has an opinion, surface it consistently. Where it
doesn't, get out of the user's way. Each loop iteration, identify the
single highest-leverage gap, design it, build it, verify it, ship it,
then look again with fresh eyes.

You are NOT working from a fixed task list. The backlog already
contains ~20 deferred items from round 3 plus three regressions the
self-review uncovered. Prioritise the seed areas below first, pick the
relevant existing tasks from the backlog (they already have full
acceptance criteria), and only invoke a Product agent to propose new
tasks when an area genuinely runs out.

================================================================
WHAT IS ALREADY DONE — DO NOT RE-PROPOSE
================================================================
Skim `docs/cx-improvement-backlog.md` first. TASK-001 through
TASK-099 with status `done` are shipped. Don't re-propose anything
in the run-summary tables. Round 3 specifically shipped:

- **TASK-042 + 044** — `DomainHealthSnapshot` writer (P0 prod fix).
  Health tab now populates after each `sendvery:dns:check-all` run.
- **TASK-066** — leading severity glyph + `border-l-4` on domain
  list cards, driven by `DomainHealthFilter::fromOverview()`.
- **TASK-067 + 080** — one-line status banner + per-protocol setup
  panel on `/app/domains/{id}`, both consuming a `DomainSetupStatus`
  DTO with a `displayMode` discriminator (TASK-097).
- **TASK-090** — `/app/mailboxes` reframed as DNS-first "Report
  ingestion" with two-card recommended/fallback callout + per-domain
  ingestion matrix.
- **TASK-091** — `/app` Next Step card branches to "Publish RUA
  record" first; mailbox becomes a fallback that fires after 7 days
  or explicit dismissal. New `team.ingestion_recommendation_dismissed_at`.
- **TASK-060 + 061** — Alerts + Domains sidebar count badges
  (Quarantine had one already from TASK-020). Three-badge cap.
- **TASK-081** — DNS History page lede + three filter chip groups +
  per-day `<details>` collapsibles.
- **TASK-082** — canonical `{% block page_heading %}` across all
  dashboard pages; sweep test asserts exactly one H1 per page.
- **TASK-097 + 099** — fixed banner/panel contradictions on the
  domain detail page (display mode discriminator + null-DMARC guard
  on the policy explainer).

Build on top — don't duplicate.

================================================================
SEED FOCUS AREAS (priority order — SHIP ALL IN ONE ROUND)
================================================================
Seven buckets plus the new TASK-100 product feature. All seven are
in scope for this round; the order below is the SHIP ORDER (which
bucket gets built first when work is being serialised). Earlier
buckets unblock or compose with later ones, so ordering matters
even when total scope is fixed.

1. DMARC RUA AUTO-DETECTION — turn Sendvery's core parsing into the
   driver of every "what should I do?" recommendation. **TASK-100**
   in the backlog.
   This is one of Sendvery's defining product features and round 4's
   leading work. Today the dashboard asks the user "have you set up
   DMARC?" via a binary verified/unverified flag. But Sendvery
   already parses the literal `_dmarc.<domain>` TXT record on every
   DNS check — we know exactly what `rua=mailto:` destination the
   record points at, if any. That single piece of information drives
   three completely different best-next-actions per domain:

   a. **No DMARC record at all** → user must publish one. Offer two
      paths in order of preference: (i) point `rua` at our central
      inbox (`reports@sendvery.com`) — recommended because we parse
      reports directly and the user never sets up an inbox
      connection; (ii) point `rua` at the user's own email and then
      connect that inbox to Sendvery via the mailbox flow. Either
      path, the DMARC record itself is a hard prerequisite — the
      copy must convey that publishing the record is mandatory and
      the choice is only about WHERE the reports land.

   b. **DMARC published, RUA points at a Sendvery address** → all
      good. Surface this as the positive resolved state (green
      check, "Sendvery is parsing your reports directly — no
      mailbox connection needed"). Suppress mailbox-flow nudges
      entirely for this domain.

   c. **DMARC published, RUA points at a non-Sendvery email** →
      two equivalent paths: (i) connect that mailbox to Sendvery so
      we can poll it (preferred if the user already has the inbox
      set up and doesn't want to touch DNS again); (ii) change the
      DMARC RUA to point at `reports@sendvery.com` (simpler — one
      DNS change, no credentials shared). Show both with clear
      tradeoffs; don't push hard either way.

   This cascades into multiple surfaces:
   - The `/app` Next Step card (TASK-091's `PublishRuaRecord` branch)
     becomes scenario-aware — currently it assumes scenario (a)
     unconditionally.
   - The `/app/domains/{id}` setup-status panel (TASK-080) gains a
     fifth "RUA destination" row alongside SPF/DKIM/DMARC/MX, with
     copy keyed off scenario (a/b/c).
   - The `/app/mailboxes` ingestion matrix (TASK-090) reads RUA
     scenario per domain to label "Connect a mailbox" rows as
     "Connect this mailbox to ingest reports from {rua_email}" vs
     "Already ingesting via DNS" vs "DMARC record missing — fix
     that first".
   - Onboarding's ingestion step opens with "Let's check your
     DMARC record" and routes the user to whichever scenario flow
     applies based on the live DNS lookup result.

   The data is already there — `DnsCheckResult.rawRecord` for the
   DMARC type contains the literal TXT value, and a tiny parser can
   extract `rua=mailto:...`. The work is mostly: (1) a small parser
   service to extract + classify the RUA destination, (2) a
   `RuaScenarioResolver` returning one of three states per domain,
   (3) threading the scenario into the three existing surfaces, (4)
   making the copy + CTAs scenario-aware. No new data sources, no
   new entity columns.

   **Why this leads the round**: it's a higher-leverage product
   recommendation than anything else in the deferred list because
   it uses Sendvery's own competence (DMARC parsing) to give the
   user information no other product surface can. Every "what
   should I do?" moment becomes specific — "based on YOUR DMARC
   record, here's the exact next step" — instead of generic. It's
   the canonical "where the system has an opinion, surface it"
   moment from the original brief.

2. SEVERITY CONSISTENCY ACROSS SURFACES
   The single most important consistency fix in scope this round. `DomainHealthFilter::fromOverview`
   reads (DMARC verified flag, 30-day pass rate); `DomainSetupStatusResolver`
   reads four per-protocol DNS states. They classify the same domain
   differently. A domain with DMARC verified + SPF missing + 95% pass
   rate renders green on the list and yellow on the detail. The
   shipped patch is **TASK-098** in the backlog — full acceptance
   criteria already written. Architect plan: unify into a single
   `DomainHealthClassifier` service that consumes BOTH `DomainOverviewResult`
   AND the per-domain `DnsHealthOverviewResult` (LEFT JOIN LATERAL onto
   `domain_health_snapshot`). Same classifier feeds the list-card glyph,
   the detail-banner severity, AND the `/app` HealthSummary banner.
   Refactor risk is real — touch all three surfaces and lock the
   invariant with a test that proves list-severity == detail-severity
   for any combination of inputs.

3. ROW-LEVEL VISUAL STATUS (TASK-068 / 069 / 070 / 071)    Mechanical extension of TASK-066's idiom (40px leading glyph +
   `border-l-4 border-l-{tone}` on the row). Apply to:
   - `/app/mailboxes` rows (driven by `isActive` + `lastError`)
   - `/app/reports` + Recent Reports table (pass-rate thresholds
     90/70, via a shared `_severity_glyph.html.twig` macro)
   - `/app/alerts` rows (add tinted `bg-{tone}/5` row background +
     leading dot; replace the redundant "New" badge)
   - `/app/quarantine` rows (glyph keyed off
     `QuarantineReason::severityTone()` — new method)
   Each is a single-PR scope; total bundle is ~30 min × 4 = ~2h.
   Bundle as one shipping cycle if scope holds, otherwise ship in
   two pairs.

4. GUIDANCE DEPTH — generalise the DmarcPolicyAdvisor pattern (round-3 deferred)
   - **TASK-094** Mailbox detail advisor. Three branches:
     broken_credentials, silent_for_7d (composes with TASK-091's
     same 7-day clock anchor — natural pairing), quarantine_dominant.
     Silent-for-7d nudges back to DNS as the recommended path.
   - **TASK-092** Sender Inventory authorize/revoke recommendation.
     Four severity branches (recommend_authorize, recommend_revoke,
     monitor, none).
   - **TASK-093** Reports list pass-rate-regression banner ("pass
     rate dropped + top failing sender behind it").
   - **TASK-095** DNS Health page literal record-to-publish copy
     (generalises the existing `DnsRecordInstruction` pattern across
     SPF/DKIM/MX, not just DMARC).
   - **TASK-096** Onboarding ingestion step DNS-first reordering
     with a DOM-order regression test.
   All five tasks already have full acceptance criteria in the
   backlog. Ship the highest-leverage subset; TASK-094 first because
   it pairs cleanly with TASK-091's clock work.

5. NAVIGATION REFACTOR (TASK-062 / 063 / 064 / 065)    - **TASK-062** `/app` hero "N things need your attention today"
     opening line. New `AttentionSummaryResolver` reading the three
     existing count globals. Architectural decision per the backlog:
     hero is the single home — not a toast, not a fourth banner.
   - **TASK-063** Collapse `QuarantineCountExtension`,
     `AlertCountExtension`, `DomainHealthCountExtension` into one
     `NavCountsExtension`. Four sequential COUNT queries become one
     team-resolve + four COUNTs. Templates' API stays identical.
   - **TASK-064** Extract `<twig:NavBadge />` component now that
     there are three call sites — before there are six.
   - **TASK-065** Record the deliberate decision NOT to mirror
     sidebar badges onto the marketing-site Dashboard CTA.
   TASK-063 must land AFTER TASK-062 (which needs the counts), AFTER
   the round-3 badges shipped (they did), and ideally bundled with
   TASK-064 (the component extraction).

6. CLARITY POLISH (TASK-083 / 084 / 085 / 086)    - **TASK-083** DNS Health overview gains 4-card summary stat row
     + `?status=` filter chips.
   - **TASK-084** Domain workspace tabs gain count badges (Reports,
     Senders, DNS, Blacklist, History) via a new
     `GetDomainWorkspaceTabCounts` query. Composes with TASK-064's
     `<twig:NavBadge>` if both ship together.
   - **TASK-085** One-sentence lede on `/app/alerts` and
     `/app/alerts/{id}` explaining what an alert IS.
   - **TASK-086** Mailbox detail stat cards become value-reactive
     (a silent mailbox today shows all-green cards because
     `Reports parsed=0` is styled `success` — actively misleading).

7. LOCAL-DEV BOOTSTRAP (TASK-043)    `bin/console sendvery:demo:seed` — idempotent dev-env-only seeder
   producing 3 domains in varying health states, 30 days of synthetic
   reports, 5 alerts across the four `AlertType` cases, 30 daily
   health snapshots. Refuses to run in `prod`. Documents the command
   so future autonomous runs stop mis-diagnosing empty surfaces as
   bugs. Lower priority because it doesn't affect paying customers,
   but high leverage for future runs and human dev onboarding.

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
highest at run start is TASK-099. New tasks (from Product agent or
self-review) start at TASK-100. This file survives compaction;
ALWAYS read it before deciding what to do next and ALWAYS update it
after each phase transition.

Most of round 4's work uses tasks that ALREADY EXIST in the backlog
with full acceptance criteria — read each task block before invoking
an Architect (you may find the plan is already detailed enough to go
straight to Build for the smaller ones).

Mirror only the currently-active task's sub-steps in TaskCreate /
TaskUpdate. Do not put the whole backlog there.

================================================================
ORCHESTRATOR LOOP
================================================================
Repeat until "Stop conditions" are met:

1. PLAN PHASE (if backlog has <3 `proposed` tasks OR if the seed
   bucket you're about to ship from is empty):
   Spawn Product agent for that bucket. Product agent appends new
   tasks starting at TASK-100. Most rounds won't need this — the
   backlog already has ~20 deferred items with full acceptance
   criteria.

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
   otherwise pass the Acceptance criteria block verbatim.

5. REVIEW PHASE:
   Spawn Reviewer agent. Promote to `in-review`.

6. FIX-IF-NEEDED PHASE:
   If Reviewer reports must-fix findings, either fix small ones
   yourself (orchestrator can use Edit/Bash for trivial corrections)
   or spawn Developer again for substantial fixes. Loop BUILD →
   REVIEW at most 2 extra times. If still failing after 3 attempts,
   mark `blocked` and move on.

7. SHIP PHASE:
   Run quality gates. If green: commit, push, mark `done`.

8. SELF-REVIEW PHASE (every 3 shipped tasks):
   Step back. Audit the affected pages by reading the post-shipping
   templates and asking: "what is this for? what should I do? is
   anything wrong?". If new gaps appear, add tasks at TASK-100+
   even if the backlog isn't empty. Round 3's self-review caught
   three real regressions (TASK-097, 098, 099) — assume your work
   has similar blind spots.

9. Go to step 1.

Run independent agents in parallel where the work doesn't depend on
each other. Agents that touch the same file MUST serialise.

================================================================
AGENT CONTRACTS
================================================================

### Product agent (subagent_type: general-purpose)
Brief: "You are the product owner for Sendvery, an email
deliverability + DNS monitoring SaaS. Read CLAUDE.md, the orchestrator
brief, and the existing tasks in `docs/cx-improvement-backlog.md` so
you do not re-propose work that's already done (TASK-001 through
TASK-099 are shipped or planned). Then load the relevant pages
via curl + HTML inspection and form an honest first-impression
critique against the SEED FOCUS AREAS in the orchestrator brief —
severity consistency, row-level visual status, guidance depth,
navigation refactor, clarity polish. Propose 5–10 concrete,
single-PR-sized improvements targeting <area>. Each proposal must
include why a real user cares — name the moment of confusion the
change resolves, not just what changes. Append them to
docs/cx-improvement-backlog.md using the schema above. Continue
numbering from TASK-100. Do NOT write code."

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
as `### Architect plan (YYYY-MM-DD)`. Do NOT write code."

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
owns the ship phase."

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
- docker compose exec app vendor/bin/phpunit (1977 tests at run start)
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
  command. Also run `bin/console sendvery:*` commands.
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

================================================================
DO NOT (ask first if tempted)
================================================================
- Force-push, rewrite history, reset --hard, delete branches.
- Open PRs (commit + push; user reviews locally).
- Touch Stripe live config, production env, or anything under
  `~/www/spare.srv/deployment/` (round 3 verified the crontab is
  correct; no deployment changes needed for round 4).
- Introduce dark mode / sendvery-dark theme (explicitly out of scope
  per CLAUDE.md).
- Ship placeholder content without the dual marker.
- Refactor outside the current task's scope. EXCEPTION: TASK-098's
  severity unification IS a refactor task — its scope explicitly
  includes touching the list-card glyph, detail-banner severity, and
  `/app` HealthSummary banner together.
- Add backwards-compat shims, fallbacks, or feature flags.
- Skip tests or quality gates.
- Couple "ingest via DNS" and "ingest via mailbox" into a single
  config — they are mutually exclusive per domain (TASK-090's
  regression-net trait enforces this; trust it).
- Bypass `ClockInterface` with `new \DateTimeImmutable()` in
  production code. Round 3's reviewer caught one such slip; don't
  let another one ship.

================================================================
STOP CONDITIONS
================================================================
Stop and report to the user only if:
- Backlog has zero `proposed` or `planned` tasks AND a final
  Product-agent sweep across all seven seed buckets returns no new
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

There is NO artificial "after N cycles" stop. Round 3 stopped at 11
cycles for context-fatigue reasons that don't apply ahead of time —
this round runs until the backlog is empty.

When you stop, append a final summary to
docs/cx-improvement-backlog.md: tasks shipped, tasks blocked + why,
self-review findings, surfaces you reviewed and judged "good enough",
and suggested next moves for a future run.

================================================================
KICKOFF
================================================================
1. Read `docs/cx-improvement-backlog.md`, skim the four RUN SUMMARY
   sections to understand what's shipped. Note the highest existing
   TASK-NNN (TASK-099) so any newly-proposed tasks start at TASK-100.
2. CLAUDE.md is already loaded. Skim `docs/` for reference; pull in
   specific files only when the current task needs them.
3. Start with **TASK-100 (DMARC RUA auto-detection)** — Sendvery's
   defining product feature applied as a recommendation engine. The
   acceptance criteria are already in the backlog. Spawn an
   Architect to produce the implementation plan covering the small
   RUA parser + `RuaScenarioResolver` + the three surfaces it
   feeds (`/app` Next Step, `/app/domains/{id}` setup status,
   `/app/mailboxes` matrix). Build + Review + Ship as one big
   coherent change — splitting it across multiple commits creates
   intermediate states where one surface uses scenario-aware copy
   and another doesn't.
4. In parallel with TASK-100, spawn an Architect for **TASK-098
   (severity unification)** — the round-3 self-review's #2 finding,
   refactor of two severity calculators into one. The two tasks
   touch overlapping surfaces (`/app/domains/{id}` is in both
   diffs) so the Build phases must serialise even if Architects
   run concurrently.
5. After TASK-100 + TASK-098 ship, **spawn TASK-094 (Mailbox health
   advisor)** — it composes cleanly with TASK-091's 7-day clock
   AND with TASK-100's RUA scenario detection (a mailbox is only
   the "fallback" recommendation when scenario c applies).
6. Then enter the orchestrator loop and work through the remaining
   seed buckets in order: row-level glyphs (TASK-068-071, bundle as
   one ship cycle if scope holds), then the remaining guidance
   advisors (TASK-092/093/095/096), then the nav refactor
   (TASK-062/063/064/065 must serialise; 063 lands AFTER 062),
   then clarity polish (TASK-083/084/085/086), then the demo seeder
   (TASK-043).
7. After every 3 shipped tasks, run a self-review pass (the
   round-3 prompt's pattern caught 3 real regressions). New
   findings go into the backlog at TASK-101+ and get prioritised
   alongside the remaining work.
8. Run a Product-agent audit only when a seed bucket genuinely
   empties (most won't — the backlog already carries ~20
   fully-specified items plus TASK-100). At final round-end,
   run one Product agent per bucket as the stop-condition check
   ("no new proposals worth shipping").
9. Write the RUN SUMMARY only when the backlog is truly empty (or
   on the graceful-degradation path). Cover: every task shipped,
   any blocked + why, self-review findings + dispositions, suite
   growth, suggested next-round seed areas (likely "round 5 is
   marketing-site refresh + post-launch polish" if all dashboard
   work is done).
