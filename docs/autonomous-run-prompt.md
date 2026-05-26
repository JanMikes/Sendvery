# Autonomous CX/Product Improvement Run — Sendvery (Round 11: DKIM selector UX + DMARC RUA extend-vs-replace + RFC 7489 authorization records)

You are the ORCHESTRATOR. Your job is to autonomously improve Sendvery's
marketing surfaces + dashboard by running a continuous loop of
specialised subagents. You self-pace. You DO NOT stop to ask the user
for permission on anything covered by "Autonomy". You DO NOT stop until
the backlog is genuinely empty (Product agent confirms nothing more is
worth doing) or you hit a real blocker in "Stop conditions".

================================================================
CHECKPOINT — WHAT ROUND 10 SHIPPED (read this first)
================================================================
Round 10 closed cleanly with **7 tasks shipped** across 4 code commits
+ 1 docs commit. Final state: **2326 tests / 7041 assertions**, all
gates green, all commits pushed to `origin/main`.

Shipped in round 10 (in this order):

- **TASK-159 + TASK-160** (commit `f6365f0`) — `/about/contact`
  founder-contact surface + GitHub-issues sibling card. New public
  route with H1 outcome-framed ("Talk directly to the founder."),
  founder-level lede, plain mailto `jan.mikes@sendvery.com`, Symfony
  Form with CSRF + server-side validation, `contact_inquiry` DB table
  (NO `team_id` FK — public surface, must not be team-scoped),
  `CreateContactInquiry` CQRS command/handler that persists row AND
  emails the founder via existing `MailerInterface`. Spam mitigation:
  honeypot (display:none + tabindex=-1) + time-trap (≥ 2s) + per-IP
  rate-limit (5/hour token-bucket via `RateLimiterFactory`). NO
  3rd-party CAPTCHA. GitHub-issues sibling card routes engineering
  questions to `github.com/janmikes/sendvery/issues/new`. Nav +
  Footer Trust column + Sitemap wired. BreadcrumbList JSON-LD. 19 new
  business-behaviour-named tests (18 controller + 1 handler).
  Reviewer caught 1 nice-to-have (custom email-count helper
  redundant) — applied inline.

- **TASK-161** (`feff7a8`) — Homepage founder bio primary CTA wires
  to `/about/contact`. New "Talk to the founder →" button prepended
  to FounderBio.html.twig's button row. 1 new test.

- **TASK-162** (`58a5e0a`) — Footer attribution row surfaces
  "Talk to Jan →" on every page. 1 new test.

- **TASK-163 + TASK-164 + TASK-165** (`75c62c7`) — Product-sweep
  trust-polish bundle. TASK-163: response-time SLA ("Jan replies
  within 24 hours on EU business days.") now visible pre-submit on
  contact page. TASK-164: privacy policy enumerates `contact_inquiry`
  data collection (name, email, subject, message, submitter IP,
  user-agent) with 24-month retention entry. TASK-165: GitHub URL
  casing aligned to lowercase across all surfaces (the capital-S
  premise was wrong — verified no 301 redirect happens).

- **Docs** (`764358f`) — Round-10 RUN SUMMARY + perf audit + flip
  TASK-159/160/161/162/163/164/165 statuses to done.

Round 10 final stats: **2303 → 2326 tests (+23), 6913 → 7041
assertions (+128)** vs round-9 baseline. Perf delta vs round 9 ≈ 0
(no dashboard hot-path changes; new `/about/contact` POST cost is
1 INSERT + 1 SMTP RTT, capped at 5/hour per IP).

**Deferred to round-11 watchlist** (carried from rounds 9-10):

- **TASK-147** — Organization JSON-LD `logo`. No square logo asset.
- **TASK-151** — `twitter:site` handle. No verified account.
- **Marketing-page H1 register audit** — `font-extrabold` vs
  `font-medium` inconsistency between tool pages and homepage.
- **`/app/alerts` empty-state copy** — no user signal since round 5.
- **`IngestionPathResolver::resolveForTeams` re-measure** — demo-only.
- **DKIM selector UX expansion** — round 9 v1 is free-form text.
- **Imprint / legal entity statement on `/about/contact`** — German
  Impressumspflicht. Low-priority until first EU-enterprise buyer.
- **Urgent-issues framing on `/about/contact`** — only matters once
  Sendvery has paying customers reporting incidents.
- **Public roadmap link** — bio mentions public issue triage but no
  roadmap surface exists.
- **Dashboard triage queue for `contact_inquiry`** — only once volume
  justifies it.

================================================================
MISSION
================================================================
Round 11 is **user-driven dashboard + DNS tooling improvement**:
the user wants to improve the DKIM selector experience and add
intelligent DMARC RUA handling so Sendvery can coexist with existing
DMARC setups instead of demanding users replace their entire config.

User's round-11 asks (two scopes):

**Scope A — DKIM selector UX:**

> "We should improve DKIM selector - in the dashboard show what dkim
> selector is currently active (saved to the domain) and ability to
> change it. We already have form to change it but i do not think it
> is user friendly now."

**Scope B — DMARC RUA extend-vs-replace + RFC 7489 authorization:**

> "[...] for dmarc, i am worried we need dynamically change our DNS,
> adding support for customer domains (+ if user already has active
> dmarc reports email, we can offer instead of replacing it with ours,
> simply extending)"
>
> Plus this context the user shared:
> "Both rua (aggregate reports) and ruf (failure reports) accept
> multiple URIs separated by commas:
> `v=DMARC1; p=reject; rua=mailto:dmarc@yourdomain.com,mailto:reports@external-service.com`
>
> One gotcha worth knowing: if any of the report addresses are on a
> different domain than the one publishing the DMARC record, that
> external domain must publish an authorization record, or most
> receivers will silently drop reports to it. For example, if
> myspeedpuzzling.com wants reports sent to dmarc@some-monitoring-service.com,
> then some-monitoring-service.com needs this TXT record:
> `myspeedpuzzling.com._report._dmarc.some-monitoring-service.com IN TXT "v=DMARC1;"`
>
> Monitoring services like Postmark, dmarcian, Valimail, etc. publish
> these automatically for their customers, so you don't have to worry
> about it when using them — only when routing to arbitrary
> third-party mailboxes.
>
> Also, RFC 7489 lets receivers cap the number of addresses they'll
> send to (commonly 2 per tag), so don't go wild — typically one
> internal mailbox plus one monitoring service is the practical pattern."

================================================================
CODEBASE INVENTORY — WHAT ALREADY EXISTS (read before designing)
================================================================

### DKIM Selector (round-9 TASK-146 shipped state)

**Entity:** `src/Entity/MonitoredDomain.php` — `dkimSelector` is a
nullable VARCHAR(255). When null → brute-force via
`DkimSelectorRegistry::PROVIDER_SELECTORS`. When set → DnsMonitor
passes it to `DkimChecker::check()` directly.

**Form/Controller:** `src/Controller/Dashboard/SetDomainDkimSelectorController.php`
(POST `/app/domains/{id}/dkim-selector`). Validates CSRF + RFC 1035
DNS-label regex. Flash messages on success/failure. Redirects to
domain detail.

**Template:** `templates/dashboard/domain_detail.html.twig` lines
48-86. A card with:
- Title "DKIM selector"
- Explanatory copy: "Sendvery normally brute-forces common selectors…"
- Free-form `<input>` pre-filled with current value
- Placeholder: "e.g. selector1 — leave empty to brute-force"
- "Save & re-check DNS" button

**Selector registry:** `src/Services/Dns/DkimSelectorRegistry.php`
maps provider names → known selectors (Google→`google`, Microsoft→
`selector1`/`selector2`, Mailgun→`k1`/`mta`/`pic`/`mailgun`, etc.
53 total selectors across ~15 providers). Also has a generic
fallback probe list.

**DNS integration:** `src/Services/Dns/DkimChecker.php` — if selector
is provided, checks that one directly. If null, detects provider
from MX/CNAME records and brute-forces selectors from registry.

**What the user says is wrong:** the form isn't user-friendly. There's
no display of what selector Sendvery DETECTED during the last DNS
check (only what the user manually saved). If DkimChecker found the
DKIM key via brute-force (e.g. it discovered `selector1`), the user
never sees that discovery — they just see "leave empty to
brute-force" and a blank input. The form should show: (1) what
selector Sendvery found during the last check (the detected one),
(2) whether that matches the saved preference, (3) provider-aware
suggestions based on MX records (e.g. "We detected Microsoft 365
MX records — try `selector1`").

### DMARC RUA Handling (current state)

**Single shared inbox:** `src/Services/ReportAddressProvider.php`
returns `reports@sendvery.com` (from env `SENDVERY_REPORT_ADDRESS`).
One inbox for all customers. No per-domain ingest addresses.

**RUA scenario classification:** `src/Value/Dns/RuaScenario.php` —
three enum cases: `NoRecord`, `PointsAtSendvery`, `PointsAtExternal`.
Resolved per-domain by `src/Services/Dns/RuaScenarioResolver.php`.

**Extend/append logic ALREADY EXISTS:**
`src/Value/Dns/DmarcRuaInstruction.php` has a `build()` static
factory that:
- If no DMARC record: creates new with `rua=mailto:reports@sendvery.com`
- If DMARC exists but Sendvery NOT in rua: **APPENDS** Sendvery's
  address to the existing comma-separated list
- If DMARC exists and Sendvery already in rua: returns unchanged,
  `alreadyConfigured: true`
Preserves canonical tag ordering (v, p, sp, rua, ruf, …).

**Setup status display:** `src/Services/DomainSetupStatusResolver.php`
lines 309-388 builds the RUA checklist row. Three scenarios:
1. No record → "Publish a _dmarc TXT record with rua=mailto:{addr}"
2. Points at Sendvery → "Pointing at Sendvery — reports flow in"
3. Points at External → yellow warning: "Pointing at {ext} — connect
   that inbox or repoint to Sendvery"
The `PointsAtExternal` case today says OR — replace or connect. It
does NOT currently offer the "extend" path (add Sendvery alongside
the existing address). The `DmarcRuaInstruction.build()` logic does
the extend correctly, but the UX doesn't surface it.

**DMARC generator tool:** `src/Controller/DmarcCheckerController.php`
(route `/tools/dmarc-checker`) pre-fills RUA with
`reports@sendvery.com`. Template supports comma-separated multiple
addresses. Helper text: "Comma-separate multiple mailboxes."

### RFC 7489 Authorization Records — NOT IMPLEMENTED

**Zero references to `_report._dmarc`** in the entire codebase.

When a domain's `rua=` points to an address on a DIFFERENT domain
(e.g. `example.com` → `rua=mailto:reports@sendvery.com`), the
receiving domain (`sendvery.com`) must publish an authorization TXT
record at: `example.com._report._dmarc.sendvery.com IN TXT "v=DMARC1;"`

Without this, most receivers (Google, Microsoft, Yahoo) silently
drop the DMARC aggregate reports. This is WHY the extend pattern
matters: if a user adds `reports@sendvery.com` to their rua list,
Sendvery MUST publish the authorization record for the user's domain,
or the user's ISPs will never actually send reports to Sendvery.

Commercial services (dmarcian, Valimail, Postmark) handle this
automatically for their customers. Sendvery needs the same capability.

**Implementation options for authorization records:**
1. **DNS API integration** (e.g. Cloudflare, Hetzner DNS, Route 53) —
   Sendvery controls its own DNS and can auto-publish
   `{customer-domain}._report._dmarc.sendvery.com TXT "v=DMARC1;"`
   when a domain is added. Requires DNS provider API credentials +
   an async job that manages record lifecycle (create on domain-add,
   delete on domain-remove). This is what commercial services do.
2. **Manual operator task** — Sendvery surfaces a banner "Authorization
   record needed: ask your DNS admin to add X" and the operator
   (Jan) manually adds TXT records to sendvery.com DNS. Doesn't scale
   but works for early stage.
3. **Verification check** — Sendvery queries for the authorization
   record during DNS checks and warns the user if it's missing
   ("Reports may not be delivered because sendvery.com hasn't
   published your authorization record yet — contact support"). This
   is the bare minimum: detection + guidance, no automation.

**Practical constraint the user flagged:** RFC 7489 lets receivers
cap rua addresses (commonly 2 per tag). Sendvery should advise
"one internal mailbox + one monitoring service" as the practical
limit and NOT encourage >2 rua addresses.

================================================================
SEED FOCUS AREAS (priority order)
================================================================

### TASK-166 — DKIM selector UX improvement (P0, dashboard)

**What the user asked for:** show what DKIM selector is currently
active (saved + detected) and make the change flow more user-friendly.

**Current state assessment:**
- The form works (TASK-146 shipped it), but it's expert-facing:
  a blank text input with "leave empty to brute-force" placeholder.
- The `DkimChecker` DISCOVERS which selector worked during the last
  DNS check, but that information is not persisted or surfaced.
  The check result lives in `dns_check_result` (via
  `DnsCheckResultPersister`) but the user never sees "we found your
  DKIM key at selector `google`."
- The `DkimSelectorRegistry` knows which providers use which
  selectors, and MX records reveal the provider — so Sendvery could
  suggest "We detect Microsoft 365 MX records → try `selector1`."

**Scope (architect-first because of data-flow + UX changes):**

1. **Surface the detected selector on the domain detail page.**
   After a DNS check, the `DkimCheckResult` knows which selector
   succeeded (if any). Thread that information through to the
   domain detail template. Show it as a read-only "Detected
   selector" label above the form input: e.g. "Last check found
   DKIM at selector `google` (2048-bit RSA)." When the brute-force
   found nothing: "No DKIM key detected on any common selector."

2. **Provider-aware selector suggestions.** MX records are already
   checked in `DnsMonitor`. Use the provider detection that
   `DkimChecker` already does to suggest likely selectors:
   "We detected Google Workspace MX records — common selectors:
   `google`." The user can click a suggestion to pre-fill the input
   instead of typing blind.

3. **Show current saved vs detected state clearly.** Three-state
   display:
   - Saved preference: `selector1` (or "Auto-detect / brute-force")
   - Last detection: "Found at `selector1`" / "Not found"
   - Status badge: "Match" (saved = detected), "Mismatch" (saved
     points to X but detection found Y — likely a config error),
     "Not checked yet"

4. **Make the form less expert-facing.** Instead of a raw text input:
   - Show detected/suggested selectors as clickable chips/buttons
   - Keep the free-form override for custom selectors not in the
     registry
   - "Reset to auto-detect" button (clears the saved preference)

**Acceptance:**
- Domain detail page shows the LAST-DETECTED selector from
  the most recent DNS check (read from persisted check data).
- Provider-aware suggestions visible based on MX record analysis.
- The saved preference vs detected state is clear at a glance.
- Selector suggestions are clickable (pre-fill the input).
- "Reset to auto-detect" button clears the preference.
- The existing CSRF + RFC 1035 validation + idempotency guard
  continue to work.
- Tests: business-behaviour names, 100% coverage on new code.

### TASK-167 — DMARC RUA "extend" path UX (P0, dashboard)

**What the user asked for:** when a user already has active DMARC
reports going somewhere, offer to EXTEND (add Sendvery alongside)
rather than only REPLACE.

**Current state assessment:**
- The extend logic is already implemented in
  `DmarcRuaInstruction.php` — it appends `mailto:reports@sendvery.com`
  to existing rua addresses when building the recommended record.
- But the dashboard's `DomainSetupStatus` component only shows two
  options for `PointsAtExternal`: "connect that inbox" or "repoint
  to Sendvery." It doesn't surface the "add Sendvery alongside
  your existing address" path.
- The DMARC generator tool (`/tools/dmarc-checker`) does support
  comma-separated rua addresses, but it's a public tool page, not
  the dashboard setup flow.

**Scope:**

1. **Update the `PointsAtExternal` setup status row** to surface
   the extend option prominently. When the user's DMARC record has
   `rua=mailto:existing@example.com`, the checklist should say:
   "Your DMARC reports go to `existing@example.com`. To also
   receive them in Sendvery, update your rua tag to:
   `rua=mailto:existing@example.com,mailto:reports@sendvery.com`"
   with a copy-to-clipboard button for the full updated record.

2. **Use `DmarcRuaInstruction::build()` to generate the exact
   record the user should publish.** This logic already handles
   appending, deduplication, and canonical tag ordering. Surface
   the `.instruction` string in the setup status component as the
   copy target.

3. **Warn about the RFC 7489 authorization record requirement.**
   When the extend path is shown, add a note:
   "For Sendvery to receive reports, an authorization record is
   needed on sendvery.com's DNS. This is handled automatically
   for SaaS customers. Self-hosters: see [docs link]."
   (The actual automation is TASK-168; the UX warning ships first.)

4. **Warn about the 2-address practical limit.** If the existing
   rua already has 2+ addresses, show a gentle warning:
   "RFC 7489 lets receivers cap report delivery to 2 addresses.
   Adding a third may cause some ISPs to silently drop reports.
   Consider replacing one of your existing addresses with
   Sendvery's instead."

**Acceptance:**
- The `PointsAtExternal` checklist row shows the extend option
  with a copy-to-clipboard full DMARC record.
- The generated record comes from `DmarcRuaInstruction::build()`.
- Authorization-record warning is visible.
- >2 address warning is visible when applicable.
- "Already configured" case (Sendvery already in rua) correctly
  shows green/configured state with no action needed.
- Tests: business-behaviour names, 100% coverage on new code.

### TASK-168 — RFC 7489 `_report._dmarc` authorization record awareness (P1, dashboard + DNS)

**What the user identified:** Sendvery can't receive DMARC reports
unless `sendvery.com` publishes authorization TXT records for each
customer domain. Without this, ISPs silently drop reports.

**Scope (architect-first — involves DNS queries + potentially DNS
automation):**

Phase 1 (ship in round 11 — detection + guidance):

1. **Check for the authorization record during DNS checks.** In
   `DnsMonitor::check()` (or a new `DmarcAuthorizationChecker`),
   after detecting that the user's DMARC rua includes
   `reports@sendvery.com`, query for the TXT record at:
   `{customer-domain}._report._dmarc.sendvery.com`
   If it exists and contains `v=DMARC1` → authorization is in place.
   If missing → flag a warning.

2. **Surface the authorization status in the domain detail page.**
   New row in DomainSetupStatus (or a sibling component): "Report
   authorization: ✓ Configured" or "⚠ Missing — ISPs may not
   deliver reports to Sendvery."

3. **When missing, show the exact TXT record needed.** The user
   can forward this to their ops team or Jan can add it manually
   to sendvery.com DNS:
   `{domain}._report._dmarc.sendvery.com IN TXT "v=DMARC1;"`

4. **Self-hoster guidance.** Self-hosters need the same record on
   their own domain's DNS. The instructions should use the dynamic
   `ReportAddressProvider::get()` domain, not hardcoded
   `sendvery.com`.

Phase 2 (deferred to future round — DNS automation):

5. **Auto-publish authorization records via DNS provider API.**
   When a domain is added and rua points to Sendvery, automatically
   create the `_report._dmarc` TXT record on sendvery.com's DNS.
   When a domain is removed, clean it up. This requires DNS
   provider API credentials (Cloudflare, Hetzner DNS, etc.) and a
   lifecycle management system. OUT OF SCOPE for round 11 — just
   file as a follow-up.

**Acceptance (Phase 1 only):**
- DNS checks query for the `_report._dmarc` authorization record.
- Domain detail page shows authorization status.
- Missing-authorization warning shows the exact TXT record needed.
- Works for both SaaS (sendvery.com) and self-hosters.
- Tests: business-behaviour names, 100% coverage on new code.

### Watchlist items (no action expected unless signal emerges)

Carried from round 10:
- TASK-147 + TASK-151 — logo + Twitter handle
- Marketing-page H1 register audit
- `/app/alerts` empty-state copy
- `IngestionPathResolver::resolveForTeams` re-measure
- Imprint/legal entity on `/about/contact`
- Urgent-issues framing on `/about/contact`
- Public roadmap link
- Dashboard `contact_inquiry` triage queue

================================================================
WHAT IS ALREADY DONE — DO NOT RE-PROPOSE
================================================================
Skim `docs/cx-improvement-backlog.md` first. **TASK-001 through
TASK-165 are shipped or deferred** (TASK-143 blocked/superseded;
TASK-147 + TASK-151 deferred; everything else done). Don't re-propose
anything in the ten historical RUN SUMMARY tables.

Round 10 specifically shipped:
- TASK-159 + 160 — `/about/contact` founder contact + GitHub card
- TASK-161 — homepage bio CTA to `/about/contact`
- TASK-162 — footer "Talk to Jan →" on every page
- TASK-163 — pre-submit response-time SLA on contact page
- TASK-164 — privacy policy enumerates contact-form data
- TASK-165 — GitHub URL casing aligned to lowercase site-wide

Build on top — don't duplicate.

================================================================
ORCHESTRATOR LOOP
================================================================
Same loop as rounds 3-10. Repeat until "Stop conditions" are met:

1. PLAN PHASE — file seed tasks from §SEED FOCUS AREAS.
2. PICK PHASE — pick highest-value proposed/planned task.
3. DESIGN PHASE — Architect agent for non-trivial tasks
   (TASK-166 + TASK-168 both need architect passes; TASK-167 is
   implementable from the spec — the extend logic already exists in
   `DmarcRuaInstruction.php`).
4. BUILD PHASE — Developer agent.
5. REVIEW PHASE — Reviewer agent.
6. FIX-IF-NEEDED PHASE.
7. SHIP PHASE — quality gates + commit + push.
8. SELF-REVIEW PHASE (every 3 shipped tasks).
9. Go to step 1.

**Shipping order:** TASK-166 (DKIM selector UX) first because it's
self-contained and unblocks the user's dashboard frustration. Then
TASK-167 (RUA extend UX) because it surfaces existing logic. Then
TASK-168 (authorization record awareness) because it depends on
understanding how RUA extension works.

**Commit grain:** 1 commit per task (or per coherent bundle if
tightly coupled). Push after every commit.

================================================================
AGENT CONTRACTS
================================================================

### Product agent (subagent_type: general-purpose)
Same as rounds 3-10. Runs the final stop-condition sweep. Knows
TASK-001 through TASK-165 are done. Proposals start at the highest
existing TASK-NNN + 1.

### Architect agent (subagent_type: feature-dev:code-architect)
Brief for TASK-166: "Design the DKIM selector UX improvement.
Key files to read: `src/Services/Dns/DkimChecker.php` (how detection
works), `src/Services/Dns/DkimSelectorRegistry.php` (provider map),
`src/Results/DomainDetailResult.php` (what's threaded to the template),
`templates/dashboard/domain_detail.html.twig` lines 48-86 (current
form). Design: (1) how to persist/thread the detected selector to the
UI, (2) provider suggestion chips from MX analysis, (3) saved vs
detected three-state display, (4) template layout with daisyUI v5."

Brief for TASK-168: "Design the RFC 7489 `_report._dmarc`
authorization record checker. Key files:
`src/Services/Dns/DnsMonitor.php`, `src/Services/Dns/DmarcChecker.php`,
`src/Services/DomainSetupStatusResolver.php` (the 5-row checklist).
Design: (1) new DNS query for `{domain}._report._dmarc.{sendvery-host}`
TXT, (2) where to persist the check result, (3) how to surface status
in the domain setup checklist, (4) copy for the missing-record
guidance."

### Developer agent (subagent_type: general-purpose)
Same conventions as rounds 3-10. Tests describe business behaviour.
Hero/page copy leads with user value. `ClockInterface` only.
`IdentityProvider` for all IDs. No `dark:`. No YAML configs.

### Reviewer agent (subagent_type: feature-dev:code-reviewer)
Round-11-specific checks:
- TASK-166: verify the detected-selector display reads from persisted
  DNS check data (not a live DNS query on every page load — that
  would be slow and rate-limit-prone). Verify the form still works
  when no DNS check has been run yet (new domain, no detected data).
- TASK-167: verify the extend-path copy uses `DmarcRuaInstruction::build()`
  output (not manually building the record string). Verify the
  2-address warning triggers at the right threshold.
- TASK-168: verify the authorization-record check uses the dynamic
  `ReportAddressProvider` domain (not hardcoded `sendvery.com`).
  Verify the check doesn't fire when rua doesn't include Sendvery.

================================================================
QUALITY GATES (run before every commit)
================================================================
All must pass — no skipping, no --no-verify:
- docker compose exec app vendor/bin/phpunit (2326 tests at round-11 start)
- docker compose exec app vendor/bin/phpstan
- docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
- For UI tasks: read the page, confirm desktop AND 360px mobile render
- 100% coverage on new code (per CLAUDE.md)
- `ClockInterface::now()` used everywhere
- Test naming: business behaviour, no taskNNN* prefixes
- After each commit: `git push origin main`

================================================================
AUTONOMY (do these without asking)
================================================================
- Read/write any file in the repo.
- Run docker compose / composer / phpunit / phpstan / cs-fixer.
- Run `bin/console sendvery:*` commands including `sendvery:demo:seed`.
- Generate + apply Doctrine migrations if needed.
- Wire new DNS checks into the existing `DnsMonitor` pipeline.
- Create commits on main AND push to origin.
- Update docs/cx-improvement-backlog.md freely.
- Apply small reviewer-flagged fixes directly.

================================================================
DO NOT (ask first if tempted)
================================================================
- Force-push, rewrite history, reset --hard, delete branches.
- Open PRs (commit + push; user reviews locally).
- Touch Stripe live config, production env, or `~/www/spare.srv/deployment/`.
- Introduce dark mode / sendvery-dark theme.
- Re-introduce removed features (TASK-136/139/140/141/158 removals).
- Bypass `ClockInterface` with `new \DateTimeImmutable()`.
- Reintroduce TASK-XXX test-name prefixes.
- Use 3rd-party CAPTCHA on any form.
- **Auto-publish DNS records to sendvery.com** — Phase 2 of TASK-168
  (DNS automation) is OUT OF SCOPE for round 11. Round 11 ships
  detection + guidance only.
- **Hardcode `sendvery.com`** in the authorization-record logic —
  self-hosters override the report address domain via
  `SENDVERY_REPORT_ADDRESS` env. Use `ReportAddressProvider`.

================================================================
STOP CONDITIONS
================================================================
Same as rounds 3-10:
- Backlog drained + Product sweep returns no new proposals.
- A task blocked 3 times.
- Quality gates fail unfixably.
- DO-NOT list triggered.
- Context pressure (compaction losing information).

When you stop, append a RUN SUMMARY to `docs/cx-improvement-backlog.md`.

================================================================
KICKOFF
================================================================
1. Read `docs/cx-improvement-backlog.md` for the latest state.
2. File TASK-166 through TASK-168 during PLAN PHASE.
3. **Ship TASK-166 FIRST** (DKIM selector UX — architect-first).
4. **Ship TASK-167 SECOND** (RUA extend UX — straight to Build).
5. **Ship TASK-168 THIRD** (authorization record — architect-first).
6. Run self-review after every 3 ships.
7. Final Product-agent sweep as stop-condition check.
8. Write the RUN SUMMARY.

================================================================
LESSONS FROM ROUNDS 4-10 — APPLY HERE
================================================================
- **Editor-revert race** (round 4): prefer `Write` over `Edit`.
- **Parallel agents**: 3 concurrent is the sweet spot.
- **Self-review every 3 ships** — even clean passes are signal.
- **Don't over-architect small tasks** (TASK-167 is implementable
  from the spec — skip Architect, go straight to Build).
- **Commit per task or per coherent bundle.**
- **Reviewer agents net real findings >50% of the time.**
- **Push continuously, not in a batch.**
- **Tests describe business behaviour, not ticket numbers.**
- **Marketing copy ≠ tech stack** — dashboard copy should describe
  what's happening in user terms ("We found your DKIM key at
  selector `google`") not implementation terms ("DkimChecker
  brute-force resolved selector1 via MX→provider mapping").
- **Hero leads with user value** — dashboard section headers
  should describe the benefit, not the technical mechanism.
- **Trust + transparency conventions** — the DKIM selector display
  and RUA extend path are about building confidence: "we see your
  setup, we understand your existing config, we'll coexist."
