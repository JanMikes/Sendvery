# Autonomous CX/Product Improvement Run — Sendvery (Round 10: founder-contact + GitHub-feedback transparency surface + trust polish)

You are the ORCHESTRATOR. Your job is to autonomously improve Sendvery's
marketing surfaces + dashboard by running a continuous loop of
specialised subagents. You self-pace. You DO NOT stop to ask the user
for permission on anything covered by "Autonomy". You DO NOT stop until
the backlog is genuinely empty (Product agent confirms nothing more is
worth doing) or you hit a real blocker in "Stop conditions".

================================================================
CHECKPOINT — WHAT ROUND 9 SHIPPED (read this first)
================================================================
Round 9 closed cleanly with **10 tasks shipped** across 4 code commits
+ 1 docs commit. Final state: **2303 tests / 6913 assertions**, all
gates green, all commits pushed to `origin/main`.

Shipped in round 9 (in this order):

- **TASK-158** (commit `1b00869`) — Homepage hero rewrite for
  user-value framing. New H1 `"Stop your email from quietly landing
  in spam."` (was the feature-label triad "DMARC, DNS, deliverability
  — monitored and explained."). Eyebrow above H1 deleted (duplicated
  the H1 keywords). Subhead rewritten to lead with the visitor's
  outcome: `"Sendvery watches your DMARC reports and DNS health 24/7,
  then tells you in plain English what to fix — before a customer
  ever tells you the invoice never arrived."` Open-source pitch
  removed from the subhead (still lives in the chip row + dedicated
  Open Source section deeper down). Secondary `"View on GitHub"` CTA
  REMOVED — single hero CTA = stronger focus, GitHub still in footer
  + Open Source section. Mobile vertical rhythm tightened
  (`py-10 md:py-24`, `text-3xl md:text-5xl`, `gap-6 md:gap-16`,
  `p-4 md:p-5`) so H1 + subhead + CTA + checker card all fit one
  screen at 360px. 5 new business-behaviour-named tests pin the
  rewrite + mobile rhythm. Audit of `/about/what-is-sendvery`,
  `/about/open-source`, `/about/pricing` confirmed they already lead
  with user value — no in-scope fixes needed on those heroes.

- **TASK-146** (`1456960`) — Per-domain DKIM-selector preference.
  The deferred-from-round-8 missing-feature gap finally shipped.
  New migration `Version20260530000000` adds
  `monitored_domain.dkim_selector VARCHAR(255) NULL`. `MonitoredDomain`
  entity gains the nullable field. New CQRS command
  `SetDomainDkimSelector` + handler with RFC 1035 single-DNS-label
  validation, idempotent re-verification (no-op when value
  unchanged), and synchronous `CheckDomainDnsHandler` dispatch so the
  verification status reflects the new selector by the next page
  load. New POST `/app/domains/{id}/dkim-selector` controller with
  CSRF. `DnsMonitor::check()` now passes `$domain->dkimSelector` to
  `DkimChecker::check()` — empty value preserves brute-force fallback.
  `GetDomainDetail` + `DomainDetailResult` thread the column through
  to the template. Dashboard form on `/app/domains/{id}` under
  `DomainSetupStatus`: free-form `<input>` pre-filled with current
  value, `maxlength=63`, HTML5 `pattern` attribute, CSRF token,
  "Save & re-check DNS" submit. 8 new integration tests
  (pre-fill, set, clear, whitespace-normalise, malformed-rejection,
  leading-hyphen-rejection, CSRF-missing, idempotent-resubmit) + 1
  added to `CheckDomainDnsHandlerTest`
  (`customDkimSelectorIsPassedThroughToTheChecker` pins the
  DnsMonitor→DkimChecker wiring so a future refactor that silently
  reverts to passing `null` fails fast). Reviewer agent caught the
  missing idempotency test inline — shipped before commit.

- **TASK-148 + TASK-149 + TASK-150 + TASK-152** (`7b24194`) — SEO
  polish bundle. TASK-148: `KnowledgeBaseIndexController::GUIDES`
  gains per-article `publishedAt` + `updatedAt` fields (8 articles
  now span 2026-03-25 → 2026-04-20 instead of all sharing the
  hardcoded 2026-03-25 — Google sees per-article freshness signals).
  `_article_layout.html.twig` threads them into the Article JSON-LD.
  TASK-149: `BreadcrumbList` JSON-LD on `/learn` +
  `/about/what-is-sendvery` + `/about/open-source` + `/pricing` +
  every KB article (was only on tool pages). TASK-150: `WebSite`
  JSON-LD with `SearchAction` on `/` (forward-declarative for
  Sitelinks Searchbox; the `/search` endpoint doesn't exist yet).
  TASK-152: 4 over-length `<title>` trimmed to ≤ 60 chars by swapping
  `| Sendvery` → `— Sendvery` and tightening descriptors
  (`/tools/domain-health` 69→54, `/tools/email-auth-checker` 72→58,
  `/tools/blacklist-checker` 71→60, gmail-yahoo article 84→56).
  `publicPagesShipSeoBaseline` extended with 27 new assertions.

- **TASK-153 + TASK-154 + TASK-155 + TASK-156 + TASK-157** (`2eea281`)
  — Round-8 DNS-generator nice-to-haves bundle. TASK-153: dropped the
  unreachable `copyButton` Stimulus targets from all 4 generator
  controllers (`spf/dmarc/dkim/mx_generator_controller.js`) — declared
  but never wired in any template; `copy()` uses `event.currentTarget`.
  TASK-154: email-format validation on DMARC rua/ruf inputs
  (`/^[^@\s]+@[^@\s]+\.[^@\s]+$/`); malformed entries are excluded
  from the generated record AND the input gets `aria-invalid="true"` +
  `ring-warning` so the user sees why their address didn't make it in.
  TASK-155: `SpfProviderRegistry` + `MxPresetRegistry` refactored from
  shape-arrays to `list<SpfProvider>` / `list<MxPreset>` DTOs (new
  `src/Value/SpfProvider.php`, `src/Value/MxPreset.php`,
  `src/Value/MxPresetRecord.php`). DTOs implement `JsonSerializable`
  with explicit property-order arrays so `allAsJson()` output stays
  byte-identical to round 8 (Stimulus controllers consume the JSON
  via `data-*-generator-{providers,presets}-value` and any reorder
  would break them). Pinned by
  `jsonOutputStaysByteIdenticalAfterDtoRefactor`. TASK-156:
  `assertResponseIsSuccessful()` status guards on the 3 generator
  tests the round-8 reviewer flagged. TASK-157: DMARC generator
  omits default `adkim=r`/`aspf=r` when both at default; emits BOTH
  when either is set to strict so the record is self-documenting.
  8 new tests pin the DTO refactor + Brevo canonical-domain regression
  guard + Microsoft `your-tenant` placeholder.

- **Docs** (`5e91404`) — Round-9 RUN SUMMARY + perf audit + flip
  TASK-146/148/149/150/152/153/154/155/156/157/158 statuses to `done`.

**Deferred to future-watchlist** (filed with explicit prerequisite
notes, not shipped):

- **TASK-147** — Organization JSON-LD `logo` field. No square logo
  asset exists at `public/logo.png` yet. The existing
  `og-default.webp` is a 1200×630 social card, not a logo-shaped
  asset Google would consume. Sub-task once a square logo lands.
- **TASK-151** — `twitter:site` handle. No verified `@sendvery`
  Twitter/X account yet. Shipping with a non-existent handle
  produces a broken card preview. Sub-task once the brand handle
  is registered + verified.

**Reviewer-caught must-fix applied inline:**

- TASK-146: missing test for the idempotency / no-op guard. The
  class docblock promised "idempotent re-submits don't fire
  duplicate DNS re-verifications" but no test asserted the
  `dns_check_result` row count was stable. Added
  `resubmittingUnchangedSelectorDoesNotFireRedundantDnsCheck`
  shipped in the same `1456960` commit.

Round 9 final stats: **2284 → 2303 tests (+19), 6764 → 6913
assertions (+149)** vs round-8 baseline. Perf delta vs round 8 ≈ 0
(TASK-146's new column read is on a row that's already loaded for
verification; SEO/refactor changes don't touch DB).

User-driven sidecar commits this round: none. The user did NOT push
intermediate fixes between my commits in round 9 (unlike round 8's
hero gradient + section background work). This run's commit chain is
linear: 87c52b9 → 1b00869 → 1456960 → 7b24194 → 2eea281 → 5e91404.

================================================================
MISSION
================================================================
Round 10 is **user-driven trust + transparency**: a fresh ask from
the user about building credibility as a from-scratch startup by
making the founder accessible and the feedback loop public.

User's verbatim ask (preserved here in full because the framing
matters):

> "To build some trust and transparency, it is always good to show
> who is behind the project, who am i paying to. We need some
> contact section or something with contact form and public email
> jan.mikes@sendvery.com - some text like 'Talk directly to the
> founder' - and as well incorporate link to github - one card/section
> for developers 'Product suggestions or bug report - can open issue
> directly on github, where the code lives.' something in this manner
> i want to follow conventions and best practices for building
> transparent trustful product from scratch (startup)."

This decomposes into 3 user-facing surfaces + 1 trust-polish sweep:

1. **TASK-159 — Founder contact surface** (P0, ship FIRST). A
   dedicated contact route (likely `/about/contact` or `/contact`)
   that introduces Jan Mikeš as the founder, exposes
   `jan.mikes@sendvery.com` as the canonical channel for non-bug
   questions (billing, sales, partnerships, "how does your team
   work"), and offers a contact form that submits to that same
   address. The "Talk directly to the founder" framing is explicit —
   this is the antithesis of the no-reply support-ticket experience
   visitors expect from SaaS. Architect-first: needs UX decision
   (single contact page vs section on existing `/about/*` page?),
   form-backing decision (Symfony Mailer + transactional provider?
   simple mailto fallback?), spam-mitigation decision (Cloudflare
   Turnstile / honeypot field / rate-limit?), and route placement
   (footer + nav link).

2. **TASK-160 — Developer feedback / GitHub-issues surface** (P0).
   A parallel card/section for the developer audience that names the
   right channel for bugs and product suggestions: `github.com/janmikes/Sendvery/issues`.
   Copy: *"Product suggestions or bug reports — open an issue
   directly on GitHub, where the code lives."* This pairs with
   TASK-159 to make the routing explicit: business questions →
   founder email; engineering questions → GitHub. The two surfaces
   should live side-by-side (same page or sibling sections) so
   visitors see both options at once and don't try to email
   bug reports to the founder. No architect needed — single template
   addition.

3. **TASK-161 — Founder bio expansion / link-up** (P0). The
   homepage already has a "Founder bio" section
   (`§12 of 14` per TASK-145's narrative arc). Audit it for:
   - Does it currently expose a contact channel? (My read: probably
     not — it was added before the contact-surface scope.)
   - Does it link to the new `/about/contact` (TASK-159) and to the
     GitHub repo (TASK-160)?
   - Does it feel like "this is who is building the product" or
     does it read as boilerplate "team" copy?
   If it falls short on any of these, expand in scope — add a small
   "Get in touch" footer to the bio card with the same two-channel
   routing (email + GitHub). Don't restructure the section; just
   wire the credibility hook.

4. **TASK-162 — Footer "Get in touch" row** (P1). The footer
   currently shows "Built with love by Jan Mikeš · Source on GitHub →"
   (shipped in TASK-141). Add a same-row "Talk to Jan
   <jan.mikes@sendvery.com>" link OR a `/about/contact` link so the
   founder channel is reachable from every page, not just from the
   one new contact route. Small template change, no architect.

5. **Watchlist items** (no action expected unless real signal emerges):
   - **TASK-147 + TASK-151 follow-through** — still no logo asset,
     still no verified Twitter handle. Skip unless user lands them.
   - **`IngestionPathResolver::resolveForTeams` re-measure** — still
     demo-only at 3-domain scale. First production team hitting 50+
     monitored domains is the trigger; until then, no point measuring.
   - **`/app/alerts` empty-state copy** — carried since round 5 with
     no user signal. Defer unless user flags it this round.
   - **Marketing-page H1 register audit** — `_tool_layout.html.twig`
     still ships `font-extrabold` while the homepage runs `font-medium`
     page-end-to-end. Cosmetic inconsistency between marketing
     surfaces. Listed as a round-10 candidate in the round-9 RUN
     SUMMARY; pick up if scope allows after TASK-159/160/161/162.

The user supplied the round-10 contact-surface ask directly. Other
surface-level marketing feedback from the user during the round
should land as additional TASK entries before round-10 shipping
completes.

================================================================
WHAT IS ALREADY DONE — DO NOT RE-PROPOSE
================================================================
Skim `docs/cx-improvement-backlog.md` first. **TASK-001 through
TASK-158 are shipped or filed** (TASK-143 is `blocked`/superseded by
TASK-146 which shipped in round 9; TASK-147 + TASK-151 are
`deferred — future-watchlist`; everything else is `done`). Don't
re-propose anything in the nine historical RUN SUMMARY tables.

Round 9 specifically shipped:
- TASK-158 — homepage hero rewrite for user-value framing
- TASK-146 — per-domain DKIM-selector preference (migration + form +
  CQRS + wiring + 9 tests)
- TASK-148 / 149 / 150 / 152 — SEO polish (per-article dates +
  BreadcrumbList + WebSite SearchAction + 4 title trims)
- TASK-153 / 154 / 155 / 156 / 157 — DNS-generator nice-to-haves
  (drop dead targets + email validation + DTO refactor + status
  guards + omit-default-DMARC-tags)

Round 9 self-review caught zero must-fixes (clean first pass).
Round 9's reviewer-agent on TASK-146 caught 1 real must-fix
(missing idempotency test) shipped inline before commit.

Build on top — don't duplicate.

================================================================
SEED FOCUS AREAS (priority order — SHIP IN THIS ORDER)
================================================================
Four buckets. **TASK-159 (founder contact surface) ships first**
because it's the load-bearing piece of the user's ask — the contact
page is the artefact every other round-10 task either feeds traffic
into (TASK-160 / 161 / 162) or has a coherent answer to ("where can
I reach you?").

0. **TASK-159 — Founder contact surface** (P0, user-driven, ship
   FIRST)

   **Why this matters (verbatim from the user):** *"To build some
   trust and transparency, it is always good to show who is behind
   the project, who am i paying to. We need some contact section or
   something with contact form and public email jan.mikes@sendvery.com
   - some text like 'Talk directly to the founder'."* The implicit
   audience here is the visitor who's about to enter a credit card
   number on `/pricing` and wants to know there's a human on the
   other end. A no-reply support-ticket experience is the SaaS
   anti-pattern Sendvery is positioned against (open source +
   self-hostable + founder-built — credibility scales WITH this
   surface, not against it).

   **Architect must scope (architect-first):**

   - **Route placement decision.** Two reasonable shapes:
     - **Option A: dedicated `/about/contact` page.** Discoverable
       from the Nav (sub-menu under About) + Footer link + bio-card
       inline link. Pros: clean URL, full surface for the contact
       form + founder framing. Cons: one more public route.
     - **Option B: section on existing `/about/what-is-sendvery`
       page** (currently the de-facto About page, mounted at
       `/about/what-is-sendvery`). Pros: consolidates the "who is
       Sendvery / who is the founder / how do I reach them"
       narrative on one page. Cons: long page, contact form may not
       deserve its own canonical URL.
     - Architect's call. My read: **Option A is cleaner** because
       (a) the contact form needs its own `<h1>` for the SEO
       BreadcrumbList JSON-LD pattern shipped in TASK-149,
       (b) a sibling `/about/contact` keeps `/about/what-is-sendvery`
       focused on the product story, and (c) `/about/contact` is
       what visitors actually type when looking for it.

   - **Contact form backing.**
     - **Storage**: do form submissions land in the database
       (`contact_inquiry` table) for triage, or only in the
       founder's inbox?
       - DB-first (recommended): every submission persists for
         audit + future "we got X inquiries last month" stats. Add
         `ContactInquiry` entity + `CreateContactInquiry` command +
         handler. Spam-mitigation: store every row, even spam (just
         don't email out). The dashboard can later show a triage
         queue.
       - Email-only: submissions go straight to
         `jan.mikes@sendvery.com` via Symfony Mailer; no DB row.
         Simpler but loses audit trail.
     - **Email transport**: Symfony Mailer is already wired in the
       project (used by magic-link auth + weekly digest). Reuse the
       existing transport — don't introduce a new provider.

   - **Spam mitigation.**
     - **Honeypot field** (free): hidden `<input name="website">`
       that real users leave empty; bots fill everything. Reject
       submissions where the field is non-empty.
     - **Time-trap** (free): timestamp on form render; reject
       submissions that arrive < 2 seconds after render (bots
       submit instantly).
     - **Rate-limit** (built-in): Symfony's `RateLimiterFactory`
       per-IP at 5/hour is industry-standard for public contact
       forms.
     - **NOT shipping**: Cloudflare Turnstile / reCAPTCHA — adds
       3rd-party JS and a vendor relationship that conflicts with
       "open source + self-hostable" positioning. Honeypot +
       time-trap + rate-limit handles >99% of automated spam
       without a vendor lock-in.

   - **Copy framing** (Developer's judgement against the user's
     framing):
     - Page title: `"Talk to Jan — Founder of Sendvery"` or
       similar. NOT `"Contact us"` — the user explicitly wants
       founder-level framing.
     - H1: outcome-framed (per the round-9 hero-feedback memory
       `feedback-hero-leads-with-user-value`). E.g. *"Talk directly
       to the founder."* — same pattern as the homepage hero.
     - Lede: short paragraph naming Jan + his role (sole founder /
       maintainer / Czech-based engineer behind the project) +
       what he handles personally (billing questions, custom-plan
       requests, "is Sendvery right for me?", partnerships).
     - Routing-clarity callout: a sibling card/section that says
       *"Bug reports + feature requests live on GitHub →"* with the
       direct issues-tracker link. This is the seed of TASK-160 —
       file as a separate task but they SHIP on the same page so
       visitors see both routes at once.
     - Form fields: name, email, subject (free-form or dropdown:
       Sales / Support / Partnership / Other), message. Standard
       Symfony Form.

   - **Nav + Footer wiring** (part of acceptance):
     - Nav: add "Contact" link under the existing "About" dropdown
       (or as a top-level item — architect's call). Visible to
       both signed-out and signed-in visitors.
     - Footer: add the contact route to the appropriate footer
       column (likely "Company" or "About").
     - Sitemap: add `/about/contact` to `SitemapController`.

   - **Public email exposure**: the user explicitly wants
     `jan.mikes@sendvery.com` rendered as a visible mailto link on
     the contact page (not just inside the form-handler config).
     Spam-bot harvesting risk is mitigated by the inbox provider's
     spam filter — Sendvery is a tiny target, premature obfuscation
     would just signal "we hide our email." Render the link
     plainly: `<a href="mailto:jan.mikes@sendvery.com">jan.mikes@sendvery.com</a>`.

   **Acceptance:**
   - New route `/about/contact` (or `/contact` — architect's call)
     renders for both signed-out + signed-in visitors.
   - Page leads with founder-level framing (H1 + lede) — not
     generic "Contact us" copy.
   - Plain mailto link to `jan.mikes@sendvery.com` rendered visibly.
   - Symfony Form with name / email / subject / message fields,
     CSRF-protected, server-side validated.
   - Submission persists a `contact_inquiry` row (assuming DB-first
     architect decision) AND emails the founder via existing Symfony
     Mailer transport.
   - Honeypot field + time-trap + per-IP rate-limit (5/hour) — no
     3rd-party CAPTCHA.
   - "Thank you" confirmation page or flash message after submit.
   - Nav + Footer link to the new route.
   - Sitemap entry added.
   - JSON-LD: BreadcrumbList (Home > About > Contact) per the
     TASK-149 pattern + optional `ContactPage` schema.
   - Tests follow business-behaviour naming (per round-8 user
     feedback memory `feedback-tests-describe-business-behaviour`):
     `visitorCanSubmitContactFormAndFounderGetsEmail`,
     `honeypotFieldSilentlyRejectsBotSubmissions`,
     `rateLimiterBlocksSixthRequestWithinAnHour`,
     `formSubmissionPersistsContactInquiryRow`.
   - Coverage: 100% on new code (per CLAUDE.md).

   **Notes:**
   - Architect-first because of the multi-decision data-model + form
     + spam-mitigation + route-placement scope.
   - The contact form does NOT need to live behind authentication —
     it's an explicit public-facing surface. The rate-limit is the
     guardrail, not authentication.

1. **TASK-160 — Developer feedback / GitHub-issues surface** (P0,
   ship SECOND, lives on the SAME PAGE as TASK-159)

   **Why this matters (verbatim from the user):** *"as well
   incorporate link to github - one card/section for developers
   'Product suggestions or bug report - can open issue directly on
   github, where the code lives.'"* This is the second route of the
   two-channel routing pattern: business questions → founder email;
   engineering questions → GitHub Issues. If only the founder-email
   channel exists, the founder's inbox becomes the bug tracker. If
   only GitHub exists, non-technical visitors think "I have to
   create a GitHub account to ask about billing?" Both routes
   together = visitor self-selects the right channel.

   **Acceptance:**
   - Sibling card/section on the `/about/contact` page from TASK-159
     (NOT a separate page — they share one canonical contact surface
     so visitors see both routes at once).
   - Copy: developer-framed; matches the user's verbatim text:
     *"Product suggestions or bug reports — open an issue directly
     on GitHub, where the code lives."*
   - Direct link to `https://github.com/janmikes/Sendvery/issues/new`
     (NOT the repo root — the issues tracker is the actionable
     surface). Open in a new tab (`target="_blank" rel="noopener"`).
   - Visual treatment: distinct from the founder-email card so the
     two routes read as parallel choices, not duplicated content.
     Consider a left card (Mail glyph + "Talk to the founder" + form)
     and right card (GitHub glyph + "Open an issue" + button).
     Architect / Developer call.
   - Test: render the page + assert both routing cards exist + the
     GitHub link points at `github.com/janmikes/Sendvery/issues`.

   **Notes:**
   - No architect needed — straight to Build alongside TASK-159.

2. **TASK-161 — Founder bio expansion / contact wire-up** (P0, ship
   THIRD)

   **Why this matters:** the homepage `Founder bio` section (TASK-145
   narrative arc §12) is currently a credibility artefact but
   probably reads "here's who built this" without an actionable next
   step. Now that `/about/contact` exists (TASK-159), the bio
   should LINK to it — same way the round-9 hero links to the chip
   row for open-source credibility.

   **Acceptance:**
   - Audit the existing founder-bio section on `/` (find the
     section by reading `templates/homepage/index.html.twig` —
     section comment likely says "12. FOUNDER BIO" or similar).
   - Add a "Get in touch" footer to the bio card linking to
     `/about/contact` (the route TASK-159 ships).
   - If the bio currently DOESN'T expose the founder's name +
     credentials + role, expand in scope — same constraints as
     TASK-159's lede (sole founder / maintainer / what he handles
     personally). Don't restructure the section; tighten if needed.
   - Test: render `/` + assert the bio section contains a link to
     `/about/contact`.

   **Notes:**
   - Architect-first only if the bio needs structural changes;
     otherwise straight to Build.
   - This is the closing-loop step — TASK-159 creates the contact
     surface, TASK-161 makes sure visitors arriving via the
     homepage's most-credible section actually find it.

3. **TASK-162 — Footer "Get in touch" link** (P1, ship FOURTH)

   **Why this matters:** the footer currently shows "Built with love
   by Jan Mikeš · Source on GitHub →" (TASK-141). Adding a contact
   link makes the founder channel reachable from EVERY page, not
   just from the one new contact route. Pages visitors land on
   first (KB articles, tool pages, pricing) all get the contact
   affordance without a re-architect.

   **Acceptance:**
   - Add a "Talk to Jan →" or similar link in the footer, either:
     - In the same row as the existing "Built with love" attribution
       (compact), or
     - In a footer column (Sales/Contact/About — wherever it reads
       most naturally next to the existing Pricing / Open Source /
       KB links).
   - Link points to `/about/contact` (the route TASK-159 ships).
   - Test: render any public page (e.g. `/`) + assert the footer
     contains the contact link.

   **Notes:**
   - Single template change in the shared layout / footer partial.
     No architect.

4. **PERFORMANCE AUDIT** (round-10 baseline diff)

   Round 10's largest DB-touching change is TASK-159's
   `contact_inquiry` table — write-once on form submit, read-once
   when (eventually) a triage queue lands. Not on any hot path.
   The form-render cost is a Twig template + Symfony Form build,
   constant time.

   Expected perf delta vs round 9 ≈ 0. Spot-check after
   TASK-159 lands. Document in a new
   `## Round-10 performance audit (YYYY-MM-DD)` section above the
   round-9 one in `docs/cx-improvement-backlog.md`.

5. **ROUND-10 SELF-REVIEW** (every 3 shipped tasks)

   Same pattern as rounds 3-9. Round-10-specific things to watch for:

   - **TASK-159**: does the form actually email the founder? Run
     `bin/console messenger:consume` or check the Symfony Mailer
     dev profiler to confirm a message lands. Spam-mitigation
     defence-in-depth: honeypot + time-trap + rate-limit ALL must
     be active (not just declared in config). The rate-limit
     factory needs the `framework.rate_limiter` config block — if
     it's missing, the limit is silently a no-op.
   - **TASK-160**: GitHub link target — verify the issues URL
     resolves. `github.com/janmikes/Sendvery` (with the capital S
     per the round-9 push remote) NOT `github.com/janmikes/sendvery`.
     Inconsistent casing is a real GitHub redirect (the lowercase
     resolves but adds a 301 hop). Use the canonical casing.
   - **TASK-161**: don't double-mention the founder's name in the
     bio + the new "Get in touch" footer. Read together must flow,
     not repeat.
   - **TASK-162**: the footer is rendered on the dashboard too
     (signed-in users). Confirm the contact link is appropriate for
     both signed-out and signed-in audiences — for signed-in users,
     they might prefer the in-product support channel (if one
     exists; today there isn't one, so the founder email is the
     correct route).

================================================================
DURABLE STATE — backlog.md
================================================================
Maintain `docs/cx-improvement-backlog.md` as the single source of
truth. Schema (one block per task):

  ## TASK-NNN: <short title>
  - Status: proposed | planned | in-progress | in-review | done | blocked | deferred
  - Area: marketing | dashboard | domains | reports | onboarding | ops | trust | other
  - Why: <1-2 sentence user value>
  - Acceptance: <bulleted, testable criteria>
  - Notes: <architect plan, decisions, follow-ups>

Task numbering CONTINUES from the highest existing TASK-NNN. At
round-10 start, **NO tasks are filed in `proposed` status**
(TASK-158 was the last round-9 ship; TASK-147 + TASK-151 are
`deferred`). Round-10 work claims **TASK-159 through TASK-162**
(file them at the start of the PLAN PHASE before shipping).
Self-review findings or post-shipping follow-ups start at TASK-163.

This file survives compaction; ALWAYS read it before deciding what
to do next and ALWAYS update it after each phase transition.

Mirror only the currently-active task's sub-steps in TaskCreate /
TaskUpdate. Do not put the whole backlog there.

================================================================
ORCHESTRATOR LOOP
================================================================
Repeat until "Stop conditions" are met:

1. PLAN PHASE (if backlog has <3 `proposed` tasks for the current
   bucket): file the next seed tasks from §SEED FOCUS AREAS using
   the acceptance criteria.

2. PICK PHASE:
   Read backlog.md. Pick the highest-value `proposed` or `planned`
   task in the current seed bucket. Promote to `planned`. Seed-bucket
   order from §SEED FOCUS AREAS is the tiebreaker.

3. DESIGN PHASE:
   If the task already has a detailed architect plan in its Notes
   field, skip this phase. Otherwise, for non-trivial tasks (TASK-159
   for sure — new public surface + form + DB + spam-mitigation),
   spawn Architect agent; it appends `### Architect plan (YYYY-MM-DD)`
   to the task's Notes. Promote to `in-progress`. For TASK-160 /
   TASK-161 / TASK-162 the spec is detailed — skip Architect, go
   straight to Build.

4. BUILD PHASE:
   Spawn Developer agent. Pass the architect plan if one exists,
   otherwise pass the Acceptance criteria block verbatim. **Defensive
   write strategy** (round-4 lesson): prefer `Write` with full file
   contents over `Edit` when modifying open files; `Edit` calls were
   observed being reverted by an editor race during round 4's
   parallel runs. Heredoc-via-bash is another safe fallback.

5. REVIEW PHASE:
   Spawn Reviewer agent. Promote to `in-review`. Rounds 4-9 all
   showed Reviewer agents netting real findings on >50% of bundles —
   keep the rhythm. Round 9's reviewer caught the TASK-146
   missing-idempotency-test inline before commit.

6. FIX-IF-NEEDED PHASE:
   If Reviewer reports must-fix findings, either fix small ones
   yourself (orchestrator can use Edit/Bash for trivial corrections)
   or spawn Developer again for substantial fixes. Loop BUILD →
   REVIEW at most 2 extra times. If still failing after 3 attempts,
   mark `blocked` and move on.

7. SHIP PHASE:
   Run quality gates. If green: **commit AND push to `origin/main`**,
   then mark `done`. Commit per task (or per coherent bundle) —
   round-9 shipped 4 task-commits + 1 docs-commit = 5 total. Round 10's
   natural commit grain: 1 commit for TASK-159 + TASK-160 (they ship
   on the same page), 1 commit for TASK-161, 1 commit for TASK-162,
   1 docs commit = 4 total.

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
   anything wrong?". Round-9 self-review caught zero must-fixes
   (clean first pass); round 10's biggest risk is silent rate-limit
   misconfiguration on TASK-159 (the limit looks active but doesn't
   actually engage if the `framework.rate_limiter` config block is
   missing).

9. Go to step 1.

Run independent agents in parallel where the work doesn't depend on
each other. Round 10 has decent parallelisation: TASK-161 + TASK-162
can ship in parallel after TASK-159 + TASK-160 land (both depend on
the `/about/contact` route existing).

================================================================
AGENT CONTRACTS
================================================================

### Product agent (subagent_type: general-purpose)
Brief: "You are the product owner for Sendvery, an email
deliverability + DNS monitoring SaaS. Read CLAUDE.md, the orchestrator
brief, and the existing tasks in `docs/cx-improvement-backlog.md` so
you do not re-propose work that's already done (TASK-001 through
TASK-162 are shipped or planned by the time you run). Your job in
round 10 is the FINAL stop-condition sweep once the user-driven
trust + transparency surfaces have shipped. Form an honest
first-impression critique against the round-10 scope (founder
contact + developer feedback + bio wire-up + footer link) and
surface any 'we forgot' gaps the user hasn't named yet. Append
proposals to docs/cx-improvement-backlog.md using the schema.
Continue numbering from the highest existing TASK-NNN. Each
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
as `### Architect plan (YYYY-MM-DD)`. Do NOT write code. **Round 10's
primary Architect candidate is TASK-159** — needs route-placement
decision (`/about/contact` vs section on existing About page),
data-model decision (`contact_inquiry` table vs email-only), form
schema (Symfony Form class), spam-mitigation layering (honeypot +
time-trap + per-IP rate-limit, no 3rd-party CAPTCHA), nav + footer
wiring. TASK-160 / TASK-161 / TASK-162 are implementable from the
spec — skip Architect for those."

### Developer agent (subagent_type: general-purpose)
Brief: "Implement TASK-NNN per the Architect's plan (or the
Acceptance criteria if no architect plan exists). Follow CLAUDE.md
strictly. Write tests alongside.

**Test naming convention (round-8 user feedback, baked in):** test
method names describe BUSINESS BEHAVIOUR, not TASK-XXX ticket numbers.
Use names like `visitorCanSubmitContactFormAndFounderGetsEmail` or
`honeypotFieldSilentlyRejectsBotSubmissions` — never
`task159ContactForm*`. Assertion failure messages describe the
broken behaviour, not the originating ticket. Test docblocks CAN
reference TASK-XXX (that's documentation, not the test contract).

**Hero / page copy convention (round-9 user feedback, baked in as
durable memory `feedback-hero-leads-with-user-value`):** every
marketing-page H1 leads with outcome, not feature labels or
licence/stack chrome. The contact page H1 should follow the same
pattern (e.g. *'Talk directly to the founder.'* not *'Contact us'*).

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

For TASK-159 specifically:
- Verify the rate-limit is ACTUALLY engaged — Symfony's
  `RateLimiterFactory` silently no-ops if the
  `framework.rate_limiter` config block doesn't include the
  named limiter. Grep `config/packages/framework.php` (or yaml)
  to confirm.
- Verify the honeypot field is hidden via `style=\"display:none\"`
  or `tabindex=\"-1\" autocomplete=\"off\"` — visible honeypots get
  filled by real users with screen-readers and the form silently
  rejects legitimate submissions.
- Verify the time-trap rejects submissions that arrive < 2 seconds
  after render (not < 200ms — slow human typers DO finish a contact
  form in under a second sometimes).
- Verify the founder's email is the LITERAL `jan.mikes@sendvery.com`
  per the user's verbatim ask. Don't substitute `support@` or
  `hello@` — the user wants the founder-level framing explicit.
- Verify the new test methods follow business-behaviour naming
  (no `task159*` prefixes; assertion messages describe behaviour).
- Verify the form CSRF token is present + validated server-side.
- Verify multi-tenancy: contact form submissions don't carry team
  context (it's a public surface), so `contact_inquiry` rows must
  NOT have a `team_id` FK that would auto-scope via the Doctrine
  filter (this would silently filter out every row when viewed
  from a dashboard team context).

For TASK-160: verify the GitHub link uses the canonical
`github.com/janmikes/Sendvery` casing (capital S) not the lowercase
`sendvery` (which 301-redirects)."

================================================================
QUALITY GATES (run before every commit)
================================================================
All must pass — no skipping, no --no-verify:
- docker compose exec app vendor/bin/phpunit (2303 tests at round-10 start)
- docker compose exec app vendor/bin/phpstan
- docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
- For UI tasks: read the page, confirm desktop AND 360px mobile render
- 100% coverage on new code (per CLAUDE.md)
- `ClockInterface::now()` used everywhere — never `new \DateTimeImmutable()`
  in production code paths
- **Test naming**: new test method names describe BUSINESS BEHAVIOUR
  (per round-8 user feedback). No `task159*` / `taskNNN*` prefixes
  in the public method name. Docblocks can keep the TASK-XXX
  reference.
- **Hero / page-lead copy**: marketing-page H1s lead with outcome,
  not feature labels or licence/stack chrome (per round-9 user
  feedback memory `feedback-hero-leads-with-user-value`).
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
- **Generate + apply a new Doctrine migration for TASK-159** — the
  `contact_inquiry` table add is a standalone metadata schema change.
  Use the existing migration command pattern.
- Wire the contact form to the existing Symfony Mailer transport
  (already used by magic-link auth + weekly digest). Don't introduce
  a new email provider.
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
- Re-introduce the homepage hero secondary "View on GitHub" CTA
  (removed TASK-158 — single hero CTA only; GitHub still in footer
  + Open Source section + TASK-160's contact-page card).
- Re-introduce the homepage hero eyebrow above the H1 (removed
  TASK-158 — duplicated H1 keywords without information).
- Re-introduce the open-source pitch in the homepage hero subhead
  (removed TASK-158 — belongs in chip row + Open Source section).
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
- **Use 3rd-party CAPTCHA** (reCAPTCHA / hCaptcha / Turnstile) on
  the contact form. The user's positioning is "open source +
  self-hostable + founder-built"; a Google/Cloudflare CAPTCHA
  contradicts that. Honeypot + time-trap + Symfony rate-limit
  handles >99% of spam without a vendor relationship.

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

Round 9 hit the primary stop signal (backlog drained against the
user-driven scope; TASK-147 + TASK-151 deferred to future-watchlist
with clear prerequisite notes). Round 10 should aim to do the
same — TASK-159 + TASK-160 + TASK-161 + TASK-162 is about the size
of round 7 (3-4 substantive surfaces, mostly marketing-side, single
new DB table).

When you stop, append a final summary to
docs/cx-improvement-backlog.md: tasks shipped, tasks blocked + why,
self-review findings, surfaces you reviewed and judged "good enough",
perf-audit measurements (even null results), and suggested round-11
seed areas.

================================================================
KICKOFF (round-10 start)
================================================================
1. Read `docs/cx-improvement-backlog.md` for the latest state. At
   round-10 start, **NO tasks are filed in `proposed` status**
   (TASK-147 + TASK-151 are `deferred — future-watchlist`).
   TASK-159 through TASK-162 need to be filed during the PLAN PHASE
   per the SEED FOCUS AREAS spec above.
2. CLAUDE.md is already loaded. Skim `docs/` for reference; pull in
   specific files only when the current task needs them.
3. **Ship TASK-159 + TASK-160 FIRST** — they live on the SAME PAGE
   (`/about/contact`) and ship in one commit. TASK-159 is the heavy
   one (new route + form + DB + spam-mitigation + nav/footer wiring)
   and needs an Architect pass; TASK-160 is the sibling GitHub-issues
   card on the same page (no architect).
4. **Ship TASK-161** — homepage founder-bio wire-up to the new
   contact route. Single template edit, no architect.
5. **Ship TASK-162** — footer "Talk to Jan" link on every page.
   Single template edit, no architect.
6. **Run the round-10 perf audit** after all tasks land. Document
   the numbers in a new `## Round-10 performance audit (YYYY-MM-DD)`
   section above the round-9 perf section.
7. After every 3 shipped tasks, run a self-review pass.
8. Final Product-agent sweep across all buckets as the
   stop-condition check.
9. Write the RUN SUMMARY when the backlog is truly empty. Cover:
    every task shipped, any blocked + why, self-review findings +
    dispositions, suite growth (from 2303 baseline), perf-audit
    measurements (round-10 vs round-9 diff), suggested round-11 seed
    areas.

================================================================
LESSONS FROM ROUNDS 4 + 5 + 6 + 7 + 8 + 9 — APPLY HERE
================================================================
- **Editor-revert race** (round 4): prefer `Write` (full file
  content) or heredoc-via-bash when modifying files that might be
  open in an editor / under linter watch. Rounds 5-9 used this
  defensively and lost zero edits.
- **Parallel agents**: 3 concurrent is the sweet spot. Round 8
  parallelised the TASK-142 + TASK-144 architect runs successfully.
- **Self-review payoff**: round-3 caught 3, round-4 caught 6, round-5
  caught 3, round-6 caught 0, round-7 caught 1, round-8 caught 0,
  round-9 caught 0 (clean first passes on the marketing-only +
  data-model-only scopes). Run self-review every 3 ships without
  exception — even a clean pass is a confidence signal.
- **Don't over-architect small tasks**: round-9 skipped Architect
  for TASK-148/149/150/152/153/154/155/156/157/158 (single-template
  / mechanical refactor scopes). Round 10 should do the same —
  Architect only for TASK-159 (route + form + DB + spam-mitigation).
- **Commit per task or per coherent bundle**: round-9 shipped 4
  task-commits + 1 docs-commit = 5 total (TASK-158 standalone,
  TASK-146 standalone, SEO bundle, TASK-144 nice-to-haves bundle,
  docs). Round 10's natural commit grain: 1 commit for
  TASK-159 + TASK-160 (same page), 1 commit for TASK-161, 1 commit
  for TASK-162, 1 docs commit = 4 total.
- **Reviewer agents net real findings on >50% of bundles**: rounds
  4-9 all confirmed this. Round-9 reviewer caught 1 must-fix on
  TASK-146 (missing idempotency test). Keep the review step even
  when the dev agent reports "all green".
- **Cross-surface consistency tests pay off**: round 5's
  `SurfaceConsistencyTest`, round 6's TASK-130 codified guards,
  round 7's `InMemoryQueryLogger`, round 8's
  `publicPagesShipSeoBaseline`, round 9's
  `customDkimSelectorIsPassedThroughToTheChecker` (DnsMonitor →
  DkimChecker wiring guard) all caught real regressions at the test
  layer. Round 10 should add a guard that fails fast if the contact
  form silently stops emailing the founder (e.g. via Symfony Mailer
  test transport assertion).
- **User-driven tasks have the highest signal**: rounds 6, 7, 8, 9
  were all user-driven. Round 10 is also user-driven — the founder-
  contact + GitHub-feedback surfaces are an explicit user ask, not
  orchestrator-discovered backlog. Quality bar is same as round 9.
- **Push continuously, not in a batch**: rounds 6-9 all pushed
  every commit before moving on. No carryover of unpushed commits.
  Round 10 enforces this as a quality gate.
- **Marketing copy ≠ tech stack**: rule still applies — nothing on
  user-facing surfaces names the tech stack. The contact form
  doesn't need to name Symfony Mailer; just "we'll get back to you."
- **Tests describe business behaviour, not ticket numbers**
  (round-8 user feedback, baked in as durable memory
  `feedback-tests-describe-business-behaviour`): new tests this round
  MUST follow the `methodNameDescribesBehaviour` convention.
  Assertion messages describe the broken behaviour, not the
  originating TASK. Docblocks can still reference TASK-NNN — that's
  documentation, not the test contract.
- **Hero leads with user value, not feature labels or open-source
  pitch** (round-9 user feedback, baked in as durable memory
  `feedback-hero-leads-with-user-value`): the contact page H1 must
  follow the same convention. *"Talk directly to the founder."* not
  *"Contact us"*. *"Talk to Jan — Founder of Sendvery."* not
  *"Get in touch with the Sendvery team."*
- **Trust + transparency conventions for startup product** (round-10
  framing from the user, recommended new durable memory
  `feedback-startup-trust-transparency`): founder-level contact
  framing, plain mailto links (not obfuscated), two-channel routing
  (business → founder email; engineering → GitHub Issues), no
  3rd-party CAPTCHA (positioning conflicts with open-source +
  self-hostable). Apply this lens to every public-facing copy
  decision this round.
