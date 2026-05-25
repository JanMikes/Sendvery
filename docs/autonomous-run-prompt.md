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
team and flagged seven issues. The work splits into four threads:

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

3. **IA merge (one refactor — SHIPPED in TASK-130).** `/app/domains`
   now absorbs the former `/app/dns-health` surface. The 4-card summary
   (Domains monitored / Fully healthy / Need attention / Awaiting first
   check), the per-card letter grade, and the SPF/DKIM/DMARC/MX
   protocol badges all live on the merged `/app/domains` page. The
   `/app/dns-health` route is gone (returns 404); the sidebar lost its
   standalone "DNS Health" entry. The per-domain drill-down at
   `/app/domains/{id}/health` stays — different scope (overview vs
   detail). No backwards-compat redirect was introduced.

4. **Homepage hero rework (visual polish).** The current hero copy
   ("Email authentication is set once and forgotten") + the standalone
   DNS-checker section feel boring next to the dashboard polish round
   4-5 added. The user wants a designed hero that lives up to the
   product the screenshot below it shows.
   - **TASK-131**: rebuild the top of `/` as three sequential sections
     — a two-column hero that ABSORBS the standalone DNS checker, a
     centered "XML → plain English" explainer, and an A–F grade
     showcase. Monochrome zinc palette + semantic state colors,
     `font-medium` ceiling, sentence case throughout, no gradients /
     shadows / blobs. Reuse the existing Stimulus DNS-checker
     controller verbatim — only the visual shell changes.

5. **Performance audit (round-6 baseline diff).** Round 5 captured a
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
that DUPLICATE (TASK-130 merge), stops the dashboard lying about the
user's state (TASK-125 / 126 / 128 / 129), gives the user the inline
diff they need to read history confidently (TASK-127), and brings the
homepage's visual register up to match the dashboard screenshot it
already carries (TASK-131).

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
Four buckets. The order below is the SHIP ORDER. Bucket 1 is the
small mechanical wins (4 bug fixes), bucket 2 is one feature, bucket 3
is the dashboard refactor, bucket 4 is the marketing hero rework.

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

3. **IA MERGE — /app/dns-health into /app/domains** (TASK-130 — SHIPPED)

   **TASK-130** — done. `/app/domains` now carries the 4-card summary
   (Domains monitored / Fully healthy / Need attention / Awaiting first
   check), the per-card grade chip, the SPF/DKIM/DMARC/MX protocol
   badges, the `?status=unchecked` filter, and a single "DNS Health →"
   footer link per card deep-linking to `/app/domains/{id}/health`.
   The `/app/dns-health` route, controller (`DnsHealthOverviewController`),
   template (`dns_health_overview.html.twig`), integration test
   (`DnsHealthOverviewTest`), and sidebar entry are all deleted. Two
   codified regression guards in `DomainsWithDnsHealthTest` sweep
   `templates/` and `src/` for any surviving `dashboard_dns_health`
   reference. Badges render as NON-INTERACTIVE styled spans (HTML-validity
   call: card root is a stretched-link `<a>` so nested anchors would be
   invalid) — only the footer "DNS Health" button is a real anchor,
   carrying `relative z-10` so the stretched-link does not eat its
   click. `GetDnsHealthOverview` stays alive feeding both the merged
   `/app/domains` page (`forTeams()`) and the per-domain detail header
   badge row (`forDomain()`).

4. **HOMEPAGE HERO REWORK** (TASK-131)

   **TASK-131** — Rebuild the top of the homepage as three sequential
   sections (hero with embedded DNS checker, "XML → plain English"
   explainer, A–F grade showcase). The standalone "Check your domain"
   section is ABSORBED into the hero — there's no more separate checker
   block. The existing trust-logos row stays between hero and explainer.
   Everything below "What Sendvery catches that nobody else does"
   stays untouched.

   **Discovery first** (the dev agent's first step):
   Locate the homepage template (`templates/homepage/index.html.twig`
   per round-5 work, but verify) and the existing DNS-checker form +
   Stimulus controller (currently at `#dns-checker`). Reuse the
   checker's endpoint, response handling, and result logic VERBATIM —
   only the visual shell changes. Confirm file paths before editing.

   **Section 1 — Hero (replaces current hero AND absorbs the standalone
   domain-check section).** Two-column at `md+`, stacked on mobile.

   Left column:
   - Eyebrow: `text-xs uppercase tracking-wider text-zinc-500` —
     "DMARC · DNS · deliverability"
   - Headline `<h1>`: `text-4xl md:text-5xl font-medium tracking-tight
     leading-[1.1] text-zinc-900` — "DMARC, DNS, deliverability —
     monitored and explained."
   - Subhead: `text-base md:text-lg text-zinc-500 max-w-xl
     leading-relaxed` — "Sendvery is the open-source email
     deliverability platform that watches your DNS 24/7, parses your
     DMARC reports, and translates the XML into plain English. Free
     for 1 domain."
   - CTAs (row, `gap-3`):
     - Primary: "Get started free" → `/login`. `bg-zinc-900 text-white
       rounded-md px-4 py-2 text-sm font-medium hover:bg-zinc-800`
     - Secondary: "View on GitHub →" → use the existing
       `SENDVERY_GITHUB_*` env-driven URL (verify the Twig global
       name — it's wired through `OpenSourceExtension` per TASK-122).
       Class: `border border-zinc-300 rounded-md px-4 py-2 text-sm
       font-medium hover:bg-zinc-50`
   - Trust line below CTAs: `text-xs text-zinc-400` — "Open source ·
     AGPL-3.0 · 1 domain free forever · Self-hostable"

   Right column — live checker visually integrated:
   - Card: `bg-zinc-50 border border-zinc-200 rounded-lg p-5`
   - Label: `text-xs uppercase tracking-wider text-zinc-500` —
     "Free instant check — no signup"
   - Input + button row (`flex gap-2 mt-3`): monospace input
     (`font-mono text-sm`, placeholder `yourdomain.com`), primary
     "Check" button
   - Result area (`mt-4`, `aria-live="polite"`): four chips in a row
     — SPF / DKIM / DMARC / MX. Pass = `bg-emerald-50 text-emerald-700`,
     warn = `bg-amber-50 text-amber-700`, fail = `bg-red-50
     text-red-700`. Each chip: `inline-flex items-center gap-1
     rounded-md px-2 py-0.5 text-xs font-medium`. Lucide check/alert
     icon at `w-3 h-3`. Include `<span class="sr-only">` text naming
     the state for screen readers.
   - One-line plain-English summary below chips (`text-xs text-zinc-600
     mt-3`) using whatever the API returns. Fallback example: "Grade
     C — DMARC is on `p=none`. Anyone can spoof you."
   - **Reuse the existing Stimulus controller** — wire it to this
     new markup, DO NOT rebuild it.

   Background: subtle dotted grid only on the hero container —
   `bg-[radial-gradient(circle,theme(colors.zinc.200)_1px,transparent_1px)]
   bg-[length:24px_24px]`. No gradients, blobs, or imagery anywhere.

   **Section 2 — From XML to plain English.** Centered, narrow column.
   Replaces the current "Email authentication is set once and forgotten"
   section.

   - Eyebrow (centered): "How the AI insights work"
   - Headline `<h2>` (centered, `text-3xl md:text-4xl font-medium
     tracking-tight max-w-2xl mx-auto`): "DMARC reports are written
     for machines. We translate them for you."
   - Sub (centered, `text-zinc-500 max-w-xl mx-auto mt-3`): "Sendvery
     parses the XML, watches DNS continuously, and gives you one
     sentence that tells you exactly what to fix."
   - 3-column transformation visual at `md+` (`grid
     md:grid-cols-[1fr_auto_1fr] gap-4 mt-10 max-w-3xl mx-auto`):
     - Left card: raw DMARC XML in `bg-zinc-50 border border-zinc-200
       rounded-lg p-4 font-mono text-xs text-zinc-500 leading-relaxed`.
       Sample content (a small `<record>` snippet showing
       `<policy_evaluated>` with `disposition=none`, `dkim=fail`,
       `spf=pass`).
     - Middle: Lucide `ArrowRight` icon, `w-5 h-5 text-zinc-400`,
       centered.
     - Right card: `bg-blue-50 border border-blue-200 rounded-lg p-4`.
       Label row with sparkles icon (`w-3 h-3`) + "AI summary" in
       `text-xs font-medium text-blue-700`. Below: `text-sm
       text-blue-900` — "A Mailchimp send from your marketing
       subdomain failed DKIM. SPF alone won't pass alignment — add
       the Mailchimp selector to fix it."
   - Mobile (`< md`): stack vertically, swap right-arrow for
     `ArrowDown`.

   **AI placeholder note**: per DEC-057 (AI stub-first launch
   posture — see `~/.claude/projects/-Users-janmikes-www-dmarc/memory/ai-stub-first-launch-posture.md`),
   the "AI summary" example here is illustrative copy. The visual
   commits to the AI value proposition without claiming AI insights
   are live — the real `AnthropicAiInsightsService` ships post-launch.
   That's fine for marketing-side hero copy as long as it doesn't
   contradict the gated state elsewhere on the site. Add the dual
   marker (`// TODO(placeholder)` + `config/placeholders.php` entry)
   per the TASK-023 convention if the sample copy is hardcoded in the
   template.

   **Section 3 — Your domain, one letter.** Two-column at `md+`,
   stacked on mobile. Pairs naturally with the existing A–F legend
   already on the page (keep that legend either inside section 3 or
   directly below — orchestrator/dev's judgment).

   Left:
   - Eyebrow: "Email authentication, scored"
   - Headline `<h2>`: "One letter tells you if your email is at risk."
   - Sub: "DMARC reports, DNS health, and AI insights rolled into a
     single A–F score per domain. No XML, no guesswork."
   - CTAs: "Check your domain's grade" → `/tools/domain-health`
     (primary), "How grading works" → existing relevant `/learn`
     article (secondary)

   Right — grade card mockup (`bg-white border border-zinc-200
   rounded-lg p-5 max-w-sm`):
   - Top row (`flex items-center gap-3`): grade tile (`w-14 h-14
     bg-emerald-50 text-emerald-700 rounded-md flex items-center
     justify-center text-3xl font-medium` containing `A`) next to a
     column with `font-mono text-zinc-500 text-sm` `acme.io`, `text-sm
     font-medium mt-0.5` "98.4% pass rate", `text-xs text-zinc-400
     mt-0.5` "last 30 days · 12 reports"
   - Below (`mt-4 flex gap-1.5 flex-wrap`): four emerald pass chips —
     SPF, DKIM, DMARC, MX.

   **Design constraints — apply everywhere in the three new sections:**
   - Monochrome zinc palette (`50, 100, 200, 300, 400, 500, 700, 900`).
     Semantic colors (`emerald`, `amber`, `red`, `blue`) only for
     state chips and the AI summary card.
   - Font weights: `400` and `500` only — never `600`/`700`/`800`.
     This is a deliberate departure from daisyUI's heavier default
     headings; the dev agent should override theme-driven heading
     weights with explicit `font-medium` on the H1/H2 elements.
   - Sentence case in all copy, including buttons.
   - No gradients, shadows, blur, decorative SVG blobs.
   - Radii: `rounded-md` for buttons and chips, `rounded-lg` for
     cards.
   - Section vertical rhythm: `py-16 md:py-24`. Outer container:
     `container mx-auto px-4 md:px-6`.

   **Accessibility:**
   - All interactive elements have visible focus rings:
     `focus-visible:ring-2 focus-visible:ring-zinc-900
     focus-visible:ring-offset-2`.
   - Result chips include screen-reader text naming the state.
   - Live checker result area has `aria-live="polite"`.
   - Hero is `<h1>`; sections 2 and 3 use `<h2>`.

   **What stays untouched:**
   - The existing trust-logos row (TheDevs.cz / SpeedPuzzling.com /
     FajneSklady.cz) — keep it, place it between hero and section 2
     with `text-xs text-zinc-400` styling.
   - The "What Sendvery catches that nobody else does" section and
     everything below it.
   - Meta tags / OG image — current copy still aligns.
   - The round-5 dashboard screenshot at the homepage's section 4.5
     (TASK-120) — stays as-is, it's already a strong product preview.

   **Acceptance:**
   - Renders correctly at 320 / 768 / 1024 / 1440 px widths. Verify
     via curl + HTML inspection at multiple viewports if possible,
     or via responsive-snapshot test if the project has one.
   - Live checker behaves IDENTICALLY to before — only the shell is
     new. Reusing the existing Stimulus controller means the existing
     checker integration tests should pass unmodified.
   - No layout shift when checker results render — reserve space or
     use `min-h-*` on the result area.
   - Old hero markup AND the standalone `#dns-checker` section are
     REMOVED from the template. The checker now lives inside the hero.
     Grep confirms no surviving `id="dns-checker"` outside the hero.
   - Functional test asserts: the new `<h1>` text "DMARC, DNS,
     deliverability — monitored and explained." renders; the live
     checker form is INSIDE the hero `<section>` element; the trust
     logos row sits between hero and section 2; section 2's eyebrow
     "How the AI insights work" renders; section 3's headline "One
     letter tells you if your email is at risk." renders; the grade
     card mockup contains "acme.io" and "98.4% pass rate".
   - Lighthouse / axe accessibility check ≥ 95 (the dev agent should
     verify if tooling is reachable; otherwise rely on the manual
     accessibility-rules checklist above).
   - Existing homepage tests that asserted the OLD hero copy ("Email
     authentication is set once and forgotten") get UPDATED to assert
     the new copy. No orphan tests asserting deleted markup.

   **Visual-snapshot output for the user:**
   The user explicitly asked: "Show me the diff and the rendered HTML
   of all three new sections before considering it done." The
   orchestrator honors this by including in the commit message a
   brief markdown excerpt of the rendered output of each new section
   (curl the deployed local dev → strip down to the three section
   blocks → paste in the commit body). This lets the user review the
   HTML without leaving git.

   Notes:
   - TASK-131 is the second-biggest task this round (~2-3 hours).
     Spec is detailed enough to SKIP Architect — straight to Build.
   - The "AI summary" card in section 2 lives next to DEC-057's
     stub-first AI launch posture. Don't add real AI calls; the
     example copy is illustrative only.
   - The "View on GitHub →" CTA links to whatever env-driven URL
     `OpenSourceExtension` exposes (TASK-122 wired this). If the
     repo isn't public yet, the same gate applies — link to the
     notify CTA instead.

5. **PERFORMANCE AUDIT** (round-6 baseline diff)

   Round 5 captured a perf-audit snapshot. After TASK-130 + TASK-131
   ship, re-run the same `EXPLAIN ANALYZE` measurements:
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

6. **ROUND-6 SELF-REVIEW** (every 3 shipped tasks)

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
   - **TASK-130**: DONE. The `/app/dns-health` route is gone (404s
     by design); the two codified guards in `DomainsWithDnsHealthTest`
     keep templates/ and src/ free of stale `dashboard_dns_health`
     references. KB articles / marketing pages / onboarding flows
     should be re-grepped if changed.
   - **TASK-131**: the `font-medium` ceiling fights against daisyUI's
     default heading weights — verify the rendered HTML actually uses
     400/500 weights by inspecting class lists, not just by reading
     the template. The dotted-grid background should not bleed into
     adjacent sections — check the section boundaries.
   - **TASK-131** AI-summary card: contains marketing claim about AI
     translating XML. Verify this doesn't contradict the gated state
     elsewhere — if AI is stub-only per DEC-057, the homepage shouldn't
     promise it's already running.

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
TASK-125 through TASK-131. Self-review findings or post-TASK-131
perf-audit follow-ups start at TASK-132.

This file survives compaction; ALWAYS read it before deciding what to
do next and ALWAYS update it after each phase transition.

Round 6's seed tasks are NOT yet filed at run start — you (or a
Product agent) MUST file TASK-125 through TASK-131 into the backlog
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
   TASK-130, then TASK-131. The Product agent is only needed for the
   stop-condition sweep at the end — the round-6 seed work is already
   specified.

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
   Run quality gates. If green: **commit AND push to `origin/main`**,
   then mark `done`. Commit per task (or per coherent bundle) —
   round-5 shipped 12 task-commits + 5 docs-commits = 17 total, which
   made the git log readable and `git revert <task>` safe.

   **Push continuously, not at the end.** After every successful
   commit, run `git push origin main` before moving to the next task.
   Round 5 ended with 32 unpushed local commits — the user had to
   push them manually at the end. Don't repeat that. The flow is:
   `git commit` → `git push origin main` → mark backlog status `done`
   → next task. If a push fails (network blip, remote moved ahead),
   investigate before continuing: `git pull --rebase origin main`
   first when remote diverged, then re-push. Never `--force` to
   resolve a divergence — investigate the conflict.

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
For TASK-130 (shipped): verify the codified guards in
`DomainsWithDnsHealthTest::noTemplateReferencesDashboardDnsHealthRoute`
+ `noControllerOrServiceReferencesDashboardDnsHealthRoute` remain
green — any new template/controller introducing
`path('dashboard_dns_health')` would be caught there.
For TASK-131 specifically: verify the standalone `#dns-checker`
section has been REMOVED (not just hidden), the new hero uses
explicit `font-medium` on H1/H2 (not inherited heavier weights),
the dotted-grid background is scoped to the hero container only,
the AI-summary card copy doesn't contradict DEC-057's stub-first AI
posture, and the existing Stimulus controller hasn't been rebuilt
(grep for new Stimulus controllers — only template + result-shell
changes are allowed)."

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
- For TASK-130 (already shipped): `grep -r "dashboard_dns_health" templates/ src/ config/`
  returns nothing. The codified guards in `DomainsWithDnsHealthTest`
  enforce this — any new offender fails the test run. CLAUDE.md prefers actual deletion
  over removal comments)
- **After each successful commit**, `git push origin main` runs and
  succeeds before moving to the next task. `git status` shows the
  branch at parity with `origin/main` (NOT ahead). Round 5 violated
  this — round 6 enforces it as a quality gate.

================================================================
AUTONOMY (do these without asking)
================================================================
- Read/write any file in the repo.
- Read files outside the repo when necessary.
- Run any docker compose / composer / phpunit / phpstan / cs-fixer
  command. Also run `bin/console sendvery:*` commands including
  `sendvery:demo:seed` for perf measurement.
- Create commits on the current branch AND push to origin (including
  main) — see the SHIP PHASE rule that you push CONTINUOUSLY after
  every successful commit, not in a final batch.
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
- Re-introduce `/app/dns-health` — the route was deleted in TASK-130
  per the user's explicit "we do not need keep backward compatibility,
  just migrate the functionality" instruction. Adding a redirect or an
  alias controller would also fail the codified guards in
  `DomainsWithDnsHealthTest`.

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
should do the same — the scope is tighter (7 user-driven tasks +
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
   129 / 130 / 131) into the backlog using the acceptance criteria
   from §SEED FOCUS AREAS. Each must include the user-supplied moment
   of confusion in the Why field — the user's own words from the
   feedback when possible (these are the most authentic descriptions
   of the bug). Order in the file: TASK-125 → 126 → 127 → 128 → 129
   → 130 → 131 (numeric, matches their natural narrative).
4. **Ship the bug-fix bundle first** (TASK-125 / 126 / 128 / 129).
   Three of the four touch different surfaces (dns history vs onboarding
   card vs next-step card) so they can ship in parallel. TASK-126 + 125
   both touch the DNS history page — bundle them under ONE agent
   to avoid edit collisions.
5. **Ship TASK-127 (DNS history diff)** next. Touches the same page
   as 125 + 126 but adds a new service + new rendering branch — no
   collision if shipped sequentially.
6. **Spawn Architect agent for TASK-130 (IA merge)** — biggest single
   dashboard task this round, deletion cascade needs careful
   sequencing. Then ship.
7. **Ship TASK-131 (homepage hero rework)** — can run in parallel with
   TASK-130 (entirely different files: homepage template + Stimulus
   wiring vs domains controller + DNS-health deletion). Spec is
   detailed enough to skip Architect — straight to Build. The dev
   agent should report rendered HTML excerpts of all three new
   sections in their final report; the orchestrator passes those
   through into the commit message so the user can review the diff
   without leaving git.
8. **Run the round-6 perf audit** after TASK-130 + TASK-131 land.
   Document the numbers in a new `## Round-6 performance audit
   (YYYY-MM-DD)` section above the round-5 perf section. Compare
   against round-5 baseline — flag any >5ms regression.
9. After every 3 shipped tasks, run a self-review pass.
10. Final Product-agent sweep across all buckets as the
    stop-condition check.
11. Write the RUN SUMMARY when the backlog is truly empty. Cover:
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
- **Push continuously, not in a batch**: round 5 made 19 commits but
  pushed zero of them — the user had to push 32 commits manually at
  round end (round-4's 13 carryover + round-5's 19). Round 6 fixes
  this by treating `git push origin main` as the second half of every
  ship phase. Local-only commits aren't shipped — pushed commits are.
  This also means the user can pull intermediate progress instead of
  waiting for the whole round to complete.
