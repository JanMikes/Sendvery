# RUN PROMPT — Managed DMARC (CNAME) + Auto-Ramp

> This file IS the prompt. Run it from a cleared/fresh Claude Code session at the repo
> root with: **"Read docs/15-managed-dmarc-run.md in this repo and execute it exactly."**
> (Optionally type `/effort ultracode` first so the review/verify phases fan out.)

---

Implement the "Managed DMARC (CNAME) + Auto-Ramp" feature for this repo (Sendvery),
to a production-ready, state-of-the-art standard. Work autonomously.

START BY READING, IN FULL:
  1. docs/15-managed-dmarc-execution-prompt.md  ← your operating manual: the loop,
     gates, verification, review, and definition-of-done. FOLLOW IT EXACTLY.
  2. docs/15-managed-dmarc-plan.md              ← the spec: locked decisions, UX (§2),
     architecture (§3), test plan (§4), and the ordered build sequence TASK-174→193 (§5).
  3. CLAUDE.md                                  ← coding standards you must obey.

Then execute docs/15-managed-dmarc-plan.md §5 task-by-task, in order. For EVERY task:
implement per §2/§3 (reuse the named existing classes — don't reinvent), write the tests
named in §4, and run all three gates until green before moving on:
  docker compose exec app vendor/bin/phpunit       (must stay at 100% coverage)
  docker compose exec app vendor/bin/phpstan
  docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff
Commit each task as: feat(managed-dmarc): TASK-XXX <summary> (+ the repo's Co-Authored-By trailer).

NON-NEGOTIABLE (full detail in the docs):
  • Never break a customer's live DMARC: publish hosted TXT before the CNAME; idempotent
    GET→PATCH→POST single-record upsert with low ttl; rollback only ever loosens.
  • Never delete user data: downgrade FREEZES (never loosens); dangling-safe teardown.
  • Tenancy: user commands load via findForTeams() (404 on miss); get() only in crons.
  • No real external calls in tests; never hardcode "sendvery.com" (tests use sendvery.test).
  • Paid-only gate; auto-drive (auto-ramp) is the PREMIUM hero — Premium badge + named in the
    Free-plan upgrade nudge. Semantic daisyUI tokens only; never assert raw Tailwind classes.

WHEN THE FEATURE IS BUILT:
  • Add the two cron lines to ~/www/spare.srv/deployment/crontab (separate git repo) and
    commit + git push it — this push is authorized. Also update CLAUDE.md's Crons list.
  • Verify in the RUNNING app: run `bin/console sendvery:demo:seed`, then drive the UI with
    the browser tools and screenshot every ManagedDmarcCard state, the onboarding chooser,
    the premium auto-drive control, and the Free-plan nudge. Polish the UX/UI until it looks
    intentional, then re-screenshot.
  • Spin parallel code-reviewer agents (correctness, security/tenancy, DMARC/DNS protocol,
    test rigor, UX/copy), adversarially verify their findings, fix the real ones, then run
    /code-review high and /security-review and resolve.
  • Re-run all three gates clean at 100% coverage. Push branch feat/managed-dmarc-cname and
    open a DRAFT PR to main (scope, DEC-058 decisions, screenshots, the Sentry-monitor ops
    step). DO NOT MERGE.

STOP AND ASK ME only if: a product decision isn't covered by the plan, the live code
materially contradicts the plan, or you're blocked after 2–3 genuine attempts. Don't weaken
tests or lower the coverage gate to go green — fix the code. Confirm before any irreversible
or outward-facing action beyond the authorized spare.srv push and the draft PR.
