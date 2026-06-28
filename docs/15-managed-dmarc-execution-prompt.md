# Managed DMARC (CNAME) + Auto-Ramp — Autonomous Execution Prompt

> Paste the block below into a **fresh Claude Code session** at the repo root
> (`/Users/janmikes/www/dmarc`). It drives the full build of
> [`docs/15-managed-dmarc-plan.md`](./15-managed-dmarc-plan.md) end-to-end:
> implement → test → verify in the running app → multi-agent review → production-ready branch.
> Tip: prefix with `/effort ultracode` so the review/verify phases fan out across agents.

---

## MISSION

Implement **Managed DMARC (CNAME) + Auto-Ramp** exactly as specified in `docs/15-managed-dmarc-plan.md`, to a **production-ready, state-of-the-art** standard: correct, fully tested (100% coverage), well-architected to this repo's conventions, and with polished UX/UI. Work autonomously through the whole task sequence; only stop to ask me when you hit a genuine product decision the plan doesn't answer, or you're blocked after 2–3 real attempts.

## READ FIRST (before writing any code)

1. `docs/15-managed-dmarc-plan.md` — **the spec.** The locked decisions, architecture, UX, test plan, and the ordered build sequence (TASK-174 → TASK-193) are all there. Treat its §5 build sequence as your task list and its §4 as the coverage contract.
2. `CLAUDE.md` — coding standards (PHP 8.5 strict, CQRS, `readonly final`, **no `flush()` in handlers**, `IdentityProvider`, `ClockInterface`, DBAL queries→Results DTOs, domain events, daisyUI v5 + Twig component rules, crons via system cron, run everything in `docker compose exec app`).
3. `docs/07-decisions-log.md` — add **DEC-058** (stub is in plan §7) as part of TASK-174.
4. `docs/05-monetization.md` and `docs/13-pricing-implementation-plan.md` — the entitlement/plan plumbing you extend (`PlanLimits`/`PlanEnforcement`, `SubscriptionPlan::Free !== $plan`, `Unlimited` staff-grant).

If anything in the plan conflicts with the live code you find, **trust the live code, flag the drift to me, and adapt** — the plan was written from an inspection snapshot.

## SETUP

- Branch off `main`: `git checkout main && git pull && git checkout -b feat/managed-dmarc-cname`.
- All PHP tooling runs in Docker: `docker compose up -d` first, then `docker compose exec app <cmd>`.

## EXECUTION LOOP — one TASK at a time, in order (TASK-174 → TASK-193)

For **every** task in plan §5:

1. **Implement** precisely what the task's *Scope* and *Files* describe, following the architecture in §3 and the UX in §2. Reuse the named existing classes/patterns — do not reinvent (`CloudflareDnsClient` helpers, `DmarcRuaInstruction`/serializer, `DmarcPolicyAdvisor`, `PublishAuthorizationRecordWhenDomainAdded`, `SetDomainDkimSelectorController`, `FakeDnsRecordPublisher`, `PersonaBuilder`, `MockClock`).
2. **Write the tests named in §4** for that task (tests are the business spec — DEC-009). Hit every new branch: paid vs Free vs `Unlimited`, self-hosted-unconfigured skip, tenancy rejection, idempotent re-runs, regression/rollback, the 48h schedule (advance `MockClock`), the Cloudflare single-record upsert (non-Fake **client contract test** — never a real Cloudflare call).
3. **Run the three quality gates after the task — all must be green before moving on:**
   ```
   docker compose exec app vendor/bin/phpunit
   docker compose exec app vendor/bin/phpstan
   docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff
   ```
   Coverage must stay at **100%** (`--coverage-min=100`). Fix until clean — do not accumulate debt across tasks.
4. **Commit** with a focused message: `feat(managed-dmarc): TASK-XXX <summary>` ending with the repo's `Co-Authored-By` trailer.

### Non-negotiable invariants (from the plan — re-check on every task)

- **Never break a customer's live DMARC**: publish the hosted TXT *before* the customer points the CNAME; idempotent content-compare upsert (GET→PATCH→POST, single-record invariant, explicit low `ttl=1`); rollback loosens instantly.
- **Never delete user data**: downgrade *freezes* auto-ramp (never loosens); teardown is dangling-safe (delete the hosted TXT only once the CNAME no longer points at us); only ever delete Sendvery-owned records.
- **Tenancy**: user-dispatched handlers load via `findForTeams(...)` (404 on miss) — `get()` only for cron commands; every new query is team-scoped.
- **No real external calls in tests**; mock `HttpClientInterface`/DNS. Never hardcode `sendvery.com` — derive `reportDomain` (tests use `sendvery.test`).
- **Auto-drive is the premium hero**: paid-only gate via `managed_dmarc`; the auto-ramp control carries a `Premium` badge and the Free-plan nudge names auto-drive. Use **semantic daisyUI tokens only** (no `dark:`, no hardcoded colours); never assert raw Tailwind classes in tests.

## DEPLOYMENT CRONTAB (separate repo — pre-authorized to push)

At TASK-189 and TASK-190, add the two cron lines to `~/www/spare.srv/deployment/crontab` under the `## Sendvery` block, matching the existing entries' shape (`sentry-cli monitors run --monitor-slug <slug> -- docker compose run --rm worker bin/console …`):
- `30 5 * * *` → `sendvery:dmarc:auto-ramp` (slug `sendvery-dmarc-auto-ramp`)
- `45 5 * * *` → `sendvery:dmarc:sync-hosted-records` (slug `sendvery-dmarc-sync-hosted-records`)

`~/www/spare.srv` is a **different git repository**; the deploy host pulls it. **Commit and `git push` it** (this push is authorized). Also append both to the `CLAUDE.md` "Crons" list in the app repo.

## VERIFY IN THE RUNNING APP (not just tests)

After the feature is built:

1. `docker compose exec app bin/console sendvery:demo:seed` (TASK-192 makes a demo domain managed + verified + mid-ramp).
2. Drive the real UI with the browser tools (`mcp__claude-in-chrome__*`) and capture screenshots / a short GIF of:
   - Onboarding step 3 — the self-TXT | managed segmented chooser + "which is good for you" guidance + the CNAME instruction with copy button + the three-state verify frame.
   - Dashboard domain detail — the `ManagedDmarcCard` in **each** state (not enabled / preparing / CNAME pending / verified+active / error-dangling / frozen), the policy selector, readiness hint, one-click advance, and the **auto-drive** control with its `Premium` treatment.
   - The Free-plan upgrade nudge (log in as a Free persona) and the public-checker soft CTA on a weak result.
3. **UX/UI polish pass**: verify copy matches §2, the premium framing of auto-drive reads well, states are visually distinct, layout holds on mobile width, and it looks intentional — not templated. Fix anything that looks off; re-screenshot.

## MULTI-AGENT REVIEW (spin reviewers — "check everything")

Before declaring done, run an independent review and fix what's real:

1. **Dimension reviewers in parallel** — spawn `feature-dev:code-reviewer` (and/or general) agents over the diff, one per dimension: correctness/logic, security & tenancy, DMARC/DNS protocol correctness (single-record invariant, §7.1 still published, CNAME verification), test rigor/coverage honesty, UX/accessibility/copy. Have each return concrete findings.
2. **Adversarially verify** each finding (a second agent tries to refute it) so you only act on real issues; fix the confirmed ones and re-run the three gates.
3. Run the built-in skills: `/code-review high` (or `/code-review ultra` for the deep cloud pass) and `/security-review`. Address findings.
4. Re-run the full suite at `--coverage-min=100` + phpstan + cs-fixer until spotless.

## DEFINITION OF DONE

- [ ] All TASK-174→193 implemented per `docs/15`, each committed.
- [ ] `phpunit` green at **100% coverage**, `phpstan` clean, `php-cs-fixer --dry-run` clean.
- [ ] `doctrine:schema:validate` green (new columns + audit table).
- [ ] App verified live via demo seed + screenshots of every managed card state, onboarding chooser, auto-drive premium control, Free-plan nudge.
- [ ] Multi-agent review + `/code-review` + `/security-review` findings resolved.
- [ ] Two cron lines added to `~/www/spare.srv/deployment/crontab` **and pushed**; `CLAUDE.md` crons list + `docs/02/03/04/05/15` + DEC-058 updated.
- [ ] Branch `feat/managed-dmarc-cname` pushed; open a **draft PR** to `main` summarizing scope, the DEC-058 decisions, screenshots, and the manual ops step (Sentry monitors for the two new slugs). **Do not merge** — leave for human review.

## GUARDRAILS

- Stop and ask me if: a product decision isn't covered by the plan; the live code contradicts the plan materially; or you're stuck after 2–3 genuine attempts. Don't thrash.
- Don't weaken a test or lower the coverage gate to get green — fix the code.
- Confirm with me before merging or any irreversible/outward-facing action beyond the authorized `spare.srv` crontab push and the draft PR.
