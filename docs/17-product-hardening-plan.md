# 17 — Product hardening plan

**Status:** ready to execute · **Written:** 2026-07-27 · **Audience:** a fresh Claude Code instance

Everything here is a *known* gap. It comes from a user-feedback pass, an architecture-guard
exercise, and an audit for the pattern "a scheduled job is load-bearing for the truthfulness of a
surface". The easy half is already shipped and deployed; what remains is listed below, with the
evidence that it is real.

Read `CLAUDE.md` first — especially **"Unknown Is Not Failure"**, which is the principle behind
half of this document.

---

## 0. How to work

**Every command runs in Docker:** `docker compose exec app <command>`.

**Gates that must be green before you call anything done:**

```bash
docker compose exec app vendor/bin/phpunit
docker compose exec app vendor/bin/phpstan
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
docker compose exec app bin/console lint:twig templates/
```

`--allow-risky=yes` is required or cs-fixer aborts on `declare_strict_types`.

**Architecture guards exist and will catch you.** `tests/Unit/Architecture/` and
`tests/Integration/Architecture/` enforce: no absolutely-positioned anchor inside a table row, no
`position: relative` on a `<tr>`, every `cursor-pointer` row delegating to a real anchor via the
`row-link` Stimulus controller, the retired sender vocabulary staying out of user-facing copy, no
Tailwind `dark:` variant, no `{% block %}` nested in a `<twig:>` tag, no daisyUI v3/v4 theme
variables, and every Stimulus controller/method a template references existing. Read them before
editing templates. **Do not weaken a guard to make your change pass** — if a guard is wrong, say so
and argue it; that is a design conversation, not a test edit.

**Rules that are not negotiable:**

- **User data is never deleted.** Plan limits and downgrades cause freeze/quarantine. Deletion only
  via the contractual retention purge. A bug in exactly this area was fixed on 2026-07-27 — do not
  reintroduce it.
- **Unknown is not failure.** Absent/not-yet-measured state never renders as an error; a desired
  outcome never renders as a warning. The grep-able tells are listed in CLAUDE.md.
- Test names and assertion messages describe **business behaviour**, never ticket numbers.
- Never assert Tailwind utility classes for styling. Asserting the *absence* of a structurally
  broken pattern is different and is allowed (the guards do it).
- Migrations: additive only, hand-authored rounded timestamp, docblock explaining why it is safe,
  real reversing `down()`.

**Seed data:** `docker compose exec app bin/console sendvery:demo:seed`.

**Deployment reality:** a push to `main` builds an image and deploys to production automatically.
The Release workflow currently uses `await_deploy: false`, so a green pipeline proves only that the
deploy was *triggered* — confirm a rollout with
`ssh root@lily.srv.thedevs.cz 'journalctl -u lily-deploy-runner.service --since "-15min" --no-pager'`.
App cron is versioned at `~/www/lily.srv/apps/sendvery/cron.d/sendvery` and goes live on a push to
that repo's `main`; **deploy the app image before pushing a cron row that calls a new command.**

---

## 1. Decisions the human must make BEFORE you build (blocking)

Ask these as a batch, up front. Do not guess — each one changes what gets built, and two of them
change numbers customers have already seen.

**D1 — Blacklist monitoring: ship it, or stop claiming it?**
This is the largest open item. `src/Message/CheckBlacklist.php` exists, `CheckBlacklistHandler` and
`AlertOnBlacklisting` exist — and **nothing dispatches `CheckBlacklist` anywhere in the codebase**
(verified: the only occurrence is the class's own declaration). So `blacklist_check_result` is
permanently empty. Meanwhile:
- `src/Services/Dns/DomainHealthScorer.php:19` — `$blScore = $blacklistScore ?? 100`, so a fabricated
  100 carries **20% of every health grade**;
- it is rendered as a measured verdict on `templates/public/domain_health.html.twig` (an
  **unauthenticated, shareable** page), `templates/dashboard/domain_health.html.twig`,
  `templates/pdf/domain_report.html.twig`, and the REST API (`src/ApiResource/HealthScoreResource.php`);
- it is paywalled in `PlanLimits`, listed in the Personal plan, described in `PricingFaq` as
  continuously checking and raising alerts, and marked done in `docs/03-features-roadmap.md`.

Options: **(a) ship it** — add a dispatcher + cron, real DNSBL lookups, rate limiting, wire the
existing alert path; **(b) retire the claim** — drop it from scoring and from every surface and
pricing claim. Either is defensible; shipping a checker is a few days, retiring is a day. What is
*not* defensible is the current state.

**D2 — If D1 changes the score, every existing domain's grade changes.** Removing a constant 100
that carries 20% weight means renormalising over the four real protocols, which silently re-grades
every domain and every stored `domain_health_snapshot`. Confirm this is acceptable, and decide
whether historical snapshots are left alone (recommended — they are a time series) or backfilled.

**D3 — Confirm the auto-ramp CNAME max-age of 26 hours**
(`DmarcRampReadinessEvaluator::CNAME_VERIFICATION_MAX_AGE_HOURS`). Rationale: the DNS sweep runs
03:00 and the ramp 05:30, so the value is normally ~2.5h old; 26h = one sweep cycle + 2h grace, so an
on-time sweep always passes and a skipped one never does. A live CNAME re-check now also runs before
any due advance, so the age gate is a backstop rather than the only rail.

**D4 — Ingestion-stopped alerting (see W2): wanted, and at what threshold?**
Proposal: alert when a domain that has been receiving reports goes quiet for longer than its own
observed cadence (most reporters send daily; 72h is a safe default). Needs a product yes and a
threshold.

---

## 2. Workstreams

Ordered by value. W1 and W2 are the ones that change the product; the rest is hardening.

### W1 — Blacklist: make it real or make it honest *(blocked on D1/D2)*

**Path (a) — ship it.** Build `sendvery:blacklist:check-all` as an idempotent console command
following the shape of `CheckAllDomainsDnsCommand`; dispatch `CheckBlacklist` per monitored IP or
domain; respect the existing plan gating; let the existing `AlertOnBlacklisting` handler fire. Use a
small, reputable DNSBL set, cache aggressively, and be careful about query volume — public DNSBLs
rate-limit and will null-route a noisy resolver. Then add the cron row to
`~/www/lily.srv/apps/sendvery/cron.d/sendvery` **after** the image ships.
Honesty requirement: until a domain has actually been checked, its blacklist state is `null` and
must render as "not checked", never as a green pass.

**Path (b) — retire it.** Remove blacklist from `DomainHealthScorer` and renormalise the remaining
weights; remove or gate the claim in `PricingFaq`, the plan feature list, and the roadmap; make the
blacklist tab an explicit "not available" rather than an empty pass; remove it from the public
health page, the PDF and the API resource.

**Acceptance:** no surface asserts a blacklist verdict that was never measured; the health grade is
composed only of things actually checked; pricing copy matches what the product does.

### W2 — Ingestion health: notice when reports stop arriving *(blocked on D4)*

**The gap.** The product's core promise is monitoring, and it cannot currently notice its own
monitoring stopping. `src/Services/DomainAttentionResolver.php:320` warns *"DMARC is published but no
reports have arrived after 48 hours"* purely on `firstReportAt === null` — so it fires at users whose
DNS is correct when the poll cron is merely behind, and once `firstReportAt` is stamped the branch is
**unreachable forever**. A domain that reported daily for a year and goes silent produces **no alert
at all**. There is no `AlertType` for ingestion stopping and nothing records when the poll last
succeeded, which is what makes the whole class invisible from inside the product.

**Build:**
1. A durable "last successful ingest" signal — per domain (last report processed) and per mailbox /
   the central inbox (last successful poll). The per-poll signal is the one that distinguishes "your
   DNS is wrong" from "our pipeline is stuck", which is exactly the distinction the current 48h
   message gets wrong.
2. A new `AlertType` for reports having stopped, with a threshold derived from the domain's observed
   cadence where possible and a sane default otherwise.
3. A cron command to evaluate it (or fold into an existing daily job).
4. Fix the 48h message so it only accuses the user's DNS when our own pipeline is demonstrably
   healthy.
5. Surface pipeline health somewhere an operator sees it — `/app/status` or the admin area.

**Acceptance:** a domain whose reports stop is alerted; a user is never told to fix correct DNS
because our poller is behind; an operator can see when ingestion last succeeded.

### W3 — Finish the "unknown is not failure" sweep

Known remaining instances:
- `src/Query/GetDomainPassRateTrend.php:33-34,169-170` — `COALESCE(..., 0)` makes an empty bucket
  plot as a **plunge to 0%** in the sparkline. Decide: render a gap (correct) or keep zero
  (documented as a deliberate tradeoff in the file). If a gap, `DomainPassRateSparkline` must handle
  discontinuity.
- `src/Query/GetDomainReadinessSignals.php:39-49` — `COALESCE(pass_rate, 0)`, so
  `RampReadinessResult::$passRate` is non-nullable and cannot distinguish "no reports" from
  "everything failed". Safe direction for the ramp gate (0.0 blocks advancement), so fix for honesty
  in the UI without weakening the gate.
- `src/Query/GetDomainOverview.php` — the `?status=healthy|attention` SQL filter is looser than
  `DomainHealthClassifier`. Consequence: a domain with a broken record shows amber on its card and
  counts in the "Need attention" stat, but clicking the chip does not list it. Push per-protocol
  predicates into the filter SQL so the count and the list agree.

Then re-run the sweep yourself using CLAUDE.md's mechanical tells (`COALESCE(<rate>, 0)`,
non-nullable numeric DTO fields for unmeasured things, state derived from cron-only tables, booleans
standing in for tri-states) and fix what you find.

**Acceptance:** a stat and the list behind it never disagree; no rate/percentage renders a
fabricated zero.

### W4 — Browser-level tests (the structural gap)

**Why this matters more than it looks.** The single defect that most annoyed the user — every report
row opening the same report — was invisible to 3400+ PHPUnit tests and *inherently* invisible to
them: it was CSS hit-testing. The repo has no browser tooling at all (`docs/cx-improvement-backlog.md`
records "axe-core baseline: SKIP — no Panther/Cypress infrastructure"). A second instance of the same
bug was still live on `/app` weeks later and was only caught by a source-scanning guard.

**Build a small Playwright suite** (or Symfony Panther if you prefer staying in PHP — argue the
choice). Keep it deliberately small and fast; this is a smoke net, not a second test suite:
- log in (magic link — read `tests/` for how auth is faked, or add a test-only login route guarded by
  env);
- `/app/reports`: click row 3, assert the URL is row 3's report;
- `/app`: same for the Recent Reports table;
- a domain detail page: the guided DNS setup renders, the copy button works;
- `/app/alerts`: select-all then bulk action;
- the reports filter: TomSelect enhances the native select and the form still submits `domain[]`;
- assert **zero console errors** on each page visited — this alone would catch a whole class of
  Stimulus/Turbo breakage.

Wire it into CI as a separate job. Add an axe-core accessibility baseline while the infrastructure is
there — it was explicitly deferred for lack of exactly this.

**Acceptance:** CI fails if a row link regresses or a page throws in the console.

### W5 — Make the coverage claim true

`CLAUDE.md` says 100% coverage is mandatory and that CI enforces `--coverage-min=100`.
**`.github/workflows/ci.yml:54` only emits clover and gates on nothing**, and ~148 `src/` files are
below 100% (largely infrastructure adapters: IMAP, SMTP probe, Stripe, API Platform state providers,
OG image, Twig checker components). `bin/coverage-audit.php` is committed and prints every file below
100% with the uncovered lines.

A hard 100% gate would fail on day one. Recommended: a **ratchet** — commit a baseline of currently
uncovered files, fail CI on any *new* uncovered line or any file regressing, and burn the baseline
down over time. Then correct CLAUDE.md to describe what is actually enforced. Either enforce the rule
or stop asserting it; a doc that lies about CI teaches everyone to discount the doc.

### W6 — Email correctness and preview

- **HTML/plain-text drift.** `SendWeeklyDigestHandler::renderPlainText()` is a second renderer that
  will keep falling behind the Twig template — the sender-review section shipped HTML-only and had to
  be hand-added. Either assert parity in a test (every section flag present in HTML has a plain-text
  counterpart) or generate the text part from a single source.
- **No preview path.** Every digest defect this quarter shipped because nobody looked at a rendered
  one. `--dry-run`/`--team=` exist; add a preview that writes the rendered HTML to a file so email
  changes are reviewable in a browser, and consider golden-file tests for the digest sections.
- The digest is the highest-stakes surface in the product: it reaches customers unprompted and had
  the least verification.

### W7 — Smaller leftovers

- `src/Services/Dns/SocketSmtpProbe.php:37,57` — `fsockopen($ip, 25, ...)` does not bracket IPv6
  literals, so an IPv6-only mail host always reads "unreachable". Reachability deliberately does not
  gate MX validity, so this is cosmetic — but the copy blames our own egress, which is now
  misleading for a specific class of host.
- `src/Command/SetTeamPlanCommand.php` writes `team.plan` directly instead of dispatching
  `UpgradeTeamPlan`, so a staff-granted upgrade does not release plan-overage-parked reports until
  the nightly `usage:reset`. Route it through the command bus.
- **BYO mailboxes create no envelope rows.** `PollMailboxHandler` does not create
  `ReportSource::ByoMailbox` envelopes, so mailbox detail pages show permanently-zero envelope counts
  and BYO overage never appears on the billing "N reports waiting" card. Needs raw EML threaded
  through `MailClient`/`MailMessage`.
- `src/Command/BackfillSenderIdentitiesCommand.php` ships but nothing runs it — historical reports
  keep showing raw IPs instead of resolved hostnames until someone does. Decide one-off vs cron, then
  actually run it in production.
- Dead Stimulus attributes: `data-dns-verify-poll-target="spinner"` appears in 3 templates but
  `dns_verify_poll_controller.js` declares only `['status']`. Harmless, but it is a lie about the
  contract; clean up.

### W8 — Product polish (propose before building)

Do **not** build these unprompted; investigate, then propose with reasoning.

- **Verify the guided DNS setup end-to-end in a real browser.** It was built and deployed but never
  actually clicked through in this workflow. Same for `/app/reports` row navigation. Do this first —
  it is cheap and may generate its own list.
- **Onboarding.** A new user's first ten minutes decide whether the product is understood. Walk it as
  a new user and report what is confusing, with the "unknown is not failure" lens.
- **Performance.** The dashboard issues a lot of queries; check for N+1s and slow paths under demo
  data before adding more surfaces.
- **Accessibility** beyond the axe baseline in W4.

---

## 3. Sequencing and parallelism

Safe to run in parallel — disjoint file ownership:

| Stream | Owns |
|---|---|
| W1 | `src/Message/CheckBlacklist.php`, `src/MessageHandler/CheckBlacklistHandler.php`, `AlertOnBlacklisting`, `src/Services/Dns/DomainHealthScorer.php`, `HealthSnapshotComposer`, blacklist templates, `PricingFaq`, `PlanLimits` |
| W2 | `src/Services/DomainAttentionResolver.php`, `src/Value/AlertType.php`, new command + query + entity/migration, `PollMailboxHandler`, status page |
| W3 | `src/Query/GetDomainPassRateTrend.php`, `GetDomainReadinessSignals.php`, `GetDomainOverview.php`, `DomainPassRateSparkline` |
| W4 | new `tests/Browser/` (or `tests/E2E/`), `package.json`, `.github/workflows/ci.yml` |
| W5 | `.github/workflows/ci.yml`, `bin/coverage-audit.php`, `CLAUDE.md` |
| W6 | `SendWeeklyDigestHandler`, `SendAllWeeklyDigestsCommand`, digest templates |
| W7 | scattered — take it last, or split it across the others by file |

W4 and W5 both touch `.github/workflows/ci.yml` — serialise those two or have one own the file.
W1 and W3 both touch health/score surfaces — W1 first.

## 4. Definition of done

- All four gates green, full suite, no skipped tests.
- Every fix has a test that was **confirmed failing beforehand** — state this explicitly per fix.
- No architecture guard weakened.
- Product decisions D1–D4 answered by the human and the answers recorded in `docs/07-decisions-log.md`.
- `CLAUDE.md` updated where behaviour or conventions changed (cron list, coverage claim).
- Anything deliberately not done is reported with the reason — a clean "I did not do X because Y" is
  worth more than a silent omission.

## 5. Out of scope

- Do not redesign surfaces that were just rebuilt (guided DNS setup, dashboard focus card, sender
  vocabulary) without a specific user complaint.
- Do not push the lily infra repo's `apps/kvintana/` or `infra/*/deploy.sh` — that is unrelated
  in-flight work by the operator.
- Do not add a dark theme (`CLAUDE.md` forbids it; it was removed deliberately).
