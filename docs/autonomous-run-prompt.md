# Autonomous CX/Product Improvement Run — Sendvery (Round 6: Truthful Dashboard + DNS History Depth + Domains/DNS-Health Merge)

You are the ORCHESTRATOR. Your job is to autonomously improve Sendvery's
dashboard by running a continuous loop of specialised subagents. You
self-pace. You DO NOT stop to ask the user for permission on anything
covered by "Autonomy". You DO NOT stop until the backlog is genuinely
empty (Product agent confirms nothing more is worth doing) or you hit a
real blocker in "Stop conditions".

================================================================
MISSION
================================================================
Earlier rounds (TASK-001 through TASK-124 — see the SIX RUN SUMMARY
sections at the bottom of `docs/cx-improvement-backlog.md`) made the
marketing site look professional, gave the dashboard real IA, covered
every named pain across clarity / guidance / visual status / attention
signals (round 3), shipped DMARC RUA parsing as a scenario-aware
recommendation engine + unified the severity calculator + extended
advisor cards + cleaned row-level visual status (round 4), drained the
round-4 carryover + filed-and-shipped two self-review cycles + executed
a marketing-site refresh including a real dashboard screenshot on the
homepage + ran a measurement-validated perf audit (round 5).

This round (6) is **user-driven** — every task below comes from a real
account holder ([j.mikes@me.com](mailto:j.mikes@me.com)) who looked at
the live dashboard with their own correctly-configured single-domain
team and flagged six issues. The work splits into three threads:

1. **Truthful dashboard (false-positive bug fixes).** The system is
   currently telling the user to do things they've already done, OR
   marking initial state as a change, OR styling record-type labels
   so they look like warnings. Each is a "system has the wrong opinion"
   bug that erodes trust:
   - **TASK-125**: DNS history reports `CHANGED` on the very first
     check (transition from no-prior-state to current-state). A first
     check is not a change.
   - **TASK-126**: DNS history record-type labels (DKIM / DMARC / SPF /
     MX) are styled in tones (`badge-warning`, etc.) that overlap the
     validity-state tones. A yellow "DMARC" label looks like a warning
     when it's just the record name.
   - **TASK-128**: `/app` "Receive your first DMARC report" card says
     "Connect a mailbox if you prefer pulling them yourself" even when
     the user's DMARC `rua=` already points at `reports@sendvery.com`.
     The alternative CTA contradicts the user's correctly-configured
     state. Copy should be scenario-aware (no connect-mailbox alt when
     scenario is `PointsAtSendvery`).
   - **TASK-129**: `/app` NEXT STEP card says "Publish a DMARC RUA
     record" when the user's DMARC RUA record IS published and points
     at Sendvery's central inbox. The check that drives this card is
     missing the "RUA already at Sendvery" branch (or has it but isn't
     reading the right state).

2. **DNS history depth (one feature).** When DNS records change, the
   user wants to see the diff — what was the value before and what is
   the value now — so they can tell "is this an error or did I want
   this change?" Currently the history page just shows two states
   side by side as opaque text blobs.
   - **TASK-127**: token-level inline diff (e.g. `p=none` → `p=quarantine`
     highlighted within the DMARC record) WITH a "view full records"
     expander that toggles to the old before/after-block view.

3. **IA merge (one refactor).** `/app/domains` and `/app/dns-health`
   render two views of the same underlying data — a paying customer
   should not have to learn two separate pages for "list of my domains"
   vs "DNS health of my domains". Merge into one canonical surface.
   - **TASK-130**: collapse `/app/dns-health`'s 4-card summary
     (TASK-083) + the per-domain DNS health cards into the existing
     `/app/domains` list. Each domain card grows the DNS health score
     + per-protocol badges. NO backwards-compat redirect — find every
     route reference / template link / sidebar entry / KB article
     pointing at `/app/dns-health` and migrate it to `/app/domains`.
     Delete the now-orphaned controller + template + test files
     cleanly. CLAUDE.md says no shims, no fallbacks, no feature flags —
     do the migration as a single cohesive deletion.

4. **Performance audit (round-6 baseline diff).** Round 5 captured a
   perf-audit snapshot — every round-4/round-5 query landed SAFE
   (<5ms) on the demo-seeded dev DB. Round 6 should re-run the same
   `EXPLAIN ANALYZE` measurements after TASK-130's merge lands (the
   `/app/domains` list page will gain DNS-health-snapshot lookups it
   didn't have before) and confirm the merged surface stays within
   the SAFE band. Compare against the round-5 numbers documented in
   the backlog. Same rules: pure measurement, only ship code changes
   if a regression is confirmed.

A paying customer should trust the dashboard's opinions — "the system
told me to publish a DMARC record that I already published" is exactly
the failure mode round-4 and round-5 worked to eliminate, and round 6
catches the cases that slipped through. Where round 5 unified surfaces
that disagreed (TASK-114 cross-surface tone), round 6 unifies surfaces
that DUPLICATE (TASK-130 merge) and stops the dashboard lying about
the user's state.

================================================================
WHAT IS ALREADY DONE — DO NOT RE-PROPOSE
================================================================
Skim `docs/cx-improvement-backlog.md` first. TASK-001 through TASK-124
with status `done` are shipped. Don't re-propose anything in the six
run-summary tables. Round 5 specifically shipped:

- **TASK-084** — Domain workspace tab count badges (round-4 carryover).
- **TASK-105 + TASK-106** — Mailboxes matrix polish: collapsed callout
  for all-scenario-b teams + path-vs-scenario priority for connected
  mailboxes receiving reports.
- **TASK-107 + TASK-114** — RUA destination row visual differentiation
  + cross-surface fix via new `RuaMailboxMatcher`. Both
  `/app/mailboxes` and `/app/domains/{id}` now agree on tone for the
  same domain.
- **TASK-108** — `MailboxHealthAdvisor::silentForTooLong()` primary
  CTA is scenario-aware (Disconnect / Publish / Check DNS).
- **TASK-109** — `PassRateRegressionAdvisor` minimum-sample-size floor
  (MIN_SAMPLE_SIZE = 50) suppresses false alarms on low-volume domains.
- **TASK-115** — Active workspace tab dot badges gain a contrast ring
  so the signal doesn't disappear on the dark active background.
- **TASK-116** — TASK-106 success sub-line names the rua= address.
- **TASK-117 / 118 / 119 / 120 / 121 / 122 / 123 / 124** — marketing
  refresh: public DMARC checker CTA, pricing FAQ bundle (report
  definition + ingestion paths + glossary tooltips), real dashboard
  screenshot on the homepage, AI-bundle copy accuracy, open-source
  quickstart repo-public gate, marketing nav "What is this?" link.
- **Round-5 perf audit** — all 5 round-4/round-5 queries land SAFE
  on demo-seed. Baseline documented in the backlog for round-6 diff.

Round 5 test suite growth: 2158 → 2226 (+68 tests / +286 assertions).

Build on top — don't duplicate.

================================================================
SEED FOCUS AREAS (priority order — SHIP ALL IN ONE ROUND)
================================================================
Three buckets. The order below is the SHIP ORDER. Bucket 1 is the
small mechanical wins (4 bug fixes), bucket 2 is one feature, bucket 3
is the bigger refactor.

1. **TRUTHFUL DASHBOARD — false-positive fixes** (TASK-125 / 126 / 128 / 129)

   **TASK-125** — DNS history initial check is not a change. When a
   domain is added and the first `sendvery:dns:check-all` run captures
   its DNS state, the resulting `dns_check_result` row should NOT
   render as `CHANGED` in the history view. It's a baseline, not a
   change.

   Acceptance:
   - The first `dns_check_result` row per domain renders with an
     `INITIAL CHECK` label (use the existing severity-glyph badge
     pattern, distinct tone — e.g. `badge-info` or `badge-ghost`)
     instead of the `CHANGED` chip.
   - The label must be visually distinct from both `CHANGED` (which
     stays for subsequent real diffs) AND from the protocol-row
     validity badges (which TASK-126 also clarifies).
   - History page still shows the baseline state under the INITIAL
     CHECK row (SPF / DKIM / DMARC / MX values as they were when
     first observed), so the user sees what was originally there.
   - Detection: a row is an "initial check" when there is no prior
     `dns_check_result` for the same `(monitored_domain_id, record_type)`
     OR when the `has_changed` column is true but the row is the
     oldest for that protocol. Use the cleaner of the two — query
     the schema to decide.
   - Test: integration test seeds 2 days of dns_check_result for a
     fresh domain (Day 0 = initial, Day 1 = a real DMARC `p` change).
     Renders `/app/domains/{id}/dns-history` and asserts the Day 0
     row has `INITIAL CHECK` label and the Day 1 row has `CHANGED`.

   **TASK-126** — DNS history record-type labels need their own visual
   identity, distinct from validity-state tones. Currently "DMARC" is
   styled `badge-warning` which reads as "DMARC is broken" — but it's
   just the record name. The validity state is rendered separately
   ("Valid" / "Invalid" / etc.) and should own the warning/error tones.

   Acceptance:
   - Record-type labels (SPF / DKIM / DMARC / MX) render in a SINGLE
     unified non-semantic tone (e.g. all `badge-neutral` or all
     `badge-ghost` with a small fixed-width icon prefix per protocol).
     User identifies the record by name/icon, not by color.
   - Validity badges (`Valid` / `Invalid` / `Not found` / etc.) keep
     their existing tone palette (success/error/warning) — those colors
     own the meaning.
   - At a glance, a row reads as `[icon] DMARC | [badge-success] Valid`
     — record name has no color baggage, validity carries the tone.
   - Test: render the DNS history page for a fixture with all 4
     protocols in different validity states. Assert each protocol
     label has the same non-semantic class set, and each validity
     badge has the expected semantic class.

   **TASK-128** — `/app` "Receive your first DMARC report" card on the
   onboarding strip drops the "Connect a mailbox if you prefer pulling
   them yourself" alternative when the user's DMARC `rua=` is already
   correctly configured at `reports@sendvery.com`. For that user, the
   alternative is misleading — they've already chosen DNS ingestion;
   suggesting they connect a mailbox contradicts their state.

   Acceptance:
   - The card's body copy branches on `RuaScenarioResolver` (same
     pattern as TASK-091 / TASK-100):
     - `PointsAtSendvery` → "Reports flow in automatically. The first
       one usually arrives within 24-48 hours of `rua=` publishing —
       Gmail / Outlook / Yahoo each send one per day per domain."
       NO mailbox alternative.
     - `PointsAtExternal` → existing copy explaining the user's rua=
       routes elsewhere + suggesting they either change DNS or
       connect THAT inbox (matching TASK-100's external-inbox flow).
     - `NoRecord` → existing copy pointing the user at publishing a
       DMARC record (deep-link to domain health).
   - The card's CTA respects the same branching (no "Connect a
     mailbox" button when scenario is PointsAtSendvery).
   - Test: integration test seeds a team with one domain whose rua=
     points at sendvery's reports email and no reports yet received.
     Asserts the card renders the new "Reports flow in automatically"
     copy and does NOT contain the literal "Connect a mailbox" string.

   **TASK-129** — `/app` NEXT STEP card stops recommending "Publish a
   DMARC RUA record" when the user already has one pointing at
   Sendvery. Currently the resolver returns the publish-RUA action
   regardless of the actual rua= state. The bug likely lives in
   `NextActionResolver` (or whichever service drives the NEXT STEP
   card) — it isn't reading the `RuaScenarioResolver` output when
   computing the "what should the user do next" decision.

   Acceptance:
   - When the user's team has at least one domain with
     `RuaScenario::PointsAtSendvery` AND `firstReportAt is null`, the
     NEXT STEP card resolves to `WaitForReports` (the existing branch
     TASK-102 added for the "fresh scenario-b settling window" case)
     INSTEAD of `PublishDmarcRua`.
   - When the team has at least one domain with `RuaScenario::NoRecord`,
     the existing `PublishDmarcRua` branch still fires for THAT
     domain.
   - When the user has multiple domains with different scenarios, the
     resolver picks the highest-attention scenario per the existing
     priority order (NoRecord > misconfigured > waiting > healthy).
   - Test: integration test seeds a one-domain team with rua= at
     sendvery + no reports. Renders `/app` and asserts the NEXT STEP
     card text matches the WaitForReports variant ("Reports start
     flowing within 24-48 hours...") and does NOT contain "Publish a
     DMARC RUA record" or "Add a `_dmarc` TXT record".
   - Test: integration test seeds a one-domain team with NO DMARC
     record. Renders `/app` and asserts the existing PublishDmarcRua
     copy still renders for that team.

2. **DNS HISTORY DEPTH** (TASK-127)

   **TASK-127** — When a `dns_check_result` row is a real change
   (`has_changed = true` AND NOT the initial check from TASK-125),
   the history page renders a token-level inline diff highlighting
   the specific tags that changed within the record, WITH an expander
   to view the full before/after records side-by-side.

   Acceptance:
   - For each changed protocol on a CHANGED row, render two views:
     - **Default (token diff)**: an inline rendering of the record
       text with the tags that differ highlighted — `p=` value
       changes get the old tag in `bg-error/20 line-through` and the
       new tag in `bg-success/20 font-bold`. Other tags render in
       neutral tone. For SPF, each `include:` / `ip4:` / `~all` token
       diffs independently. For DMARC, each `key=value` tag diffs
       independently. For DKIM, the public-key body diff is treated
       as one opaque block (don't try to diff inside `p=<base64>`).
     - **Expanded (full records)**: a `details`/`summary` (or daisyUI
       `collapse`) toggle reveals two full code blocks — `Before`
       and `After` — with each record's raw value. Useful for
       longer records where the token diff gets noisy.
   - Implementation: a new `src/Services/Dns/DnsRecordDiffer.php`
     (`readonly final`) takes a previous `DnsRecord` value object and
     a current one, returns a `DnsRecordDiff` result with a list of
     `DnsRecordDiffSegment` items (each = `text`, `kind: unchanged|added|removed`).
     Template-level rendering of the segments is a Twig macro or
     small component.
   - Tests:
     - Unit test for `DnsRecordDiffer` covering: SPF token diff
       (added include), DMARC tag diff (p= flip), DKIM (one opaque
       block — the entire record changed), MX (priority change).
     - Integration test renders `/app/domains/{id}/dns-history` for a
       fixture with a DMARC `p=` flip; asserts the rendered HTML
       contains both the strike-through old value AND the highlighted
       new value within the same record line, AND the `<details>`
       expander markup is present (default-collapsed).

3. **IA MERGE — /app/dns-health into /app/domains** (TASK-130)

   **TASK-130** — Collapse `/app/dns-health` into `/app/domains` as
   one canonical "domains overview" surface. NO backwards-compat
   redirect — find every reference to the old route and migrate it.

   Acceptance:
   - `/app/domains` list page absorbs the DNS Health page's signals:
     - The 4-card summary row from TASK-083 (Domains monitored /
       Fully healthy / Need attention / Awaiting first check) renders
       at the top of `/app/domains` (above the existing chip filters
       and domain cards).
     - The `?status=` filter chips already on `/app/domains` keep
       working — they're the same classifier-driven chips as TASK-083.
     - Each domain card in the list grows: the DNS health letter
       grade (A/B/C/D/F) as a chip, plus per-protocol badges
       (SPF / DKIM / DMARC / MX) showing pass/fail at a glance —
       reuse the badge-rendering pattern already in
       `templates/dashboard/dns_health.html.twig`.
     - Clicking the letter grade or any protocol badge on a card
       deep-links to `/app/domains/{id}/health` (the per-domain DNS
       drill-down stays, since that's a different scope — overview
       vs detail).
   - `/app/dns-health` route deleted:
     - Delete the controller (`DnsHealthOverviewController.php`).
     - Delete the template (`templates/dashboard/dns_health_overview.html.twig`).
     - Delete the integration test (`DnsHealthOverviewTest.php` or
       similar).
     - Find every link to `path('dashboard_dns_health')` in templates,
       controllers, KB articles, fixtures — migrate all to
       `path('dashboard_domains')`.
     - The sidebar nav loses its standalone "DNS Health" entry. If
       its position in the sidebar is load-bearing for some IA reason,
       check the existing sidebar layout to ensure removal doesn't
       leave an obvious gap.
   - The `GetDnsHealthOverview` query stays (TASK-001 + TASK-098
     work) but now feeds the merged `/app/domains` page directly
     rather than its own controller. If `GetDomainOverview` already
     returns the per-protocol scores (via TASK-098's LATERAL join),
     prefer that single query over running both — collapse data
     access to a single round-trip if practical.
   - Tests:
     - `tests/Integration/Controller/ListDomainsTest.php` (or extend
       the existing list test) asserts: the 4-card summary renders at
       the top, each domain card renders the letter grade + 4
       per-protocol badges, the existing chip filters still work.
     - A grep test or codified check confirms NO surviving reference
       to `path('dashboard_dns_health')` or
       `/app/dns-health` in templates / controllers / KB.
     - All previously-existing tests for `/app/dns-health` removed
       cleanly (no orphan asserts referencing a deleted controller).

   Notes:
   - This is the biggest single task this round (~3-4 hours of work).
     Pick an Architect agent here — the merge needs careful sequencing
     so the deletions don't break tests mid-flight.
   - Watch for `GetDnsHealthOverview` callers: round-5 perf audit
     noted "feeds three call sites instead of one" — confirm each
     call site is now either `/app/domains` (merged) or `/app/domains/{id}/health`
     (drill-down). Any third call site is suspect.

4. **PERFORMANCE AUDIT** (round-6 baseline diff)

   Round 5 captured a perf-audit snapshot. After TASK-130 ships,
   re-run the same `EXPLAIN ANALYZE` measurements:
   - `GetDomainOverview::forTeams()` (post-TASK-130: may now JOIN on
     `domain_health_snapshot` more aggressively, or the merge may
     keep things separate — measure either way).
   - `GetDnsHealthOverview::forTeams()` (if the query survives the
     merge — if not, it should be deleted).
   - `NavCountsExtension::getGlobals()` (4 COUNTs, unchanged).
   - `IngestionPathResolver::resolveForTeams()` (post-TASK-114 went
     through `RuaMailboxMatcher` — recheck the per-row cost).
   - `GetDomainWorkspaceTabCounts::forDomain()` (unchanged).

   Compare each query's execution + planning time against the
   round-5 numbers in `docs/cx-improvement-backlog.md`. If anything
   regresses by >5ms, file a TASK-13X optimization. Document the
   round-6 numbers in a new section so round 7 can diff against them.

5. **ROUND-6 SELF-REVIEW** (every 3 shipped tasks)

   Same pattern as rounds 3-5. Step back after every 3 ships, read
   the affected templates with fresh eyes, ask "what is this for?
   what should I do? is anything wrong?". Round 3 caught 3, round 4
   caught 6, round 5 caught 3 — assume your work has similar blind
   spots.

   Round-6-specific things to watch for:
   - **TASK-126**: when record-type labels become tonally neutral, do
     they still read clearly at 360px mobile? Or do they get lost
     against the validity badge next to them?
   - **TASK-127**: the token diff highlights tags individually — for
     a DMARC record with 6+ tags that all changed (rare but possible),
     does the inline rendering get visually noisy? Is the expander
     discoverable enough?
   - **TASK-128 + TASK-129**: both branch on `RuaScenarioResolver`.
     If they share a code path, a single bug could miss both. Are
     they exercising INDEPENDENT scenario reads, or coupled through
     a shared decision point? Verify the tests genuinely cover both.
   - **TASK-130**: deleting `/app/dns-health` is a sharp edge — if
     ANY KB article, marketing page, or onboarding flow links there,
     it 404s. Grep is the friend.

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
highest at run start is TASK-124. Round-6 user-driven tasks claim
TASK-125 through TASK-130. Self-review findings or post-TASK-130
perf-audit follow-ups start at TASK-131.

This file survives compaction; ALWAYS read it before deciding what to
do next and ALWAYS update it after each phase transition.

Round 6's seed tasks are NOT yet filed at run start — you (or a
Product agent) MUST file TASK-125 through TASK-130 into the backlog
using the acceptance criteria from §SEED FOCUS AREAS above BEFORE
shipping them. The orchestrator brief itself is the source; the
backlog is the durable contract.

Mirror only the currently-active task's sub-steps in TaskCreate /
TaskUpdate. Do not put the whole backlog there.

================================================================
ORCHESTRATOR LOOP
================================================================
Repeat until "Stop conditions" are met:

1. PLAN PHASE (if backlog has <3 `proposed` tasks for the current
   bucket): file the next seed tasks from §SEED FOCUS AREAS using the
   acceptance criteria. For round 6, this means: file TASK-125 / 126 /
   128 / 129 in one pass (the 4-bug bundle), then TASK-127, then
   TASK-130. The Product agent is only needed for the stop-condition
   sweep at the end — the round-6 seed work is already specified.

2. PICK PHASE:
   Read backlog.md. Pick the highest-value `proposed` or `planned`
   task in the current seed bucket. Promote to `planned`. Seed-bucket
   order from §SEED FOCUS AREAS is the tiebreaker.

3. DESIGN PHASE:
   If the task already has a detailed architect plan in its Notes
   field, skip this phase. Otherwise, for non-trivial tasks (TASK-130
   especially), spawn Architect agent; it appends `### Architect plan
   (YYYY-MM-DD)` to the task's Notes. Promote to `in-progress`. For
   the 4 small bug fixes (TASK-125/126/128/129) the spec is already
   detailed — skip Architect, go straight to Build.

4. BUILD PHASE:
   Spawn Developer agent. Pass the architect plan if one exists,
   otherwise pass the Acceptance criteria block verbatim. **Defensive
   write strategy** (round-4 lesson): prefer `Write` with full file
   contents over `Edit` when modifying open files; `Edit` calls were
   observed being reverted by an editor race during round 4's
   parallel runs. Heredoc-via-bash is another safe fallback.

5. REVIEW PHASE:
   Spawn Reviewer agent. Promote to `in-review`. Rounds 4 + 5 both
   showed Reviewer agents netting real findings on >50% of bundles —
   keep the rhythm.

6. FIX-IF-NEEDED PHASE:
   If Reviewer reports must-fix findings, either fix small ones
   yourself (orchestrator can use Edit/Bash for trivial corrections)
   or spawn Developer again for substantial fixes. Loop BUILD →
   REVIEW at most 2 extra times. If still failing after 3 attempts,
   mark `blocked` and move on.

7. SHIP PHASE:
   Run quality gates. If green: commit, push, mark `done`. Commit per
   task (or per coherent bundle) — round-5 shipped 12 task-commits +
   5 docs-commits = 17 total, which made the git log readable and
   `git revert <task>` safe.

8. SELF-REVIEW PHASE (every 3 shipped tasks):
   Step back. Audit the affected pages by reading the post-shipping
   templates and asking: "what is this for? what should I do? is
   anything wrong?". Round 5's self-review caught 3 real issues
   (TASK-114 cross-surface contradiction, TASK-115 dot-badge contrast,
   TASK-116 missing rua= address). Assume your work has similar
   blind spots.

9. Go to step 1.

Run independent agents in parallel where the work doesn't depend on
each other. Agents that touch the same file MUST serialise. Rounds 4
+ 5 each ran up to 3 concurrent agents successfully — that remains the
sweet spot.

================================================================
AGENT CONTRACTS
================================================================

### Product agent (subagent_type: general-purpose)
Brief: "You are the product owner for Sendvery, an email
deliverability + DNS monitoring SaaS. Read CLAUDE.md, the orchestrator
brief, and the existing tasks in `docs/cx-improvement-backlog.md` so
you do not re-propose work that's already done (TASK-001 through
TASK-130 are shipped or planned by the time you run). Your job in
round 6 is the FINAL stop-condition sweep across all seed buckets
once the user-driven work has shipped. Form an honest first-impression
critique against the round-6 scope (truthful dashboard / DNS history
depth / IA merge) and surface any 'we forgot' gaps the user hasn't
named yet. Append proposals to docs/cx-improvement-backlog.md using
the schema. Continue numbering from the highest existing TASK-NNN.
Each proposal must include why a real user cares — name the moment
of confusion the change resolves, not just what changes. Do NOT
write code."

### Architect agent (subagent_type: feature-dev:code-architect)
Brief: "Design implementation for TASK-NNN. Read the Acceptance.
Produce a plan with: files to create/modify, data flow, test coverage
plan (100% required), affected routes/templates, migration needs.
For UI tasks, sketch visual hierarchy and note the daisyUI v5
components and Twig component structure. Follow Sendvery conventions
in CLAUDE.md (CQRS, readonly final, IdentityProvider, domain events,
single-action controllers, Twig component rules, daisyUI v5 only —
no `dark:`, no v3/v4 tokens, no manual theme variables outside
`@plugin \"daisyui/theme\"`). Append plan to the task's Notes field
as `### Architect plan (YYYY-MM-DD)`. Do NOT write code. **Important**:
if the orchestrator says 'this task has no architect plan yet but the
spec is detailed', the orchestrator may skip the Architect phase
entirely — your job is to produce a plan that's MORE specific than
the spec, not to restate it. If the spec is already implementable,
say so and exit. Round 6's only Architect candidate is TASK-130
(IA merge with deletion cascade) — the four bug fixes + TASK-127's
diff feature are detailed enough to skip straight to Build."

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
via `grep` that the changes are still in the file before moving on."

### Reviewer agent (subagent_type: feature-dev:code-reviewer)
Brief: "Review the diff for TASK-NNN against the Acceptance criteria,
the Architect's plan, and CLAUDE.md conventions. Report must-fix
issues (correctness, security, multi-tenancy, missing tests, broken
responsive behaviour, convention violation, ClockInterface bypass,
orphan code from deletions) separately from nice-to-haves. Be
specific: file:line + what to change. If clean, say so explicitly.
For TASK-130 specifically: verify every `path('dashboard_dns_health')`
reference is migrated, no orphan controller/template/test files
survive, and the merged /app/domains list page renders the
4-card summary + per-card grade + protocol badges per spec."

================================================================
QUALITY GATES (run before every commit)
================================================================
All must pass — no skipping, no --no-verify:
- docker compose exec app vendor/bin/phpunit (2226 tests at run start)
- docker compose exec app vendor/bin/phpstan
- docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
- For UI tasks: read the page, confirm desktop AND 360px mobile render
- 100% coverage on new code (per CLAUDE.md)
- `ClockInterface::now()` used everywhere — never `new \DateTimeImmutable()`
  in production code paths
- For TASK-130 deletion cascade: `grep -r "dashboard_dns_health" templates/ src/ config/`
  returns nothing (or only `// removed in TASK-130` markers that are
  themselves about to be deleted — CLAUDE.md prefers actual deletion
  over removal comments)

================================================================
AUTONOMY (do these without asking)
================================================================
- Read/write any file in the repo.
- Read files outside the repo when necessary.
- Run any docker compose / composer / phpunit / phpstan / cs-fixer
  command. Also run `bin/console sendvery:*` commands including
  `sendvery:demo:seed` for perf measurement.
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
- **Delete files and routes cleanly** as part of TASK-130 (no
  backwards-compat shims per CLAUDE.md). This is the user-blessed
  scope for round 6.

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
- Add backwards-compat shims, fallbacks, or feature flags. TASK-130
  is EXPLICITLY a no-redirect migration per user direction.
- Skip tests or quality gates.
- Couple "ingest via DNS" and "ingest via mailbox" into a single
  config — they are mutually exclusive per domain.
- Bypass `ClockInterface` with `new \DateTimeImmutable()` in
  production code.
- Reintroduce the marketing-nav Dashboard CTA badge (TASK-065 +
  CLAUDE.md note explicitly locks this).
- Keep `/app/dns-health` alive as an alias (the user explicitly said
  "we do not need keep backward compatibility, just migrate the
  functionality" — honor it).

================================================================
STOP CONDITIONS
================================================================
Stop and report to the user only if:
- Backlog has zero `proposed` or `planned` tasks AND a final
  Product-agent sweep returns no new proposals worth shipping.
  **This is the primary stop signal — the round is designed to
  drain the backlog completely.**
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

Round 5 hit the primary stop signal (backlog drained). Round 6
should do the same — the scope is tighter (6 user-driven tasks +
perf audit + self-review) than round 5's 17-task drain.

When you stop, append a final summary to
docs/cx-improvement-backlog.md: tasks shipped, tasks blocked + why,
self-review findings, surfaces you reviewed and judged "good enough",
perf-audit measurements (even null results), and suggested next
moves for a future run.

================================================================
KICKOFF
================================================================
1. Read `docs/cx-improvement-backlog.md`, skim the SIX RUN SUMMARY
   sections to understand what's shipped. Note the highest existing
   TASK-NNN (TASK-124) so any newly-proposed tasks start at TASK-125.
2. CLAUDE.md is already loaded. Skim `docs/` for reference; pull in
   specific files only when the current task needs them.
3. **File the round-6 user-driven tasks** (TASK-125 / 126 / 127 / 128 /
   129 / 130) into the backlog using the acceptance criteria from
   §SEED FOCUS AREAS. Each must include the user-supplied moment of
   confusion in the Why field — the user's own words from the
   feedback when possible (these are the most authentic descriptions
   of the bug). Order in the file: TASK-125 → 126 → 127 → 128 → 129
   → 130 (numeric, matches their natural narrative).
4. **Ship the bug-fix bundle first** (TASK-125 / 126 / 128 / 129).
   Three of the four touch different surfaces (dns history vs onboarding
   card vs next-step card) so they can ship in parallel. TASK-126 + 125
   both touch the DNS history page — bundle them under ONE agent
   to avoid edit collisions.
5. **Ship TASK-127 (DNS history diff)** next. Touches the same page
   as 125 + 126 but adds a new service + new rendering branch — no
   collision if shipped sequentially.
6. **Spawn Architect agent for TASK-130 (IA merge)** — biggest single
   task this round, deletion cascade needs careful sequencing. Then
   ship.
7. **Run the round-6 perf audit** after TASK-130 lands. Document the
   numbers in a new `## Round-6 performance audit (YYYY-MM-DD)`
   section above the round-5 perf section. Compare against round-5
   baseline — flag any >5ms regression.
8. After every 3 shipped tasks, run a self-review pass.
9. Final Product-agent sweep across all buckets as the stop-condition
   check.
10. Write the RUN SUMMARY when the backlog is truly empty. Cover:
    every task shipped, any blocked + why, self-review findings +
    dispositions, suite growth, perf-audit measurements (round-6 vs
    round-5 diff), suggested round-7 seed areas.

================================================================
LESSONS FROM ROUNDS 4 + 5 — APPLY HERE
================================================================
- **Editor-revert race** (round 4): prefer `Write` (full file
  content) or heredoc-via-bash when modifying files that might be
  open in an editor / under linter watch. Round 5 used this
  defensively and lost zero edits.
- **Parallel agents**: 3 concurrent is the sweet spot. Round 5 hit
  this consistently.
- **Self-review payoff**: round-3 caught 3, round-4 caught 6, round-5
  caught 3. Run the self-review every 3 ships without exception.
- **Don't over-architect small tasks**: round-5 skipped Architect for
  every bug-fix bundle and only used it where the deletion/refactor
  scope demanded it. Round 6 should do the same — Architect only
  for TASK-130.
- **Commit per task or per coherent bundle**: round-5 made 12
  task-commits across 17 tasks (some bundled like TASK-118/119/124).
  Readable git log, safe `git revert <task>`. Don't pile multiple
  unrelated tasks into one commit.
- **Reviewer agents net real findings on >50% of bundles**: round 4
  + 5 both confirmed this. Keep the review step even when the dev
  agent reports "all green".
- **Cross-surface consistency tests pay off**: round 5's
  `SurfaceConsistencyTest` for TASK-114 caught the
  `/app/mailboxes` vs `/app/domains/{id}` tone disagreement at the
  test layer rather than user-report layer. For TASK-130's merge,
  consider whether the same pin pattern applies — the merged
  `/app/domains` page should agree with `/app/domains/{id}/health`
  on protocol badge tones for the same domain.
- **User-driven tasks have the highest signal**: round 6's entire
  scope comes from a real user looking at the real product. The Why
  field for each task should be quoted from their words. The bug
  fixes (TASK-125 / 126 / 128 / 129) are the highest-value items in
  the round even though they're the smallest — they're catching
  trust-erosion failures the system didn't catch on its own.
