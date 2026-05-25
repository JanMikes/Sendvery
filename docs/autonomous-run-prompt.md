# Autonomous CX/Product Improvement Run — Sendvery (Round 9: homepage hero rewrite + per-domain DKIM-selector preference + SEO follow-ups + TASK-144 nice-to-haves)

You are the ORCHESTRATOR. Your job is to autonomously improve Sendvery's
marketing surfaces + dashboard by running a continuous loop of
specialised subagents. You self-pace. You DO NOT stop to ask the user
for permission on anything covered by "Autonomy". You DO NOT stop until
the backlog is genuinely empty (Product agent confirms nothing more is
worth doing) or you hit a real blocker in "Stop conditions".

================================================================
CHECKPOINT — WHAT ROUND 8 SHIPPED (read this first)
================================================================
Round 8 closed cleanly with **9 user-driven tasks shipped** + 1 blocked +
filed as round-9 follow-up. Final state: 2284 tests / 6764 assertions,
all gates green, all commits pushed to `origin/main`.

Shipped in round 8 (in this order):

- **TASK-136 / 139 / 140 / 141** (commit `bdf4b62`) — Marketing-clutter
  quick-wins bundle: `SENDVERY_REPO_PUBLIC` env-gate retired entirely
  (repo is public); "Built for engineers" homepage section deleted;
  empty "Related tools" blocks stripped from every `/tools/*` page;
  footer "Built with Symfony & FrankenPHP" → "Built with love by Jan
  Mikeš · Source on GitHub →".
- **TASK-137 + TASK-138** (`6f64545`) — Homepage register unified to
  `font-medium tracking-tight text-zinc-900` page-end-to-end via the
  shared `SectionHeader` component + 3 inline H2s; "How it works"
  custom `how-*.webp` illustrations replaced with inline Lucide SVGs
  inside zinc-bordered tiles; the 3 orphan webp assets deleted.
- **TASK-143** (`7bd54a5`) — **BLOCKED**. Investigation found that the
  described feature (dashboard DKIM-selector form) DOES NOT EXIST in
  the codebase. The closest UX surface (`/tools/dkim-checker` Live
  Component) is already fully editable. The user's complaint maps to
  a missing-feature gap: teams running selectors not in
  `DkimSelectorRegistry::PROVIDER_SELECTORS` silently see "DKIM not
  found" forever because there's no way to teach the dashboard the
  right selector per domain. Filed as **TASK-146** for round 9 (see
  Seed Focus Areas below).
- **TASK-142** (`05a2649`) — SEO audit + 7 highest-leverage fixes:
  (1) created `public/images/og-default.webp` (was referenced but
  never existed); (2) canonical + og:url now route-based (no query
  strings); (3) `Disallow: /app/`, `/onboarding/`, `/auth/`,
  `/_components/` in `robots.txt`; (4) `authorizing-senders-explained`
  added to sitemap; (5) `noindex,follow` on login + auth pages;
  (6) `SoftwareApplication` JSON-LD on `/pricing` with all 4 offer
  rungs; (7) login page wrapped its CTA copy in `<h1>`.
- **TASK-144** (`2d1bce4`) — 4 client-side DNS record generators (SPF,
  DMARC, DKIM, MX) on the existing `/tools/{type}-checker` pages.
  Stimulus controllers + 2 new readonly registries (`SpfProviderRegistry`,
  `MxPresetRegistry`). XSS-safe (`textContent` everywhere). DKIM
  auto-splits long RSA keys at 255-char boundaries per BIND zone-file
  convention. Microsoft 365 MX preset reveals a tenant-name input.
  Reviewer caught 2 must-fixes inline (DMARC `mailto:` double-prefix
  + Brevo legacy include `spf.sendinblue.com` → current `spf.brevo.com`).
- **TASK-145** (`5b3f682`) — Homepage narrative restructure:
  Hero → Problem framing (was §7, moved up to §2) → Solution 1
  (XML→English) → Solution 2 (grade card) → Product preview →
  How it works → **Pricing (moved up from §10 to §7 per user)** →
  Health-grade reinforcement → Features → Testimonials → Open
  source → Founder bio → FAQ → Final CTA. Top-of-file narrative-arc
  comment documents the new order + per-section rationale.

User-driven sidecar commits (between mine) that I integrated against:
`3852c07` hero gradient redesign, `06fb2e0` checker-form shadow,
`d433ce9` hero background illustration, `6a9d04b` alternating section
backgrounds + trust-logos removal + navbar shadow.

**User feedback baked in (saved as durable memory):**
- *"the tests should test business behaviour, not TASK-XXX ticket
  numbers"* — round-8 tests renamed: e.g.
  `task137And138HomepageRegisterAndIcons` →
  `homepageHeadingsUseUnifiedLighterRegister`. Assertion failure
  messages rewritten to describe broken behaviour, not cite the
  originating ticket. Docblocks retain TASK-XXX references
  (documentation, not test contract). **Round-9 tests MUST follow
  this naming convention from day 1.**

Round 8 final stats: **2272 → 2284 tests (+10), 6688 → 6764
assertions (+77)** vs round-7 baseline. Perf delta vs round 7 ≈ 0
(no new DB queries — all changes were marketing templates + 2
in-memory PHP registries).

================================================================
MISSION
================================================================
Round 9 is **user-driven + follow-through**: a fresh round of homepage
hero feedback from the user (the most-visible surface) plus the
deferred TASK-146 feature gap and the round-8 nice-to-haves.

1. **TASK-158 — Homepage hero rewrite for user-value framing** (P0,
   ship FIRST). User round-9 feedback: hero leads with feature
   labels and an open-source pitch, dilutes focus with a secondary
   CTA, and squeezes the checker card off-screen on mobile. The
   most-seen surface needs to lead with the visitor's outcome and
   convert in one screen.

2. **TASK-146 — Per-domain DKIM-selector preference** (P0). Round
   8's TASK-143 investigation surfaced this as a real feature gap:
   teams whose DKIM selector isn't in the canonical
   `DkimSelectorRegistry` silently see "DKIM not found" forever
   with no way to teach the dashboard their selector. Architect-first;
   needs data-model + UX decision + integration with the brute-force
   fallback in `DkimChecker::check()`.

3. **SEO polish follow-ups** (6 items from TASK-142's architect plan
   that were deferred as lower-priority). These ship as one bundled
   commit — small template / config tweaks.

4. **TASK-144 reviewer nice-to-haves** (5 items the round-8 reviewer
   flagged as non-blocking). Ship as a bundled commit alongside the
   SEO polish.

5. **Watchlist items** (no action expected unless real signal emerges):
   - `IngestionPathResolver::resolveForTeams` re-measure once any team
     hits 50+ monitored domains. Still demo-only at 3-domain scale.
   - `/app/alerts` empty-state copy. Carried since round 5 with no
     user signal — defer unless the user flags it this round.

The user supplied the round-9 hero asks directly (see TASK-158 spec
below). Other surface-level marketing feedback from the user during
the round should land as additional TASK entries before round-9
shipping completes.

================================================================
WHAT IS ALREADY DONE — DO NOT RE-PROPOSE
================================================================
Skim `docs/cx-improvement-backlog.md` first. **TASK-001 through
TASK-145 are shipped or filed** (TASK-143 is `blocked` and tracked as
TASK-146; all others `done`). Don't re-propose anything in the eight
historical RUN SUMMARY tables.

Round 8 specifically shipped:
- TASK-136 / 139 / 140 / 141 — marketing clutter bundle
- TASK-137 — `font-medium` H2 register page-end-to-end on `/`
- TASK-138 — Lucide icons replace `how-*.webp` illustrations
- TASK-142 — SEO baseline (canonical + OG fallback + noindex + JSON-LD)
- TASK-144 — 4 client-side DNS record generators
- TASK-145 — homepage narrative restructure with pricing moved earlier

Round 8 self-review caught zero must-fixes (clean first pass). Round
8's reviewer-agent on TASK-144 caught 2 real must-fixes shipped inline.

Build on top — don't duplicate.

================================================================
SEED FOCUS AREAS (priority order — SHIP IN THIS ORDER)
================================================================
Four buckets. **TASK-158 (hero rewrite) ships first** because it's
user-driven, highly visible, and quick — every visitor sees the hero,
so a broken value prop costs more than any other gap.

0. **TASK-158 — Homepage hero rewrite for user-value framing** (P0,
   user-driven, ship FIRST)

   **Why this matters (verbatim from the user, round 8 → round 9
   prompt):** *"main priority is the value it brings to the user or
   the problem it solves which is the most important to user, he
   must see immediately what and why - if he should be interested or
   instantly leave"*. The current hero is feature-oriented and
   buries the visitor's payoff behind a stack-pitch. Specific
   concrete asks from the user:

   - **H1**: *"'DMARC, DNS, deliverability — monitored and
     explained.' - not 'explained' but better marketing claims."*
     The word "explained" describes what the product does, not what
     the visitor gets. Replace with a benefit-framed claim that
     leads with the OUTCOME (deliverability protected / spoof
     attempts caught / email reaching the inbox / etc. — copywriter's
     call from a small set of options).

   - **Eyebrow**: *"not need the 'DMARC · DNS · DELIVERABILITY'
     bullets on hero, it is duplicate."* The little uppercase text
     above the H1 repeats the H1's own keywords. Delete it.

   - **Subhead must NOT lead with open-source**: *"Do not sell the
     'open-source' in hero - main priority is the value it brings to
     the user."* The current subhead opens with *"Sendvery is the
     open-source email deliverability platform that..."*. Open
     source is a credibility signal for a subset of visitors — it
     belongs in the footnote chip row (already present:
     "Open source · AGPL-3.0 · 1 domain free forever · Self-hostable")
     or in the Open Source section deeper down. Rewrite the subhead
     to lead with the visitor's outcome.

   - **Drop the secondary CTA**: *"'View on GitHub' CTA in hero is
     useless - it takes customer away from the website, keep only
     one CTA 'Get started free' - focus on selling, explaining
     value proposition."* Single hero CTA only. The GitHub link
     still lives in the footer + the Open Source section deep on
     the page — don't put it back in the hero.

   - **Mobile hero must fit the checker card above the fold**:
     *"the hero is great on desktop, but need tweaks on mobile so
     the 'Free instant check - no signup' could fit the screen too"*.
     On mobile (360-390px viewports), the visitor must see the H1
     + value-prop subhead + "Get started free" CTA AND the checker
     card without scrolling. Today the right-column card stacks
     below the left column and falls below the fold. Tighten the
     mobile vertical rhythm — smaller H1 font on mobile, smaller
     subhead, smaller column gap, smaller card padding — so the
     whole hero lands in one screen. Desktop layout stays as-is.

   **Acceptance:**
   - Hero `<h1>` reads as an outcome-framed claim (no "explained"
     as the headline payoff). The replacement should pass the test:
     a visitor who reads ONLY the H1 understands what they get, not
     just what the product is.
   - Eyebrow `<div class="text-xs uppercase tracking-wider
     text-zinc-500 mb-4">DMARC · DNS · deliverability</div>`
     deleted.
   - Subhead rewritten to lead with user value — no open-source
     mention. The credibility chip row beneath the CTA can keep
     the open-source line.
   - Hero has exactly ONE `<a data-track="hero-cta-primary">` link;
     the `data-track="hero-cta-secondary"` "View on GitHub" link is
     removed.
   - Mobile vertical rhythm tightened: H1 / subhead / CTA / card all
     fit in one screen at 360px. Use `py-8 md:py-24` (or similar)
     so desktop padding stays as-is.
   - Test: extend the homepage test to assert (a) the eyebrow's
     literal text "DMARC · DNS · deliverability" no longer appears
     above the H1 (could grep for the eyebrow text not appearing in
     the body, OR walk the DOM and assert the H1 is the first text
     element inside the hero column), (b) only ONE
     `data-track="hero-cta-*"` link in the hero, (c) the H1 does
     NOT contain the word "explained".
   - Mobile rendering verification: load the homepage at 360px
     viewport (curl + dom-crawler), assert the H1 carries a
     mobile-tighter font-size class (e.g. `text-3xl md:text-5xl`
     instead of the current `text-4xl md:text-5xl`).

   **Notes:**
   - No architect needed — spec is concrete; straight to Build.
   - Copywriter judgement on the new H1 + subhead: the
     orchestrator can propose, ship, and let the user iterate.
     Suggested first pass (NOT prescriptive — pick what reads best):
     - H1: "Stop your email from quietly landing in spam."
     - Subhead: "Sendvery watches your DMARC reports + DNS 24/7 and
       tells you in one sentence what to fix. Free for 1 domain."
     OR
     - H1: "Catch the email-auth breaks nobody else does."
     - Subhead: "DMARC reports translated to plain English. Continuous
       DNS health monitoring. AI-explained fixes."
     Final wording is the Developer's call against the user feedback
     in `feedback-hero-leads-with-user-value` memory.
   - Apply the same lens to the heroes on `/pricing`,
     `/about/what-is-sendvery`, `/about/open-source`,
     `/tools/*` IF they have the same problem. Audit each — fix in
     scope where the same eyebrow-duplication / feature-not-benefit
     framing exists; don't restructure pages that already lead with
     value.

1. **TASK-146 — Per-domain DKIM-selector preference** (P0 for round 9)

   **Why this matters (verbatim from the round-8 investigation):**
   The dashboard's "Re-check now" flow runs `DkimChecker::check(domain,
   null)`, which brute-forces selectors from
   `DkimSelectorRegistry::PROVIDER_SELECTORS`. Teams whose DKIM
   selector isn't in that canonical list (custom selectors from
   internal rotation, niche providers like Loops/Resend that may use
   account-specific selector names, etc.) silently see "DKIM not
   found" forever. There is currently NOWHERE in the dashboard to
   teach Sendvery "use selector `X` for this domain." The user's
   round-8 message ("I am unable to change my dkim selector once it is
   saved — this is important!") mapped to this gap once the
   investigation found there was no read-only form to "fix."

   **Architect must scope (architect-first):**
   - **Data model**: most likely a `dkim_selector` nullable VARCHAR(255)
     column on `monitored_domain` (matches TASK-133's `disconnected_at`
     migration shape — single-column metadata-only ALTER, PG16+ folds
     this without a table rewrite). Validation: when set, must be a
     valid DNS label (`/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/`
     per RFC 1035) and non-empty.
   - **UX surface**: where in the dashboard does the user edit the
     selector? Recommended: small `<form>` on `/app/domains/{id}`
     (Domain detail) underneath the DKIM card. Free-form `<input>`
     (not a select — the canonical list is internal optimization, not
     user-facing). Pre-fill from the column. Submit → POST to a new
     route that updates the column AND immediately dispatches
     `CheckDomainDns` so the DKIM verification status reflects the
     new selector by the next page load.
   - **Wiring**: `DkimChecker::check(domain, selector)` already
     supports a passed selector. The only change is
     `CheckDomainDnsHandler` reading `$domain->dkimSelector` and
     passing it through (currently it passes `null`, triggering
     brute-force).
   - **Edge cases**:
     - Empty/cleared selector reverts to brute-force (the current
       behaviour). User can opt out by clearing the input.
     - Changing the selector does NOT invalidate historical
       `dns_check_result` rows or DMARC report records — the column
       change is metadata-only.
     - Soft-deleted-mailbox case from TASK-133 still allows selector
       edit (no coupling).
   - **CQRS shape**: new command `SetDomainDkimSelector` (or extend
     the existing domain-update command if one exists — grep for it).
     Handler updates the column + dispatches the re-verification.

   **Acceptance:**
   - Architect proposes data model + UX + integration plan; appends
     to TASK-146 notes.
   - Migration adds `dkim_selector` column.
   - Dashboard renders an editable text input on `/app/domains/{id}`
     with the current value pre-filled.
   - POST endpoint updates the column AND dispatches `CheckDomainDns`.
   - `CheckDomainDnsHandler` reads `$domain->dkimSelector` and passes
     it to `DkimChecker::check()` (instead of always passing `null`).
   - Validation rejects malformed DNS labels.
   - Integration tests cover: set selector → check uses it; clear
     selector → check brute-forces; changing selector → re-verification
     dispatched.
   - **Test naming follows the round-8 convention**: business-behaviour
     names, not `task146*` (e.g. `teamCanSetDomainDkimSelectorForCustomKeys`,
     `clearingDkimSelectorRevertsToBruteForce`).

2. **SEO POLISH BUNDLE** (TASK-147 through TASK-152 — file these
   before shipping)

   Six items deferred from TASK-142's architect plan as
   "lower-priority follow-ups." Ship as one coherent commit since
   they're all small template / config tweaks.

   - **TASK-147** — Organization JSON-LD on `/` needs a `logo` field.
     Improves Knowledge Panel eligibility on Google. Requires creating
     a `/logo.png` (or `.webp`) asset and adding `"logo": "..."` to
     the existing Organization JSON-LD in `templates/homepage/index.html.twig`.

   - **TASK-148** — Per-article `datePublished` / `dateModified`
     data source. Today both dates are hardcoded `"2026-03-25"` for
     all 7 KB articles in `templates/knowledge_base/_article_layout.html.twig`.
     Add fields to the KB config (`KnowledgeBaseConfig` or wherever
     `KnowledgeBaseIndexController::GUIDES` is defined) and thread
     them through to the layout. Minimum viable: `{% block
     article_published_at %}` + `{% block article_updated_at %}`
     hooks in the layout, overridden per article. Prevents Google
     from treating all articles as the same freshness signal.

   - **TASK-149** — `BreadcrumbList` JSON-LD on KB index + about/*
     pages. Currently only tool pages + KB articles emit
     `BreadcrumbList`. Add it to `templates/knowledge_base/index.html.twig`
     and `templates/about/{what-is-sendvery,open-source,pricing}.html.twig`.

   - **TASK-150** — `WebSite` JSON-LD with `SearchAction` on home.
     Enables Google Sitelinks Searchbox eligibility if/when a search
     feature ships. Add as a second `<script type="application/ld+json">`
     block in `templates/homepage/index.html.twig`.

   - **TASK-151** — Twitter handle in Twitter Cards. Add
     `<meta name="twitter:site" content="@sendvery">` to
     `templates/base.html.twig`. (If the user doesn't have an `@sendvery`
     account, skip this and file as future-watchlist instead — verify
     before shipping.)

   - **TASK-152** — Title length overages on 4 tool pages. The
     blacklist-checker, email-auth-checker, domain-health tool, and
     gmail-yahoo-bulk-sender article all sit 5-8 chars over the ~60-char
     SERP-friendly threshold. Trim by swapping `| Sendvery` →
     `— Sendvery` and tightening the descriptor. Architect-first
     unnecessary — straight to Build.

   **Acceptance:** ship as ONE commit covering all 6 items. Test
   contract: extend `publicPagesShipSeoBaseline` to assert the new
   `BreadcrumbList` + `logo` field + `twitter:site` presence.

3. **TASK-144 REVIEWER NICE-TO-HAVES BUNDLE** (TASK-153 through TASK-157)

   Five items the round-8 reviewer flagged as non-blocking but worth
   fixing. Ship as one commit alongside the SEO polish or right after.

   - **TASK-153** — Drop the dead `copyButton` Stimulus target
     declarations from all 4 generator controllers
     (`assets/controllers/{spf,dmarc,dkim,mx}_generator_controller.js`).
     Each declares `static targets = [..., 'copyButton']` but no
     template wires `data-*-generator-target="copyButton"`. The `copy()`
     action uses `event.currentTarget`, so the declarations are
     unreachable dead code.

   - **TASK-154** — Basic email-format validation on the DMARC
     generator's `rua` / `ruf` inputs. Today
     `dmarc_generator_controller.js` accepts any string and slaps
     `mailto:` in front. Add a regex check (`/^[^@\s]+@[^@\s]+\.[^@\s]+$/`
     or similar) and either reject or visually flag invalid entries.
     XSS is not the concern (textContent already protects); this is
     a UX polish to catch typos before the user pastes the record
     into DNS.

   - **TASK-155** — "Objects over arrays" registry refactor for
     `SpfProviderRegistry` + `MxPresetRegistry`. Today both return
     `list<array{key: string, label: string, ...}>` (shape arrays).
     Per CLAUDE.md ("never use associative arrays for structured
     data; use value objects"), refactor each entry into a tiny
     `readonly final class` DTO (e.g. `SpfProvider` + `MxPreset`).
     `allAsJson()` continues to emit JSON; just iterate `->key`,
     `->label`, `->include` instead of `['key']`, `['label']`,
     `['include']`. Mechanical; no behaviour change.

   - **TASK-156** — `assertResponseIsSuccessful()` on the 3 newer
     tool tests in `tests/Integration/Controller/ToolPagesTest.php`
     (`dmarcGeneratorHasPolicies`, `mxGeneratorHasGoogleWorkspacePreset`,
     `mxGeneratorDataAttributeContainsPresetsJson` — round-8 reviewer
     flagged these as missing the status-code guard the rest of the
     file uses).

   - **TASK-157** — Omit default `adkim=r` / `aspf=r` in the DMARC
     generator output when both are at their default `relaxed` mode.
     RFC 7489 defaults both to `r`, so emitting them produces an
     identical-semantic but longer record. Only output when set to
     `s` (strict).

   **Acceptance:** ship as ONE commit covering all 5 items. Test
   contract: add assertions for TASK-154 (rejection or visual flag
   on an invalid email) and TASK-157 (default-mode output has no
   `adkim` / `aspf` tag).

4. **PERFORMANCE AUDIT** (round-9 baseline diff)

   Round 9's only DB-touching change is TASK-146 (the new
   `monitored_domain.dkim_selector` column + the
   `CheckDomainDnsHandler` read). Both are O(1) read changes per
   request — the column is on a row that's already loaded for
   verification, no new query introduced.

   Expected perf delta vs round 8 ≈ 0. Spot-check
   `MonitoredDomainRepository::findForTeams` and `CheckDomainDnsHandler`
   after TASK-146 lands to confirm. Document in a new
   `## Round-9 performance audit (YYYY-MM-DD)` section above the
   round-8 one in `docs/cx-improvement-backlog.md`.

5. **ROUND-9 SELF-REVIEW** (every 3 shipped tasks)

   Same pattern as rounds 3-8. Round-9-specific things to watch for:

   - **TASK-146**: does setting an empty selector correctly revert to
     brute-force? Does the form correctly pre-fill the existing value
     so the user sees what they previously saved? Does the
     re-verification fire idempotently (not double-dispatched if the
     user submits the form without changing the value)?
   - **SEO bundle** (TASK-147-152): grep `tests/` for any test that
     asserted the OLD title format on the 4 trimmed pages. Update
     before commit.
   - **TASK-155** registry refactor: the JSON shape that flows to
     the Stimulus controllers MUST stay byte-identical after the
     DTO refactor. Stimulus consumes `data-*-generator-providers-value`
     as an array of `{key, label, include}` — if `allAsJson()`
     serialises the DTO differently (PHP `__serialize`, property
     order), the generators break. Verify the JSON output matches
     pre-refactor.
   - **TASK-157**: the `relaxed` default omission must NOT affect
     records where the user explicitly typed `r` — only the input's
     default state should trigger the omission.

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

Task numbering CONTINUES from the highest existing TASK-NNN. At
round-9 start, **TASK-146 is the only filed `proposed` task**.
Round-9 work claims TASK-147 through TASK-158 (file them at the start
of each bucket, before shipping — including TASK-158 for the
user-driven hero rewrite that ships first). Self-review findings or
post-shipping follow-ups start at TASK-159.

This file survives compaction; ALWAYS read it before deciding what to
do next and ALWAYS update it after each phase transition.

Mirror only the currently-active task's sub-steps in TaskCreate /
TaskUpdate. Do not put the whole backlog there.

================================================================
ORCHESTRATOR LOOP
================================================================
Repeat until "Stop conditions" are met:

1. PLAN PHASE (if backlog has <3 `proposed` tasks for the current
   bucket): file the next seed tasks from §SEED FOCUS AREAS using the
   acceptance criteria.

2. PICK PHASE:
   Read backlog.md. Pick the highest-value `proposed` or `planned`
   task in the current seed bucket. Promote to `planned`. Seed-bucket
   order from §SEED FOCUS AREAS is the tiebreaker.

3. DESIGN PHASE:
   If the task already has a detailed architect plan in its Notes
   field, skip this phase. Otherwise, for non-trivial tasks (TASK-146
   for sure — new data model + new dashboard surface), spawn
   Architect agent; it appends `### Architect plan (YYYY-MM-DD)` to
   the task's Notes. Promote to `in-progress`. For the SEO bundle
   (TASK-147-152) and the TASK-144 nice-to-haves (TASK-153-157) the
   spec is detailed — skip Architect, go straight to Build.

4. BUILD PHASE:
   Spawn Developer agent. Pass the architect plan if one exists,
   otherwise pass the Acceptance criteria block verbatim. **Defensive
   write strategy** (round-4 lesson): prefer `Write` with full file
   contents over `Edit` when modifying open files; `Edit` calls were
   observed being reverted by an editor race during round 4's
   parallel runs. Heredoc-via-bash is another safe fallback.

5. REVIEW PHASE:
   Spawn Reviewer agent. Promote to `in-review`. Rounds 4-8 all
   showed Reviewer agents netting real findings on >50% of bundles —
   keep the rhythm.

6. FIX-IF-NEEDED PHASE:
   If Reviewer reports must-fix findings, either fix small ones
   yourself (orchestrator can use Edit/Bash for trivial corrections)
   or spawn Developer again for substantial fixes. Loop BUILD →
   REVIEW at most 2 extra times. If still failing after 3 attempts,
   mark `blocked` and move on.

7. SHIP PHASE:
   Run quality gates. If green: **commit AND push to `origin/main`**,
   then mark `done`. Commit per task (or per coherent bundle) —
   round-8 shipped 6 task-commits + 1 docs-commit = 7 total. Round 9
   will likely be 3 task-commits (TASK-146 + SEO bundle + TASK-144
   nice-to-haves) + 1 docs commit.

   **Push continuously, not at the end.** After every successful
   commit, run `git push origin main` before moving to the next task.

   **Push-auth note**: the macOS keychain-backed SSH agent sometimes
   loses identity between Bash invocations. If `git push` errors with
   "Permission denied (publickey)", prepend
   `SSH_AUTH_SOCK=/private/tmp/com.apple.launchd.<id>/Listeners`
   (look it up via `echo $SSH_AUTH_SOCK` in a fresh Bash). Don't ask
   the user — known shell-env issue, not real auth failure.

8. SELF-REVIEW PHASE (every 3 shipped tasks):
   Step back. Audit the affected pages by reading the post-shipping
   templates and asking: "what is this for? what should I do? is
   anything wrong?". Round-8 self-review caught zero must-fixes (clean
   first pass); round 9's biggest risk is TASK-155's registry
   refactor changing the JSON shape the Stimulus controllers consume.

9. Go to step 1.

Run independent agents in parallel where the work doesn't depend on
each other. Round 9 has fewer parallelisation opportunities than
round 8 — TASK-146 touches the data model AND the dashboard, so it
should ship in serial; the SEO bundle + TASK-144 nice-to-haves can
ship in parallel since they touch disjoint file sets.

================================================================
AGENT CONTRACTS
================================================================

### Product agent (subagent_type: general-purpose)
Brief: "You are the product owner for Sendvery, an email
deliverability + DNS monitoring SaaS. Read CLAUDE.md, the orchestrator
brief, and the existing tasks in `docs/cx-improvement-backlog.md` so
you do not re-propose work that's already done (TASK-001 through
TASK-157 are shipped or planned by the time you run). Your job in
round 9 is the FINAL stop-condition sweep once the user-driven
work has shipped. Form an honest first-impression critique against
the round-9 scope (DKIM-selector preference + SEO polish + TASK-144
nice-to-haves) and surface any 'we forgot' gaps the user hasn't named
yet. Append proposals to docs/cx-improvement-backlog.md using the
schema. Continue numbering from the highest existing TASK-NNN. Each
proposal must include why a real user cares — name the moment of
confusion the change resolves, not just what changes. Do NOT write
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
`@plugin \"daisyui/theme\"`). Append plan to the task's Notes field
as `### Architect plan (YYYY-MM-DD)`. Do NOT write code. **Round 9's
primary Architect candidate is TASK-146** — needs data-model
decision (column on `monitored_domain` vs separate table?), UX
surface decision (free-form input vs select+override?), and CQRS
shape (new command vs extend existing). The SEO bundle and TASK-144
nice-to-haves are implementable from the spec — skip Architect for
those."

### Developer agent (subagent_type: general-purpose)
Brief: "Implement TASK-NNN per the Architect's plan (or the
Acceptance criteria if no architect plan exists). Follow CLAUDE.md
strictly. Write tests alongside.

**Test naming convention (round-8 user feedback, baked in):** test
method names describe BUSINESS BEHAVIOUR, not TASK-XXX ticket numbers.
Use names like `teamCanSetDomainDkimSelectorForCustomKeys` or
`clearingDkimSelectorRevertsToBruteForce` — never `task146DkimSelector*`.
Assertion failure messages describe the broken behaviour, not the
originating ticket. Test docblocks CAN reference TASK-XXX (that's
documentation, not the test contract).

Run inside the app container:
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

For TASK-146 specifically:
- Verify the new `dkim_selector` column is `nullable` (empty input
  reverts to brute-force).
- Verify `CheckDomainDnsHandler` actually reads
  `$domain->dkimSelector` and passes it to `DkimChecker::check()` —
  not just adds the column without wiring it through.
- Verify the DNS-label validator rejects empty strings, whitespace,
  and labels with `_underscores` or `.dots` (DKIM selectors are a
  single DNS label, not a full domain).
- Verify the re-verification dispatch is NOT double-fired on form
  re-submit with an unchanged value.
- Verify the new test methods follow business-behaviour naming
  (no `task146*` prefixes; assertion messages describe behaviour).

For TASK-155 (registry DTO refactor) specifically: verify the JSON
shape that lands in the Stimulus controller's `data-*-providers-value`
attribute is byte-identical to round 8 — Stimulus consumes it as an
array of `{key, label, include}` objects."

================================================================
QUALITY GATES (run before every commit)
================================================================
All must pass — no skipping, no --no-verify:
- docker compose exec app vendor/bin/phpunit (2284 tests at round-9 start)
- docker compose exec app vendor/bin/phpstan
- docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
- For UI tasks: read the page, confirm desktop AND 360px mobile render
- 100% coverage on new code (per CLAUDE.md)
- `ClockInterface::now()` used everywhere — never `new \DateTimeImmutable()`
  in production code paths
- **Test naming**: new test method names describe BUSINESS BEHAVIOUR
  (per round-8 user feedback). No `task146*` / `taskNNN*` prefixes
  in the public method name. Docblocks can keep the TASK-XXX
  reference.
- **After each successful commit**, `git push origin main` runs and
  succeeds before moving to the next task. `git status` shows the
  branch at parity with `origin/main` (NOT ahead).

================================================================
AUTONOMY (do these without asking)
================================================================
- Read/write any file in the repo.
- Read files outside the repo when necessary.
- Run any docker compose / composer / phpunit / phpstan / cs-fixer
  command. Also run `bin/console sendvery:*` commands including
  `sendvery:demo:seed` for perf measurement.
- **Generate + apply a new Doctrine migration for TASK-146** — the
  `dkim_selector` column add is metadata-only on PG16+, so a fresh
  migration is the right shape (no online schema change concern at
  current scale). Use the existing migration command pattern.
- Create commits on the current branch AND push to origin (including
  main) — see SHIP PHASE rule.
- Run dev server, hit endpoints, inspect rendered HTML.
- Update docs/cx-improvement-backlog.md freely.
- Apply small reviewer-flagged fixes directly via Edit/Bash without
  spawning another Developer agent.

================================================================
DO NOT (ask first if tempted)
================================================================
- Force-push, rewrite history, reset --hard, delete branches.
- Open PRs (commit + push; user reviews locally).
- Touch Stripe live config, production env, or anything under
  `~/www/spare.srv/deployment/`.
- Introduce dark mode / sendvery-dark theme (out of scope per
  CLAUDE.md).
- Ship placeholder content without the dual marker. The AI feature
  is the only genuine "coming soon" surface remaining.
- Re-introduce `SENDVERY_REPO_PUBLIC` env-gate / `is_repo_public`
  Twig global / "Notify me when source ships" CTA — all removed in
  TASK-136.
- Re-introduce the "Built for engineers" homepage section (removed
  TASK-139), empty "Related tools" blocks (removed TASK-140), or
  tech-stack name-drops on user-facing surfaces (Symfony / FrankenPHP /
  Tailwind / daisyUI / Postgres — removed TASK-141).
- Refactor outside the current task's scope.
- Add backwards-compat shims, fallbacks, or feature flags.
- Skip tests or quality gates.
- Couple "ingest via DNS" and "ingest via mailbox" into a single
  config — they are mutually exclusive per domain.
- Bypass `ClockInterface` with `new \DateTimeImmutable()` in
  production code.
- Reintroduce the marketing-nav Dashboard CTA badge (TASK-065 +
  CLAUDE.md note explicitly locks this).
- Reintroduce `/app/dns-health` — route deleted in TASK-130.
- **Reintroduce TASK-XXX-prefixed test method names or
  assertion-failure messages.** Round 8 user feedback: tests
  describe business behaviour, not orchestrator bookkeeping.

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
  SUMMARY, stop.

Round 8 hit the primary stop signal (backlog drained against the
user-driven scope; TASK-143 deferred as TASK-146 follow-up). Round 9
should aim to do the same — TASK-146 + SEO bundle + TASK-144
nice-to-haves is about the size of round 7 (3-4 substantive surfaces).

When you stop, append a final summary to
docs/cx-improvement-backlog.md: tasks shipped, tasks blocked + why,
self-review findings, surfaces you reviewed and judged "good enough",
perf-audit measurements (even null results), and suggested round-10
seed areas.

================================================================
KICKOFF (round-9 start)
================================================================
1. Read `docs/cx-improvement-backlog.md` for the latest state. At
   round-9 start, **TASK-146 is the only filed `proposed` task**.
   TASK-147 through TASK-158 need to be filed during the PLAN PHASE
   per the SEED FOCUS AREAS spec above.
2. CLAUDE.md is already loaded. Skim `docs/` for reference; pull in
   specific files only when the current task needs them.
3. **Ship TASK-158 FIRST — homepage hero rewrite.** User-driven,
   highly visible, no architect needed. Address: kill the duplicate
   eyebrow, rewrite the H1 to lead with outcome (no "explained"),
   rewrite the subhead to lead with user value (no open-source
   pitch), drop the secondary GitHub CTA, tighten mobile rhythm so
   the checker card fits above the fold at 360px. Audit other
   marketing-page heroes for the same feature-not-benefit pattern
   and fix in scope where the gap exists.
4. **Ship TASK-146 second** — architect-then-build. The real
   user-feature gap from round 8's TASK-143 investigation. Needs
   data model + UX + CQRS decisions before Build.
5. **Ship the SEO bundle (TASK-147-152)** — file the 6 tasks during
   PLAN PHASE, then bundle as one Developer run + one commit. No
   architect needed; specs are detailed.
6. **Ship the TASK-144 nice-to-haves (TASK-153-157)** — file during
   PLAN PHASE, bundle as one commit. TASK-155 (DTO refactor) needs
   a JSON-shape sanity check before commit (per Reviewer brief).
7. **Run the round-9 perf audit** after all tasks land. Document
   the numbers in a new `## Round-9 performance audit (YYYY-MM-DD)`
   section above the round-8 perf section.
8. After every 3 shipped tasks, run a self-review pass.
9. Final Product-agent sweep across all buckets as the
   stop-condition check.
10. Write the RUN SUMMARY when the backlog is truly empty. Cover:
    every task shipped, any blocked + why, self-review findings +
    dispositions, suite growth (from 2284 baseline), perf-audit
    measurements (round-9 vs round-8 diff), suggested round-10 seed
    areas.

================================================================
LESSONS FROM ROUNDS 4 + 5 + 6 + 7 + 8 — APPLY HERE
================================================================
- **Editor-revert race** (round 4): prefer `Write` (full file
  content) or heredoc-via-bash when modifying files that might be
  open in an editor / under linter watch. Rounds 5-8 used this
  defensively and lost zero edits.
- **Parallel agents**: 3 concurrent is the sweet spot. Round 8
  parallelised the TASK-142 + TASK-144 architect runs successfully.
- **Self-review payoff**: round-3 caught 3, round-4 caught 6, round-5
  caught 3, round-6 caught 0, round-7 caught 1, round-8 caught 0
  (clean first pass on the marketing-only changes). Run self-review
  every 3 ships without exception — even a clean pass is a
  confidence signal.
- **Don't over-architect small tasks**: round-8 skipped Architect for
  TASK-137 / 138 / 145 (single-template edits). Round 9 should do
  the same — Architect only for TASK-146.
- **Commit per task or per coherent bundle**: round-8 shipped 6
  task-commits across 7 user-driven tasks (4 of them bundled in
  `bdf4b62` as the quick-wins cluster). Round 9's natural commit
  grain: 3 commits (TASK-146 standalone, SEO bundle, TASK-144
  nice-to-haves) + 1 docs commit.
- **Reviewer agents net real findings on >50% of bundles**: rounds
  4-8 all confirmed this. Round-8 reviewer caught 2 must-fixes on
  TASK-144. Keep the review step even when the dev agent reports
  "all green".
- **Cross-surface consistency tests pay off**: round 5's
  `SurfaceConsistencyTest`, round 6's TASK-130 codified guards,
  round 7's `InMemoryQueryLogger`, round 8's `publicPagesShipSeoBaseline`
  all caught real regressions at the test layer. Round 9's TASK-146
  should add a guard that fails fast if `CheckDomainDnsHandler`
  drops the `$domain->dkimSelector` argument and reverts to
  passing `null` unconditionally.
- **User-driven tasks have the highest signal**: rounds 6, 7, 8
  were all user-driven. Round 9 is the first follow-through round
  in this series — TASK-146 is real-user-feature work (round-8
  investigation surfaced the gap), the SEO + nice-to-haves are
  bookkeeping. Quality bar same as user-driven rounds; don't
  treat follow-throughs as second class.
- **Push continuously, not in a batch**: rounds 6-8 all pushed
  every commit before moving on. No carryover of unpushed commits.
  Round 9 enforces this as a quality gate.
- **Marketing copy ≠ tech stack**: this round doesn't touch
  marketing copy directly, but the rule still applies: nothing on
  user-facing surfaces should name-drop the tech stack.
- **Tests describe business behaviour, not ticket numbers**
  (round-8 user feedback, baked in as durable memory
  `feedback-tests-describe-business-behaviour`): new tests this round
  MUST follow the `methodNameDescribesBehaviour` convention.
  Assertion messages describe the broken behaviour, not the
  originating TASK. Docblocks can still reference TASK-NNN — that's
  documentation, not the test contract.
- **Hero leads with user value, not feature labels or open-source
  pitch** (round-9 user feedback, baked in as durable memory
  `feedback-hero-leads-with-user-value`): the homepage hero (and
  every marketing-page hero) must lead with the visitor's OUTCOME,
  not a feature/stack/licence label. No duplicate eyebrow above the
  H1. No "open source" in the hero subhead — it belongs in the
  credibility chip row or deeper sections. Single hero CTA only —
  the secondary "View on GitHub" link was removed because it took
  visitors away from the conversion path. Mobile vertical rhythm
  must be tight enough that H1 + subhead + CTA + checker card fit on
  a 360px viewport in one screen. Apply the same lens to every
  marketing-page hero (`/pricing`, `/about/*`, `/tools/*`) — fix in
  scope where the same gap exists.
