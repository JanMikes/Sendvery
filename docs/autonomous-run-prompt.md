# Autonomous CX/Product Improvement Run — Sendvery (Round 8 continuation: homepage visual polish + DKIM editability + SEO + DNS helper forms + marketing narrative)

You are the ORCHESTRATOR. Your job is to autonomously improve Sendvery's
marketing surfaces + dashboard by running a continuous loop of
specialised subagents. You self-pace. You DO NOT stop to ask the user
for permission on anything covered by "Autonomy". You DO NOT stop until
the backlog is genuinely empty (Product agent confirms nothing more is
worth doing) or you hit a real blocker in "Stop conditions".

================================================================
CHECKPOINT — WHAT THE PREVIOUS SESSION SHIPPED (read this first)
================================================================
Round 8 was started in a previous session and **partially drained**.
The four mechanical quick-wins shipped under one commit
(`bdf4b62`):

- **TASK-136** — Repo-public env gate retired EVERYWHERE.
  `SENDVERY_REPO_PUBLIC` deleted from `.env` + `.env.test`;
  `is_repo_public` Twig global deleted from `OpenSourceExtension`;
  every `{% if is_repo_public %}` branch collapsed to just the
  github-link branch; "Notify me when source ships" CTA + the
  `homepage-hero-repo-launch` / `open-source-repo-launch` tracking
  strings deleted. `/about/open-source` quickstart unconditionally
  shows `git clone`. Homepage hero secondary CTA = "View on GitHub →".
- **TASK-139** — "Built for engineers" homepage section deleted
  entirely (+ the AGPL/stars badge that lived in it).
- **TASK-140** — Empty "Related tools" chip strip stripped from
  every `/tools/*` page (8 pages). Case-insensitive regression pin
  in `ToolPagesTest`.
- **TASK-141** — Footer "Built with Symfony & FrankenPHP" replaced
  with "Built with love by Jan Mikeš · Source on GitHub →". Scope-
  creep scrub also rewrote `/about/open-source` "PHP 8.5 + Symfony 8
  application" → "Application code — CQRS commands and queries…"
  (genuine improvement, not whitewashing).

Suite at session-pause: **2272 tests / 6688 assertions**, all gates
green, all commits pushed to `origin/main`.

The user paused the session after the quick-wins bundle landed and
asked for the rest of round 8 to be picked up by a future invocation
of this doc. They also confirmed an open scope question:

- **TASK-144 v1 scope = ALL 4 GENERATORS** (SPF + DMARC + DKIM + MX),
  NOT the spec's original "SPF + DMARC only, defer DKIM + MX".
  Rationale: DKIM has the highest "help me set this up" demand
  despite the awkward UX (selector + public-key bytes), and MX is
  cheap to throw in alongside.

================================================================
MISSION
================================================================
Round 7 closed cleanly with the round-6 follow-through (TASK-132 →
TASK-135) all shipped and all-green at 2274 tests / 6687 assertions
(see the round-7 RUN SUMMARY in `docs/cx-improvement-backlog.md` for
detail). Round 8 is **another user-driven round** — same account holder
([j.mikes@me.com](mailto:j.mikes@me.com)) opened the live site + dashboard
after round 7 and surfaced ten things that still feel off. Four of
those ten shipped in the previous session (see CHECKPOINT above);
this invocation picks up the remaining six. The work splits into five
threads:

1. **Stop apologising for things that are already done.** The
   open-source repo is PUBLIC at github.com/janmikes/sendvery. Every
   page that still says "Notify me when the source ships" / "Coming
   soon — repo opens at launch" / wraps GitHub links behind the
   `is_repo_public` env gate is now lying — those CTAs have to point at
   the real GitHub URL. The ONLY genuine "coming soon" item left is
   **AI Insights**, which only waits for an Anthropic API key + a
   final test pass. DEC-057's stub-first posture still applies to the
   AI feature, but NOT to the repo-public claim.
   - **TASK-136**: retire the repo-public gate entirely. The
     `SENDVERY_REPO_PUBLIC` env-var + `OpenSourceExtension`
     `is_repo_public` Twig global + every `{% if is_repo_public %}`
     branch in templates can go. The Open Source page (`/about/open-source`)
     + the homepage hero secondary CTA + any other notify-me CTAs
     pointing at the repo all switch to the unconditional GitHub
     link. The notify-me mailto and `homepage-hero-repo-launch`
     tracking-source string are deleted.

2. **The most-seen surface (homepage hero) has a font register
   mismatch with the sections below it.** TASK-131 introduced the
   zinc-palette + explicit `font-medium` ceiling on the first three
   sections; sections 4+ kept daisyUI's heavier default headings.
   First-impression visitors see the seam. Fix the inconsistency.
   - **TASK-137**: pick ONE register and apply it page-end-to-end on
     `/`. Either continue zinc-palette + `font-medium` for every
     section heading, OR retire the zinc-palette override and pick a
     consistent daisyUI register top-to-bottom. The user explicitly
     called this out: "this is the most important part of the marketing
     page because everyone sees this on first sight." Recommend
     continuing TASK-131's lighter register for visual coherence with
     the dashboard polish.
   - **TASK-138**: the "How it works" section uses custom illustration
     assets (`how-connect.webp`, etc.). Drop the custom illustrations
     and replace with consistent Lucide icons or daisyUI-styled icon
     tiles so the section visually agrees with the hero's zinc
     register. Asset files can be deleted if no other surface uses
     them.

3. **Kill clutter that adds no value.** Three small deletions:
   - **TASK-139**: remove the "Built for engineers" section from the
     homepage completely. User: "We might remove the 'Built for
     engineers' from homepage completely i think."
   - **TASK-140**: every `/tools/*` page has a "Related tools"
     section at the bottom — and the user reports it's empty on
     `/tools/spf-checker` and others. Either populate it correctly OR
     remove it (user preference: remove). Audit every public tool
     page and strip the empty block.
   - **TASK-141**: the footer says "Built with Symfony & FrankenPHP".
     End users don't care about the tech stack and exposing it is bad
     marketing posture. Replace with "Built with love by Jan Mikeš"
     + a link to GitHub (and/or personal site if applicable). Verify
     there's no other "Built with <tech>" copy lingering anywhere
     (Symfony, FrankenPHP, daisyUI, Tailwind, Postgres, etc.) — strip
     all such tech-stack name-drops from user-facing surfaces.

4. **SEO and narrative.** Two strategic threads:
   - **TASK-142**: SEO audit + improvements. Look at every public
     page and verify: meta `<title>` + `<meta description>` per page
     (not just one global copy), Open Graph image per page (or a
     sensible default), structured data per page-type (Organization
     on home, Product on pricing, Article on /learn/*, SoftwareTool
     on /tools/*), internal linking density (every page should link
     out to related public pages within ~2 clicks), `robots.txt` +
     `sitemap.xml`, canonical URLs, heading hierarchy (one H1 per
     page, proper H2-H6 nesting). File specific fixes inline; ship
     the highest-leverage wins. Skip anything that needs marketing
     copy decisions — surface those as follow-ups.
   - **TASK-145**: homepage narrative pass. User said the section
     order should "make sense, maybe put pricing slightly higher, but
     there should be clear story / flow from top to bottom reasoning
     why the sections are in such order — follow best practices."
     Re-sequence the homepage with explicit per-section rationale.
     Suggested skeleton (final order is the orchestrator's call after
     reading the current page): hero → trust → problem framing →
     solution (XML→English + grade card) → social-proof (dashboard
     screenshot from TASK-120) → pricing → FAQ → final CTA. Audit
     surrounding pages (`/pricing`, `/about/what-is-sendvery`,
     `/learn`, `/tools/*`, `/open-source`) for the same narrative
     coherence — if a page's flow contradicts the homepage story, fix
     in scope.

5. **Dashboard bug.** One trust-eroding bug surfaced by real use:
   - **TASK-143**: in the dashboard, the user cannot change a domain's
     DKIM selector once it's been saved the first time. The form
     locks the field. User: "this is important!" Find the read-only
     branch in the DKIM-selector form/controller (likely
     `src/Controller/Dashboard/*` + a `templates/dashboard/*` form
     template), add an edit path that re-runs the existing DNS
     verification trigger so changing the selector immediately
     re-verifies DKIM against the new selector value.

6. **DNS helper-form feature (v1 scoped — exploratory).** User asked:
   "could there be helper forms to set up dns records format for spf,
   dkim etc on the public pages?" — phrased as a question, not a
   directive. Ship a v1.
   - **TASK-144**: on the relevant `/tools/*` pages (SPF / DKIM /
     DMARC), add a "Generate the record" helper form. The user enters
     the high-level config (for SPF: their sending services as
     toggles like Google Workspace / Mailchimp / Postmark / etc., or
     a free-form list; for DMARC: policy choice + reporting email;
     for DKIM: selector + public key; for MX: priority + host
     pairs), and the tool generates the canonical TXT/MX record
     string they paste into DNS. Output a `<code>` block + a
     copy-to-clipboard button. v1 covers SPF + DMARC; DKIM and MX
     can be follow-ups if v1 lands well. PURELY client-side
     (Stimulus controller — no API calls). Do not over-engineer
     the include: list — start with ~6 common providers + free-form.

7. **Round-8 performance audit + self-review.** Same rules as rounds
   5-7: re-measure the round-7 baseline queries after marketing-only
   changes (perf delta expected ≈ 0; verify and document). Self-review
   every 3 ships.

================================================================
WHAT IS ALREADY DONE — DO NOT RE-PROPOSE
================================================================
Skim `docs/cx-improvement-backlog.md` first. TASK-001 through TASK-135
with status `done` are shipped. Don't re-propose anything in the seven
run-summary tables. Round 7 specifically shipped:

- **TASK-132** — homepage section 5 Step 1 leads with DNS-first
  ingestion (rua= at Sendvery; mailbox demoted to fallback line).
- **TASK-133** — disconnect-mailbox POST endpoint + soft-delete via
  new `disconnected_at` column + daisyUI confirmation modal.
- **TASK-134** — batch `RuaScenarioResolver::resolveForDomainIds`
  retires the N+1 on the dashboard overview hot path. New
  `InMemoryQueryLogger` middleware (when@test) provides the
  one-query regression net.
- **TASK-135** — self-review-found must-fix:
  `RuaMailboxMatcher::matchesMailbox()` now skips
  disconnected/inactive mailboxes.
- Sidecar de-flake fix for `NextActionResolverTest::resolveConnect
  MailboxWhenNoMailboxAndNoReports` (relative-vs-fixed-date timing
  drift on 7-day boundary).
- Round-7 perf audit: all 8 measured queries SAFE. TASK-134's batch
  query clocks 0.083ms exec at 3-domain demo scale (strict
  improvement over the N+1 it retired).

Round 7 test suite growth: 2256 → 2274 (+18 tests / +72 assertions).

Build on top — don't duplicate.

================================================================
SEED FOCUS AREAS (priority order — SHIP ALL IN THIS ROUND CONTINUATION)
================================================================
Five buckets. The order below is the SHIP ORDER. Bucket 1 (quick-wins)
**already shipped in the previous session** (see CHECKPOINT) — start
at bucket 2.

1. **QUICK-WINS BUNDLE — repo gate retired + 3 small deletes** (TASK-136 / 139 / 140 / 141) — **DONE, commit `bdf4b62`**

   Section kept here for context. Below is the spec as it ran; all
   four are now `done` in the backlog. Skip to bucket 2.



   Ship ALL four under ONE dev agent — they're each small, low-risk
   text edits across the marketing site. Bundled commit makes the
   "killed marketing clutter" change reviewable as one.

   **TASK-136** — retire repo-public gate everywhere.

   The `SENDVERY_REPO_PUBLIC` env-var was a TASK-122-era gate while
   the repo was private. The repo is now PUBLIC at
   `github.com/janmikes/sendvery`. Everything gated on
   `is_repo_public` is lying.

   Acceptance:
   - Delete `SENDVERY_REPO_PUBLIC` from `.env` (default), `.env.test`,
     anywhere else it appears. Default behaviour is "repo is public".
   - Delete the `is_repo_public` Twig global. `OpenSourceExtension`
     either keeps just the `github_url` global (still needed for the
     link target) OR is deleted entirely if `github_url` can be a
     plain Twig constant / parameter.
   - Find every `{% if is_repo_public %}` / `{% else %}` branch in
     templates and collapse to just the github-link branch. The
     notify-me mailto CTA and the `data-notify-source="homepage-
     hero-repo-launch"` / `open-source-repo-launch` tracking strings
     are deleted.
   - `/about/open-source` page — every "Coming soon" / "Notify me"
     copy gets replaced with the active GitHub link. Quickstart can
     unconditionally render `git clone https://github.com/janmikes/sendvery.git`.
   - Homepage hero secondary CTA renders as "View on GitHub →"
     unconditionally (no env branching, no hidden anchor either —
     that fallback shim was already deleted in round 7).
   - Grep guard: `grep -rn "Notify me when" templates/ src/` returns
     ZERO hits. `grep -rn "is_repo_public\|SENDVERY_REPO_PUBLIC"`
     returns zero hits. `grep -rn "Coming soon" templates/` returns
     zero hits EXCEPT where the surface genuinely is coming soon
     (the AI Insights stub — those mentions stay, gated on DEC-057's
     placeholder marker).
   - Test: update existing tests pinning the env-gated branches —
     `heroSecondaryCtaRespectsRepoPublicGate` (TASK-131-era) and any
     `/about/open-source` test that asserts the notify-me variant.
     Replace with assertions that the GitHub URL renders
     unconditionally.

   **TASK-139** — remove "Built for engineers" section from homepage
   completely.

   Acceptance:
   - `templates/homepage/index.html.twig` — find the "Built for
     engineers" section (grep the file for that literal string), delete
     the entire `<section>` block.
   - If the section had its own assets (illustrations, structured
     data), delete those too.
   - Update tests that asserted the section's H2 / body copy — delete
     them outright, NOT comment them out.

   **TASK-140** — strip empty "Related tools" sections from /tools/*
   pages.

   The user reports `/tools/spf-checker` has a "Related tools" block
   with nothing in it. Audit every public tool page and strip the
   block.

   Acceptance:
   - Find every `templates/tools/*.html.twig` (and any shared
     "RelatedTools" component). Identify pages where "Related tools"
     either renders empty OR with a stale/duplicate set.
   - Delete the "Related tools" markup from those pages (user
     preference is REMOVE, not populate).
   - If the "Related tools" component is itself only used by tool
     pages and is now orphaned, delete the component.
   - The footer's "Tools" column already lists every tool, so the
     per-page Related-tools block is redundant anyway.

   **TASK-141** — footer attribution rewrite.

   The footer says "Built with Symfony & FrankenPHP". User: "do not
   mention this. Built with love by Jan Mikeš and link to github etc
   or something, but not symfony & Frankenphp this is not what we
   want to communicate."

   Acceptance:
   - `templates/components/Footer.html.twig` (or wherever the line
     lives — `grep -rn "Symfony & FrankenPHP" templates/`) — replace
     with: `Built with love by <a href="https://github.com/janmikes">Jan
     Mikeš</a> · <a href="https://github.com/janmikes/sendvery">Source
     on GitHub →</a>` (or similar — sentence case, no shouting).
   - Grep for any other "Built with <tech>" / "Powered by <tech>"
     copy elsewhere on user-facing surfaces — strip them too. Tech
     stack name-drops belong in CLAUDE.md, not on the marketing
     site.
   - Test: update the footer test (likely in `MarketingPagesTest`)
     asserting the new attribution string.

2. **MARKETING VISUAL POLISH** (TASK-137 + TASK-138)

   **TASK-137** — homepage font register page-end-to-end.

   Acceptance:
   - Read `templates/homepage/index.html.twig` end-to-end. Identify
     EVERY section heading (`<h2>`) and note its current class set.
   - The first 3 sections (TASK-131) use explicit `font-medium
     tracking-tight text-zinc-900` + zinc-palette eyebrows. Sections
     4+ use daisyUI's default heading weights (usually `font-bold`
     via the theme).
   - Pick ONE register. **Default recommendation: continue the
     TASK-131 zinc-palette + `font-medium` register page-end-to-end.**
     It's the lighter / more modern register and the user explicitly
     liked it ("this is the most important part of the marketing
     page because everyone sees this on first sight" — interpret as
     "don't downgrade the first 3 sections; bring the rest up").
   - Apply the consistent class set to every H2 on the page. If a
     section uses daisyUI components (cards, badges) that have their
     own font conventions, leave the COMPONENT styling but normalise
     the SECTION heading.
   - The `font-medium` ceiling rule from TASK-131 still applies (no
     `font-bold` / `font-semibold` / `font-extrabold` on section
     headings). Body text + button labels can stay as-is.
   - Eyebrow + subhead patterns from TASK-131 (`text-xs uppercase
     tracking-wider text-zinc-500` for eyebrows, `text-zinc-500
     leading-relaxed` for subheads) can be applied to the lower
     sections for unified register.
   - Test: extend `task131HomepageHeroAndNewSectionsRender` (or add a
     companion test) that asserts EVERY `<h2>` on `/` carries
     `font-medium` — fails if any future edit introduces
     `font-bold`/`font-semibold` on a section heading.

   **TASK-138** — replace "How it works" custom images with icons.

   The Step 1 / 2 / 3 cards use `how-connect.webp` / `how-monitor.webp`
   / `how-act.webp` (or similar). User: "remove custom images and
   replace with some icons or something."

   Acceptance:
   - Find the assets via grep + Symfony AssetMapper conventions
     (`assets/images/how-*.webp` likely).
   - Replace each `<img>` with an inline Lucide SVG icon at the same
     size (~`w-16 h-16` or `w-20 h-20`). Pick per-step icons that
     match the action: Step 1 (DMARC publish) → globe / dns icon,
     Step 2 (Monitor) → activity / pulse / line-chart icon, Step 3
     (Act) → bell / mail-check / shield-check icon.
   - Icon container styling matches the zinc palette: e.g.
     `bg-zinc-50 border border-zinc-200 rounded-lg p-4` with the
     SVG `text-zinc-700`. NO emerald/blue tint unless it carries
     state meaning.
   - Delete the orphaned `.webp` asset files from `assets/images/`
     if no other surface references them — grep first.
   - The Step 1 image alt text was updated in TASK-132 — verify the
     new icon-based markup carries equivalent `aria-label` or visible
     text per icon.
   - Test: extend the homepage test to assert the icon SVG markers
     render and the `<img>` tags for `how-*.webp` are gone.

3. **DASHBOARD DKIM SELECTOR EDITABILITY** (TASK-143)

   **TASK-143** — DKIM selector must be editable post-save.

   User reports: once they save a DKIM selector on a domain, the
   field becomes read-only. They can't change it (e.g., when rotating
   keys + selectors). This is a trust-eroding bug — "the dashboard
   trapped my input."

   Acceptance:
   - Find the DKIM-selector form. Likely candidates: `src/FormData/`
     for the form-data class, `src/Form/` for the form type,
     `src/Controller/Dashboard/Domain*` for the controller,
     `templates/dashboard/*` for the template. Grep for `dkimSelector`
     or `dkim_selector`.
   - Identify the read-only branch — probably a `{% if domain.dkimSelector
     is not null %}render as plain text{% else %}render input{% endif %}`
     pattern, or a `disabled: true` on the form field when the column
     is set.
   - Add an edit path: the field is always an editable `<input>`.
     Submitting a changed selector triggers the same DNS re-verification
     command that was used on first save (probably
     `App\Message\VerifyDomainDns` or similar — find via grep).
   - Validation: same as first save (selector must be a valid DNS
     label, can't be empty, etc.).
   - The selector change should NOT silently invalidate historical
     DMARC reports — just update the column + re-run the DKIM check.
   - Edge case: a domain with a soft-deleted-mailbox case from
     TASK-133 should still allow selector edit (no coupling).
   - Test: integration test seeds a domain with an existing DKIM
     selector ("default"), POSTs a change to ("mailchimp"), asserts
     the column was updated AND a DNS re-verification was dispatched.
     Second test asserts validation errors render the field
     editable (not stuck on the old value).

4. **STRATEGIC SEO + NARRATIVE PASS** (TASK-142 + TASK-145)

   These two are interconnected (narrative restructure affects SEO
   structured data + internal linking), but the user described them
   separately. Ship TASK-142 first (audit + identify the highest-
   leverage fixes), then TASK-145 (restructure with SEO learnings in
   mind). Architect agent for TASK-142 — needs to scope the audit
   before committing to fixes. TASK-145 also benefits from architect
   given the user-flow narrative work.

   **TASK-142** — SEO audit + improvements.

   The architect agent should produce a punch list per page-type
   (homepage, /pricing, /about/*, /learn/*, /tools/*, /open-source).
   For each page audit:
   - `<title>` — unique per page, ~50-60 chars, primary keyword
     present (e.g. "DMARC Monitoring — Sendvery", "SPF Record
     Checker — Free Online Tool — Sendvery").
   - `<meta name="description">` — unique per page, ~150-160 chars,
     compelling for SERP click-through.
   - Open Graph (`og:title`, `og:description`, `og:image`,
     `og:url`) — per page if possible, sensible default if not.
   - Twitter Card meta tags (`twitter:card`, `twitter:title`,
     `twitter:image`).
   - Canonical URL (`<link rel="canonical">`) per page.
   - Structured data (JSON-LD): Organization on home, Product on
     pricing, Article + breadcrumbs on /learn/*, SoftwareTool on
     /tools/*. The homepage already has Organization (TASK-031-era).
   - Heading hierarchy: ONE H1 per page, proper H2-H6 nesting.
   - Internal linking density: every page should reach related
     public pages within ~2 clicks (e.g. /tools/spf-checker links
     to /learn/spf-explained etc.).
   - `robots.txt` and `sitemap.xml` — exist + current? File one to
     fix if missing.
   - Image `alt` attributes — every meaningful image carries a
     description; decorative SVGs use `aria-hidden="true"`.
   - Mobile / page-speed baseline — verify CSS isn't render-blocking
     unnecessarily; lazy-load below-the-fold images.

   Ship the punch-list as a single architect plan, then ship the
   highest-leverage 5-10 fixes in this round. Lower-priority items
   filed as TASK-15X follow-ups (round 9 candidates).

   **TASK-145** — homepage narrative + section order pass.

   User: "Completely go through the design of the public and
   marketing pages — the user story on the homepage should make
   sense, maybe put pricing slightly higher, but there should be
   clear story / flow from top the bottom reasoning why the sections
   are in such order — follow best practices."

   Architect proposes the section order with explicit rationale per
   transition. Suggested skeleton (NOT prescriptive — architect's
   judgment):
   - **Hero** (TASK-131): big visual claim + free instant check
   - **Trust** (logos): "Already running on real production
     domains" social proof
   - **Problem framing** (NEW or restructured): "Email auth breaks
     silently and you only notice when deliverability drops." Hook
     the reader on the WHY.
   - **Solution** (TASK-131 sections 2 + 3): XML → English + grade
     card. THIS is what we do.
   - **Product preview** (TASK-120 dashboard screenshot): "Here's
     what it looks like in the dashboard."
   - **Pricing** (moved higher per user direction): once the visitor
     has seen the problem + solution + preview, "how much does it
     cost?" is the next logical question. Put PRICING here BEFORE
     deep-dive features.
   - **FAQ** (existing) — addresses purchase friction.
   - **Final CTA** — close.

   Acceptance:
   - Architect produces the section-order proposal with the
     rationale per transition. Append to TASK-145 in the backlog.
   - Dev re-sequences `templates/homepage/index.html.twig`. Section
     boundaries clear, narrative-comment per section explaining what
     it does for the visitor.
   - Pricing section moves earlier per user direction.
   - Verify each `/pricing`, `/about/what-is-sendvery`, `/learn`,
     `/tools/*`, `/open-source` page still flows coherently — if any
     page's flow contradicts the new homepage story, fix in scope.
     Don't restructure those pages just for parallelism — only fix
     where coherence is broken.
   - All existing functional tests still pass (assertions on H1
     copy + presence of pricing/FAQ should survive a re-sequence).
   - The dotted-grid hero background, font register (TASK-137), and
     all per-section accessibility patterns from TASK-131 carry over.

5. **DNS HELPER-FORM FEATURE — v1 (ALL 4 GENERATORS)** (TASK-144)

   **TASK-144** — generators for SPF + DMARC + DKIM + MX records.

   User asked: "could there be helper forms to set up dns records
   format for spf, dkim etc on the public pages?" — phrased as a
   question. **User's confirmed v1 scope (round-8 checkpoint): ALL
   FOUR generators.** Rationale: DKIM has the highest "help me set
   this up" demand despite the awkward UX (selector + public-key
   bytes); MX is cheap to throw in alongside.

   v1 scope (all four):
   - **SPF generator** on `/tools/spf-checker` (or a sibling page if
     the checker page is the wrong home). Toggle/checkbox UI for
     common sending services: Google Workspace, Microsoft 365,
     Mailchimp, Postmark, SendGrid, Mailgun, Amazon SES, Brevo,
     Resend, Loops. Plus a free-form "Additional IPs / includes"
     textarea. Plus the `~all` / `-all` mechanism choice. Output:
     generated TXT record string in a `<code>` block + copy button.
   - **DMARC generator** on `/tools/dmarc-checker`. Inputs: policy
     (none / quarantine / reject), subdomain policy (sp=), pct=,
     reporting email (rua= — defaults to `reports@sendvery.com`),
     forensic email (ruf= — optional), DKIM/SPF alignment mode
     (relaxed/strict). Output: generated TXT record string.
   - **DKIM generator** on `/tools/dkim-checker`. Inputs: selector
     name (text input — e.g. `default`, `mailchimp`, `selector1`),
     public-key bytes (textarea — paste the base64 from
     `openssl rsa -in private.key -pubout`), key type (RSA / Ed25519).
     Output: the generated TXT record string AND the host-name
     fragment the user needs to publish it at (`<selector>._domainkey`).
     Include a short note that Sendvery cannot generate the private
     key for them — they generate the keypair themselves and paste
     only the public part. (DKIM is the awkward generator: emphasise
     in the UI that the public key has to be split across multiple
     quoted strings if it exceeds 255 chars — the generator should
     handle the splitting automatically.)
   - **MX generator** on `/tools/mx-checker`. Inputs: rows of
     `priority` + `hostname` pairs (default: one row for Google
     Workspace [`1 ASPMX.L.GOOGLE.COM`], add/remove buttons to add
     more rows). Output: the MX record strings ready to paste.
     Optional preset toggles for common providers (Google Workspace,
     Microsoft 365, ProtonMail, Fastmail, Zoho) that auto-fill the
     standard hostname + priority pairs.
   - All four PURELY client-side (Stimulus controller). No server
     round-trip. The user can use them while logged out.
   - Each output is a `<code>` block + a copy-to-clipboard button.
     Below the code block: a short "What to do next" paragraph
     linking to `/learn/*` for the record-type explainer.
   - One-line caveat per generator ("Test in DNS before making this
     your live record. We don't publish for you.").

   Architect first — needs to confirm:
   - Which existing tool pages host the generators (checker page vs
     separate generator page). Default: same page as the checker, so
     the user can iterate "generate → copy → publish → re-check"
     without switching pages.
   - The Stimulus controller pattern matching the existing
     `HomeDomainCheckerComponent` register (Symfony UX LiveComponent
     vs vanilla Stimulus).
   - The exact provider list for SPF + the canonical `include:`
     strings (cross-check against major-provider docs as of 2026).
   - The DKIM long-key splitting strategy (255-char chunks wrapped
     in adjacent quoted strings — verify the BIND-format vs
     name=value-format conventions for the user's DNS host).
   - The MX preset list (5 common email providers; same shape as
     SPF presets).
   - Output formatting (single-line vs multi-line; quoted vs
     unquoted; trailing-period on hostnames).

   Acceptance:
   - SPF generator renders on `/tools/spf-checker`. Toggling
     providers regenerates the output in real time. Copy button
     works (`navigator.clipboard`).
   - DMARC generator renders on `/tools/dmarc-checker`. Same UX.
   - DKIM generator renders on `/tools/dkim-checker`. Long-key
     splitting visible in the output (e.g. `"v=DKIM1;…p=AAA" "BBB"`).
     UI tells the user "publish at `<selector>._domainkey.<your-domain>`".
   - MX generator renders on `/tools/mx-checker`. Add/remove row UX
     for priority+hostname pairs. Preset toggles auto-fill the
     common providers.
   - All four carry the one-line caveat.
   - SEO: new generator content adds H2-level structure on each
     page (good for keyword targeting — "SPF record generator",
     "DKIM record generator", etc.).
   - Tests: render each page + assert the generator markup is
     present. Stimulus integration tests aren't required for v1.
     Provider lists live in config files or PHP constants so they
     extend without touching the template.
   - XSS guard: Stimulus controllers MUST escape user input before
     injecting into output code blocks (free-form "additional IPs"
     textarea on SPF, the public-key paste on DKIM, the hostname
     fields on MX are the attack surfaces).

6. **PERFORMANCE AUDIT** (round-8 baseline diff)

   Round 7 captured a perf-audit snapshot. Round 8's changes are
   mostly marketing-side (templates, copy, assets) — perf delta is
   expected ≈ 0. The DKIM-editability fix (TASK-143) and DNS
   helper-form feature (TASK-144) might touch new queries — measure
   if they do.

   Re-run the 8 round-7 queries + any new ones:
   - GetDomainOverview::forTeams
   - GetDnsHealthOverview::forTeams
   - NavCountsExtension::getGlobals (4 COUNTs)
   - IngestionPathResolver::resolveForTeams (now batch-routed)
   - GetDomainWorkspaceTabCounts::forDomain
   - Combined /app/domains two-query pattern
   - RuaScenarioResolver::resolveForDomainIds (batch)
   - MailboxConnection repo methods (with disconnected_at filter)

   If TASK-143 (DKIM editability) adds a new write path, no perf
   measurement needed (writes aren't on the hot path). Document the
   round-8 numbers in a new `## Round-8 performance audit (YYYY-MM-DD)`
   section above the round-7 perf section.

7. **ROUND-8 SELF-REVIEW** (every 3 shipped tasks)

   Same pattern as rounds 3-7. Round-8-specific things to watch for:
   - **TASK-136**: did the `is_repo_public` retire leave any orphan
     env var docs / Symfony container parameter / CI secret reference?
     Grep `~/www/spare.srv/deployment/` (don't EDIT it — user only;
     just sanity-check no production env is mis-configured).
   - **TASK-137**: the `font-medium` regression-test is the regression
     net — verify it pins EVERY H2 on the page, not just the new ones.
   - **TASK-139 + TASK-140**: deleting "Built for engineers" and
     "Related tools" should leave NO orphan tests asserting the
     deleted copy. Grep `tests/` for the strings.
   - **TASK-143**: editing the DKIM selector triggers a re-verification.
     Does the dashboard correctly show "Re-checking..." state, or does
     the old verified-at timestamp stay stale until the next cron run?
     Look at the existing re-test pattern (TASK-108-era `dashboard_mailbox_retest`)
     as the comparison — DNS re-verification should behave similarly.
   - **TASK-144**: client-side generators must not introduce XSS via
     the free-form "additional IPs" input. Verify the Stimulus
     controller escapes user input before injecting into the output
     code block.
   - **TASK-145**: re-sequencing the homepage might break the
     `task131HomepageHeroAndNewSectionsRender` test's position-based
     assertions (which use `strpos($body, X) > strpos($body, Y)`).
     Update those if needed but PRESERVE the spirit — the new order
     must still pin the rationale.

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
highest at run start is TASK-135. Round-8 user-driven tasks claim
TASK-136 through TASK-145. Self-review findings or post-shipping
follow-ups start at TASK-146.

This file survives compaction; ALWAYS read it before deciding what to
do next and ALWAYS update it after each phase transition.

Round 8's seed tasks must be filed into the backlog before shipping
them. The orchestrator brief itself is the source; the backlog is the
durable contract.

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
   field, skip this phase. Otherwise, for non-trivial tasks (TASK-142
   SEO audit, TASK-144 DNS generators, TASK-145 narrative pass,
   possibly TASK-143 DKIM edit if the existing form is complex), spawn
   Architect agent; it appends `### Architect plan (YYYY-MM-DD)` to
   the task's Notes. Promote to `in-progress`. For the quick-wins
   bundle (TASK-136/139/140/141), TASK-137 (font register), TASK-138
   (icon swap) the spec is detailed — skip Architect, go straight
   to Build.

4. BUILD PHASE:
   Spawn Developer agent. Pass the architect plan if one exists,
   otherwise pass the Acceptance criteria block verbatim. **Defensive
   write strategy** (round-4 lesson): prefer `Write` with full file
   contents over `Edit` when modifying open files; `Edit` calls were
   observed being reverted by an editor race during round 4's
   parallel runs. Heredoc-via-bash is another safe fallback.

5. REVIEW PHASE:
   Spawn Reviewer agent. Promote to `in-review`. Rounds 4-7 all
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
   round-7 shipped 6 task-commits + 2 docs-commits = 8 total, which
   made the git log readable.

   **Push continuously, not at the end.** After every successful
   commit, run `git push origin main` before moving to the next task.
   The flow is: `git commit` → `git push origin main` → mark backlog
   status `done` → next task. If a push fails (network blip, remote
   moved ahead), investigate before continuing: `git pull --rebase
   origin main` first when remote diverged, then re-push. Never
   `--force` to resolve a divergence — investigate the conflict.

   **Push-auth note**: the macOS keychain-backed SSH agent sometimes
   loses `SENDVERY`-relevant identity between Bash invocations in
   this session. If `git push` errors with "Permission denied
   (publickey)", prepend `SSH_AUTH_SOCK=/private/tmp/com.apple.launchd.<id>/Listeners`
   (look it up via `echo $SSH_AUTH_SOCK` in a fresh Bash). Don't ask
   the user — this is a known shell-env issue, not a real auth
   failure.

8. SELF-REVIEW PHASE (every 3 shipped tasks):
   Step back. Audit the affected pages by reading the post-shipping
   templates and asking: "what is this for? what should I do? is
   anything wrong?". Round 7's self-review caught 1 must-fix
   (TASK-135 — RuaMailboxMatcher disconnect guard) — assume round
   8 has similar blind spots, especially around marketing-page
   consistency.

9. Go to step 1.

Run independent agents in parallel where the work doesn't depend on
each other. Agents that touch the same file MUST serialise. Rounds 4-7
each ran up to 3 concurrent agents successfully — that remains the
sweet spot. Round 8 has several non-overlapping file sets:
- TASK-136/139/140/141 (marketing templates, footer) | TASK-143 (dashboard form)
- TASK-137/138 (homepage template) AFTER quick-wins bundle so file collisions don't happen
- TASK-142 (SEO meta + structured data — affects every public page) — serialise with TASK-145 (homepage restructure)
- TASK-144 (new tool-page generators) — independent of everything except possibly TASK-140 (related-tools deletion on the same templates)

================================================================
AGENT CONTRACTS
================================================================

### Product agent (subagent_type: general-purpose)
Brief: "You are the product owner for Sendvery, an email
deliverability + DNS monitoring SaaS. Read CLAUDE.md, the orchestrator
brief, and the existing tasks in `docs/cx-improvement-backlog.md` so
you do not re-propose work that's already done (TASK-001 through
TASK-145 are shipped or planned by the time you run). Your job in
round 8 is the FINAL stop-condition sweep across all seed buckets
once the user-driven work has shipped. Form an honest first-impression
critique against the round-8 scope (marketing polish / dashboard
DKIM editability / SEO / DNS helper forms) and surface any 'we forgot'
gaps the user hasn't named yet. Append proposals to docs/cx-
improvement-backlog.md using the schema. Continue numbering from the
highest existing TASK-NNN. Each proposal must include why a real user
cares — name the moment of confusion the change resolves, not just
what changes. Do NOT write code."

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
say so and exit. Round 8's Architect candidates are TASK-142 (SEO
audit punch-list), TASK-143 (DKIM-editability if existing form has
edge cases), TASK-144 (DNS generators v1), and TASK-145 (homepage
narrative restructure)."

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
For TASK-136 specifically: verify `grep -rn 'is_repo_public\|SENDVERY_REPO_PUBLIC\|Notify me when'` returns ZERO hits across src/, templates/, and tests/.
For TASK-137: verify every `<h2>` on the homepage carries `font-medium` (not `font-bold`/`font-semibold`/`font-extrabold`).
For TASK-143: verify the DKIM selector edit triggers the same DNS re-verification command the first-save did, and that the form no longer disables the field once a selector is saved.
For TASK-144: verify the Stimulus controller escapes user input before injecting into the output `<code>` block (no XSS via the 'additional IPs' textarea)."

================================================================
QUALITY GATES (run before every commit)
================================================================
All must pass — no skipping, no --no-verify:
- docker compose exec app vendor/bin/phpunit (2272 tests at round-8 continuation start, post-quick-wins bundle)
- docker compose exec app vendor/bin/phpstan
- docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
- For UI tasks: read the page, confirm desktop AND 360px mobile render
- 100% coverage on new code (per CLAUDE.md)
- `ClockInterface::now()` used everywhere — never `new \DateTimeImmutable()`
  in production code paths
- For TASK-136: `grep -rn 'is_repo_public\|SENDVERY_REPO_PUBLIC' src/ templates/ tests/ config/` returns zero hits.
- For TASK-141: `grep -rn 'Symfony\|FrankenPHP\|Tailwind\|daisyUI\|Postgres' templates/components/Footer.html.twig` returns zero hits.
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
- Create commits on the current branch AND push to origin (including
  main) — see the SHIP PHASE rule that you push CONTINUOUSLY after
  every successful commit, not in a final batch.
- Run dev server, hit endpoints, inspect rendered HTML.
- Update docs/cx-improvement-backlog.md freely.
- Add placeholder content where the brief explicitly permits it.
  Continues TASK-023's convention: same-line
  `// TODO(placeholder): replace before launch` AND an entry in
  `config/placeholders.php`. The AI feature is the ONLY genuine
  placeholder remaining — repo-public is NOT a placeholder anymore
  per the user's round-8 direction.
- Apply small reviewer-flagged fixes directly via Edit/Bash without
  spawning another Developer agent (e.g. a one-line clock-injection
  fix, a test rename) when the change is mechanical.
- **Delete env vars + Twig globals + orphaned assets** as part of
  TASK-136 + TASK-138 + TASK-139 + TASK-140 (no backwards-compat shims
  per CLAUDE.md). This is the user-blessed scope for round 8.

================================================================
DO NOT (ask first if tempted)
================================================================
- Force-push, rewrite history, reset --hard, delete branches.
- Open PRs (commit + push; user reviews locally).
- Touch Stripe live config, production env, or anything under
  `~/www/spare.srv/deployment/`.
- Introduce dark mode / sendvery-dark theme (explicitly out of scope
  per CLAUDE.md).
- Ship placeholder content without the dual marker. The AI feature
  is the only genuine "coming soon" surface remaining — repo-public
  is NOT a placeholder anymore.
- Re-introduce `SENDVERY_REPO_PUBLIC` env-gate / `is_repo_public`
  Twig global / "Notify me when source ships" CTA — all removed per
  TASK-136. The repo is public.
- Refactor outside the current task's scope. EXCEPTION: if TASK-145's
  homepage narrative restructure reveals incoherence on
  `/about/what-is-sendvery` or `/pricing`, fix in scope rather than
  filing a follow-up.
- Add backwards-compat shims, fallbacks, or feature flags.
- Skip tests or quality gates.
- Couple "ingest via DNS" and "ingest via mailbox" into a single
  config — they are mutually exclusive per domain.
- Bypass `ClockInterface` with `new \DateTimeImmutable()` in
  production code.
- Reintroduce the marketing-nav Dashboard CTA badge (TASK-065 +
  CLAUDE.md note explicitly locks this).
- Reintroduce `/app/dns-health` — the route was deleted in TASK-130.
- Re-introduce tech-stack name-drops ("Built with Symfony &
  FrankenPHP", "Powered by Tailwind", etc.) on user-facing surfaces.
  Tech stack lives in CLAUDE.md only.

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

Round 7 hit the primary stop signal (backlog drained). Round 8
should aim to do the same — the scope is larger than round 7's 3
tasks but smaller than round 5's 17, sitting closer to round 6's
7 in size.

When you stop, append a final summary to
docs/cx-improvement-backlog.md: tasks shipped, tasks blocked + why,
self-review findings, surfaces you reviewed and judged "good enough",
perf-audit measurements (even null results), and suggested round-9
seed areas.

================================================================
KICKOFF (round-8 continuation — skip bucket 1, it's done)
================================================================
1. Read `docs/cx-improvement-backlog.md` for the latest state. The
   quick-wins bundle (TASK-136/139/140/141) is already `done`; six
   round-8 tasks remain at `proposed`: TASK-137, TASK-138, TASK-142,
   TASK-143, TASK-144, TASK-145.
2. CLAUDE.md is already loaded. Skim `docs/` for reference; pull in
   specific files only when the current task needs them.
3. **Ship TASK-138 (How-it-works icons) and TASK-137 (font register)
   sequentially** — both touch `templates/homepage/index.html.twig`.
   TASK-138 first (delete `how-*.webp` assets + insert Lucide icons),
   TASK-137 second (normalise every `<h2>` to `font-medium` + zinc
   register). These are small (~30min each), skip Architect — straight
   to Build.
4. **Ship TASK-143 (DKIM editability)** in parallel with the marketing
   work — different file surface (dashboard form vs marketing
   templates). Likely needs a brief Architect plan if the existing
   DKIM-selector form has edge cases (multi-step wizard, separate
   edit endpoint); otherwise straight to Build.
5. **Spawn Architect for TASK-142 (SEO audit)** — needs scoping before
   committing to fixes. Then ship the highest-leverage 5-10 punch-list
   items in this round; file lower-priority items as TASK-15X
   follow-ups.
6. **Spawn Architect for TASK-144 (DNS generators v1 — ALL 4)** —
   confirm provider lists + page placement + Stimulus pattern + DKIM
   long-key splitting strategy + MX preset list before build. **User
   confirmed scope: SPF + DMARC + DKIM + MX all in v1** (NOT just
   SPF+DMARC). Ship all four generators.
7. **Spawn Architect for TASK-145 (homepage narrative)** — must run
   AFTER TASK-137/138/142 land so the architect designs against the
   final per-section visual register + SEO structure. Ship the
   restructure (pricing moves earlier per user direction).
8. **Run the round-8 perf audit** after all tasks land. Document the
   numbers in a new `## Round-8 performance audit (YYYY-MM-DD)`
   section above the round-7 perf section.
9. After every 3 shipped tasks, run a self-review pass.
10. Final Product-agent sweep across all buckets as the
    stop-condition check.
11. Write the RUN SUMMARY when the backlog is truly empty. Cover:
    every task shipped (INCLUDING the quick-wins bundle from the
    previous session — `bdf4b62` — for completeness of the round-8
    summary), any blocked + why, self-review findings + dispositions,
    suite growth (from 2272 baseline post-quick-wins), perf-audit
    measurements (round-8 vs round-7 diff), suggested round-9 seed
    areas.

================================================================
LESSONS FROM ROUNDS 4 + 5 + 6 + 7 — APPLY HERE
================================================================
- **Editor-revert race** (round 4): prefer `Write` (full file
  content) or heredoc-via-bash when modifying files that might be
  open in an editor / under linter watch. Rounds 5-7 used this
  defensively and lost zero edits.
- **Parallel agents**: 3 concurrent is the sweet spot. Round 7 hit
  this consistently (TASK-133 + TASK-134 in parallel).
- **Self-review payoff**: round-3 caught 3, round-4 caught 6, round-5
  caught 3, round-6 caught 0 (drained on first pass), round-7 caught
  1 (TASK-135 — the matchesMailbox guard). Run the self-review every
  3 ships without exception.
- **Don't over-architect small tasks**: round-7 skipped Architect for
  TASK-132 (single-template edit). Round 8 should do the same —
  Architect only for TASK-142 / 143 / 144 / 145; the rest go straight
  to Build.
- **Commit per task or per coherent bundle**: round-7 shipped 6
  task-commits across 4 user-driven tasks + 1 sidecar + 1 self-review
  must-fix. Readable git log. Round 8's quick-wins bundle is the
  exception that proves the rule — bundling 4 marketing-clutter
  deletes into ONE commit is the right grain because the user
  expressed them as a single "clean this up" thread.
- **Reviewer agents net real findings on >50% of bundles**: round 4
  + 5 + 6 + 7 all confirmed this. Keep the review step even when the
  dev agent reports "all green".
- **Cross-surface consistency tests pay off**: round 5's
  `SurfaceConsistencyTest` for TASK-114, round 6's TASK-130 codified
  guards (`noTemplateReferencesDashboardDnsHealthRoute`), round 7's
  `InMemoryQueryLogger`-based one-query assertion all caught real
  regressions at the test layer. Round 8's TASK-136 should add a
  codified guard against `is_repo_public` re-introduction (mirrors
  the TASK-130 guard pattern).
- **User-driven tasks have the highest signal**: rounds 6, 7, and 8
  are all user-driven. The Why field for each task should quote the
  user's words. The bug fixes (TASK-143 DKIM editability) and the
  trust-erosion fixes (TASK-136 "stop apologising for things that
  are done") are the highest-value items.
- **Push continuously, not in a batch**: round 6 + 7 both pushed
  every commit before moving to the next task. No carryover of
  unpushed commits. Round 8 enforces this as a quality gate.
- **Marketing copy ≠ tech stack**: rounds 5 + 7 cleaned up several
  marketing-side honesty gaps (TASK-117 CTA, TASK-121 AI pricing,
  TASK-132 onboarding mental model). Round 8's TASK-141 continues
  the pattern — end users care about VALUE, not stack. Tech stack
  lives in CLAUDE.md.
