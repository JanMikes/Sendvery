# 19 — Autonomous completion prompt (W3–W7)

Paste the block below into a fresh Claude Code session at `~/www/dmarc`. It assumes W1, W2 and
the policy-badge fix are shipped and deployed (commits `e33580d`, `27d47ae`, `b63c123`,
`18a9618`; DEC-061 and DEC-062 recorded).

---

You are the **orchestrator** for the remaining half of `docs/17-product-hardening-plan.md`.
Read that file and `CLAUDE.md` first. W1, W2 and the DMARC policy-badge fix are already shipped
and deployed — do not redo them. What remains is **W4, W3, W5, W6, W7**.

Work through subagents: you plan, delegate, review and integrate; subagents implement. Do not
implement whole workstreams yourself — your job is that each one arrives correct, tested and
honestly reported.

## Execution model

Run in this order. W4 first because it is the structural gap that made the other bugs
invisible, and because W3's sparkline fix wants a browser assertion.

| Wave | Workstream | Owns (disjoint — safe to parallelise within a wave) |
|---|---|---|
| 1 | **W4** browser smoke tests | new `tests/Browser/`, `package.json`, `.github/workflows/ci.yml`, a test-only login route |
| 2 | **W3** unknown-is-not-failure sweep · **W6** email correctness | W3: `GetDomainPassRateTrend`, `GetDomainReadinessSignals`, `GetDomainOverview`, `DomainPassRateSparkline` · W6: `SendWeeklyDigestHandler`, `SendAllWeeklyDigestsCommand`, digest templates |
| 3 | **W5** coverage ratchet · **W7** leftovers | W5: `.github/workflows/ci.yml`, `bin/coverage-audit.php`, `CLAUDE.md` · W7: scattered — split by file, take last |

**W4 and W5 both own `ci.yml` — never run them in the same wave.** W1/W3 both touch health
surfaces; W1 is already done, so W3 is unblocked.

For each workstream: spawn an **implementer** subagent, then an **independent reviewer**
subagent that did not write the code. The reviewer gets the diff and the workstream's
acceptance criteria and is asked to *refute* — to find where the implementer's claims are
wrong, where a test passes vacuously, and what the change breaks elsewhere. Feed real findings
back to the implementer. Only then integrate.

## Non-negotiable rules

1. **Every command runs in Docker:** `docker compose exec app <command>`.
2. **All four gates green before anything is "done":** `vendor/bin/phpunit`,
   `vendor/bin/phpstan`, `vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes`,
   `bin/console lint:twig templates/`.
3. **Every fix needs a test you confirmed failing beforehand, and you must say so explicitly**
   with the actual failure output. If you could not make it fail first, say "I couldn't make
   this fail first, here's why" — that is worth more than an unverifiable claim.
4. **Never weaken an architecture guard** (`tests/Unit/Architecture/`,
   `tests/Integration/Architecture/`) to make a change pass. If a guard is wrong, stop and
   argue it with the human. Updating a test that *encoded a defect* is different and is
   expected — say which you are doing and why.
5. **User data is never deleted.** Freeze/quarantine instead; deletion only via the
   contractual retention purge.
6. **Unknown state never renders as failure**, and a desired outcome never renders as a
   warning. This is the spine of the whole document.
7. **Do not commit or push without asking.** Pushing `main` deploys to production.

## Traps that already cost time — check for these explicitly

These are not hypothetical; every one was hit during W1/W2.

- **Fixing the write path and forgetting the read path.** Making a column nullable is half a
  fix. `(int) $row['x']` in a `Results/` DTO or a state provider turns NULL into `0`, and on a
  0-100 scale `0` is the *most alarming* value. Three separate surfaces did this to the same
  column. After any nullability change, grep every consumer.
- **Tests that pass vacuously.** `assertLessThanOrEqual(10, count($x))` passes when `count($x)`
  is 0 — a cap test that silently becomes a test that the feature is off. Assert exact counts.
  A guard that scans files must assert its own scan is non-empty.
- **Guards that never fire.** After writing any source-scanning guard, *reintroduce the defect*
  and watch it fail with file, line and offending snippet. One guard here passed happily
  because `ProjectSource::files()` returns `path => contents`, not paths.
- **Raw `page.mouse.click(x, y)` in browser tests.** It does not scroll; below-the-fold
  elements get clicked at off-screen coordinates and report a false failure. Use Playwright
  locators, which auto-scroll.
- **Magic-link login throttles at 5/hour** (`RequestMagicLinkHandler::MAX_REQUESTS_PER_HOUR`)
  and fails **silently** — no email, no message. A browser suite cannot use the real login
  flow. Add the env-guarded test-only login route (W4 already calls for it).
- **Hardcoded "not checked" copy.** Once a feature starts producing data, literal
  not-yet-measured strings become the opposite lie. Honesty has to move in both directions.
- **Two surfaces disagreeing about the same value.** If a stat and the list behind it can
  differ, that is the bug — not a rounding detail. W3's `GetDomainOverview` filter is exactly
  this.

## Per-workstream notes

**W4** — argue Playwright vs Panther and record the choice. Playwright browsers are already
cached on the host; `chromium.launch({ channel: 'chrome' })` works if the pinned build is
missing. Keep the suite small and fast: it is a smoke net, not a second suite. Assert **zero
console errors** per page — that alone catches a class of Stimulus/Turbo breakage. Add the
axe-core baseline while the infrastructure is there; it was deferred for lack of exactly this.
Wire it as a separate CI job.

**W3** — the sparkline `COALESCE(..., 0)` is worse in a browser than on paper: on demo data it
draws two solid months of flat zero before the data starts, reading as total authentication
collapse. Prefer rendering a gap. Then re-run the sweep yourself using CLAUDE.md's mechanical
tells and fix what you find.

**W5** — a hard 100% gate fails on day one (~148 files below). Build the ratchet: commit a
baseline, fail on any *new* uncovered line or any file regressing, burn it down over time. Then
correct `CLAUDE.md`, which currently claims CI enforces `--coverage-min=100` when
`.github/workflows/ci.yml` gates on nothing. Either enforce the rule or stop asserting it.

**W6** — the digest is the highest-stakes surface in the product and had the least
verification. Either assert HTML/plain-text parity in a test or generate the text part from one
source. Add a preview that writes rendered HTML to a file so email changes are reviewable.

**W7** — take last, split by file: IPv6 bracketing in `SocketSmtpProbe`; `SetTeamPlanCommand`
routed through `UpgradeTeamPlan`; BYO-mailbox envelope rows; the `BackfillSenderIdentitiesCommand`
run decision; the dead `dns-verify-poll` spinner target.

## Verification, deploy and review

- Seed with `docker compose exec app bin/console sendvery:demo:seed`, then **actually click the
  changed surfaces in a browser**. The single most valuable finding of the previous session
  (the inverted policy badge) came from looking at a page, not from a test.
- Run `/code-review` on the working diff before proposing a push.
- When the human approves a push: commit in coherent chunks with messages that explain *why*,
  push, then **verify the rollout on the box** —
  `ssh root@lily.srv.thedevs.cz 'journalctl -u lily-deploy-runner.service --since "-15min" --no-pager'`
  and confirm the container is healthy on the new image. The Release workflow uses
  `await_deploy: false`, so green proves only that the deploy was *triggered*.
- No new cron rows are expected. If one becomes necessary: deploy the image first, verify the
  command exists on the box, then add the row to `~/www/lily.srv/apps/sendvery/cron.d/sendvery`
  and log it in that repo's `docs/journal.md`. Never touch `apps/kvintana/` or
  `infra/*/deploy.sh` — unrelated in-flight operator work.

## Reporting

Check in with the human between waves, not at the end. Each report states: what you did, what
you deliberately did **not** do and why, and what you are unsure about. If a subagent reports a
result you have not verified, say that it is unverified. A clean "I did not do X because Y"
beats a silent omission, and a retracted claim beats a claim the human cannot check.

Record any product decisions in `docs/07-decisions-log.md` and update `CLAUDE.md` wherever
behaviour or conventions change.
