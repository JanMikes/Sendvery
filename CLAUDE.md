# Sendvery — CLAUDE.md

This file contains architecture conventions, coding standards, and project context for vibecoding. Follow these rules strictly when generating code.

## Running Commands

Always run PHP commands (composer, phpunit, bin/console, php-cs-fixer, phpstan, infection) inside the Docker app container using `docker compose exec`:

```bash
docker compose exec app <command>
```

**After every code change**, always run the quality tools to verify:
1. `docker compose exec app vendor/bin/phpunit` — tests
2. `docker compose exec app vendor/bin/phpstan` — static analysis
3. `docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes` — code style

`--allow-risky=yes` is required: the ruleset includes `declare_strict_types`, which
php-cs-fixer classifies as risky, so the command aborts without it.

## Local dev bootstrap

A fresh `docker compose up` shows every dashboard surface empty (0 domains, 0 reports, 0 alerts, 0 snapshots), which makes humans onboarding and autonomous CX review runs misread normal empty states as bugs. To populate the dev database with a fully-realised "Demo Team":

```bash
docker compose exec app bin/console sendvery:demo:seed
```

The seeder lives at `src/Command/SeedDemoDataCommand.php`. It refuses to run in `prod` (the truncate-then-rebuild step is non-negotiable), is idempotent (each run wipes the existing demo team — identified by slug `demo-team` — and rebuilds from scratch, never touching data outside that team), and adopts the first existing `User` so the dashboard binds to the account you already log in with (or creates `demo@sendvery.test` if none exist). Produces: 1 team, 3 monitored domains (A-grade `acme.example`, C-grade `okay.example`, broken-SPF `broken.example`), 30 days of DMARC reports per domain (~90 total), 30 daily `domain_health_snapshot` rows per domain so trend charts render, and 5 representative `alert` rows across the main `AlertType` cases.

## Project

Sendvery is an email health & deliverability micro-SaaS. DMARC report parsing with AI-powered insights. Open source (AGPL-3.0), self-hosted always free.

## Tech Stack

- **PHP 8.5** (strict_types=1 everywhere)
- **Symfony 8.0** (upgrade to 8.1 May 2026, target 8.4 LTS Nov 2027)
- **FrankenPHP** worker mode (built-in Caddy, no separate web server)
- **PostgreSQL 16** (single DB for app data + Messenger queue transport)
- **Doctrine ORM** + **DBAL** (ORM for writes, DBAL for reads)
- **Symfony Messenger** (Doctrine transport) for async commands and domain events
- **Tailwind CSS 4** + **daisyUI 5** + **ApexCharts** for frontend
- **Stimulus + Turbo (Hotwire)** via Symfony UX
- **API Platform** for REST API
- **Stripe** for subscriptions
- **Sentry** for error tracking
- **Docker base image:** `ghcr.io/thedevs-cz/php:8.5` from https://github.com/thedevs-cz/docker

## Core Principles

- **Strongly typed PHP 8.5** — no mixed types unless absolutely necessary
- **Objects over arrays** — never use associative arrays for structured data; use value objects, DTOs, readonly classes
- **Immutability preferred** — `readonly` classes and properties by default
- **Modern PHP** — `readonly public` properties over getters, constructor promotion, named arguments, enums, match expressions, first-class callables, pipe operator
- **Convention over configuration** — follow Symfony defaults
- **Simple, decoupled, readable** — minimal inheritance, prefer composition
- **12-factor app** — config from env vars, stateless processes
- **100% test coverage for code you write or touch** — tests ARE the business specification. CI enforces this as a ratchet over the pre-existing debt, not as a cliff: [Coverage is a ratchet](#coverage-is-a-ratchet)
- **Unknown is not failure** — absent/not-yet-measured state never renders as an error, and a desired outcome never renders as a warning. Worked examples and the grep-able tells: [Unknown Is Not Failure](#unknown-is-not-failure)

## PHP Class Conventions

Classes are `readonly final` by default. `final` is preferred but can be removed when needed (e.g., for test mocking/stubbing). Doctrine entities can't be readonly due to lazy loading, but their properties should be readonly where possible.

```php
// Good
readonly final class AddDomain { ... }
readonly final class DomainOverviewResult { ... }
readonly final class IdentityProvider { ... }

// Entities: final but not readonly (Doctrine constraint)
#[ORM\Entity]
final class MonitoredDomain implements EntityWithEvents { ... }
```

Public properties over getters. Constructor promotion everywhere.

## CQRS Pattern

Inspired by https://github.com/MySpeedPuzzling/myspeedpuzzling.com/

### Commands (`src/Message/`)

Write operations. Immutable `readonly final class`. Named as imperative verb: `AddDomain`, `ConnectMailbox`, `ParseDmarcReport`. Handlers NEVER return anything. If caller needs an ID, provide it via `IdentityProvider::nextIdentity()`.

```php
readonly final class AddDomain
{
    public function __construct(
        public UuidInterface $domainId,  // Caller provides ID upfront
        public string $teamId,
        public string $domainName,
    ) {
    }
}
```

### Command Handlers (`src/MessageHandler/`)

`#[AsMessageHandler]` attribute. `readonly final class` with single `__invoke()`. One handler per command.

**Never call `$entityManager->flush()` in handlers.** Both `command_bus` and `event_bus` have `doctrine_transaction` middleware configured (`config/packages/messenger.php`) — it wraps every handler in a transaction, calls `flush()` on success, and rolls back on failure. Manual flush calls are redundant and break atomicity.

```php
#[AsMessageHandler]
readonly final class AddDomainHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamRepository $teamRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AddDomain $message): void
    {
        $team = $this->teamRepository->get($message->teamId);

        $domain = new MonitoredDomain(
            id: $message->domainId,
            team: $team,
            name: $message->domainName,
            addedAt: $this->clock->now(),
        );

        $this->entityManager->persist($domain);
    }
}
```

### Queries (`src/Query/`)

Read operations. Inject `Doctrine\DBAL\Connection` directly (not EntityManager). Raw SQL. Return result DTOs, never entities.

```php
readonly final class GetDomainOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /** @return array<DomainOverviewResult> */
    public function forTeam(string $teamId): array
    {
        $data = $this->database->executeQuery(
            'SELECT ... FROM monitored_domain WHERE team_id = :teamId',
            ['teamId' => $teamId],
        )->fetchAllAssociative();

        return array_map(DomainOverviewResult::fromDatabaseRow(...), $data);
    }
}
```

### Results (`src/Results/`)

`readonly final class` DTOs for query responses. Static `fromDatabaseRow()` factory with docblock array shape.

```php
readonly final class DomainOverviewResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public int $totalReports,
        public float $passRate,
    ) {
    }

    /** @param array{domain_id: string, domain_name: string, total_reports: int, pass_rate: float} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            totalReports: $row['total_reports'],
            passRate: $row['pass_rate'],
        );
    }
}
```

## Identity Provider

Always use UUID v7 for new entity IDs via `IdentityProvider::nextIdentity()`. Never let the database generate IDs. Never call `Uuid::uuid7()` directly in application code — always go through `IdentityProvider` to enable test mocking.

```php
readonly final class IdentityProvider
{
    public function nextIdentity(): UuidInterface
    {
        return Uuid::uuid7();
    }
}
```

Usage in controllers:

```php
$domainId = $this->identityProvider->nextIdentity();
$this->commandBus->dispatch(new AddDomain($domainId, $teamId, $domainName));
return $this->redirectToRoute('domain_detail', ['id' => $domainId]);
```

## Domain Events

Entities implement `EntityWithEvents` interface with `HasEvents` trait. Events are recorded on entities, then collected and dispatched by `DomainEventsSubscriber` (Doctrine listener) after flush via Symfony Messenger.

```php
interface EntityWithEvents
{
    public function recordThat(object $event): void;
    /** @return array<object> */
    public function popEvents(): array;
}

trait HasEvents
{
    /** @var array<object> */
    private array $events = [];

    public function recordThat(object $event): void
    {
        $this->events[] = $event;
    }

    /** @return array<object> */
    public function popEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }
}
```

Events are `readonly final class` in `src/Events/`. Handlers use `#[AsMessageHandler]`.

```php
// Entity emits event
$this->recordThat(new DomainAdded($this->id, $this->team->id));

// Event handler
#[AsMessageHandler]
readonly final class CheckDnsWhenDomainAdded
{
    public function __invoke(DomainAdded $event): void { ... }
}
```

`DomainEventsSubscriber`: Doctrine listener that collects events from entities on postPersist/postUpdate/postRemove, dispatches all on postFlush. Pattern from https://github.com/MySpeedPuzzling/myspeedpuzzling.com/

## Controllers

Single-action controllers with `__invoke()` only. One controller = one route = one action.

```php
final class AddDomainController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Handle form, dispatch command, redirect
    }
}
```

Controller names describe the action: `AddDomainController`, `ShowDomainDetailController`, `ListDomainsController`.

## Configuration

Symfony 8 PHP configs using `App::config()` syntax. No YAML configs.

```php
// config/packages/doctrine.php
return App::config([
    'doctrine' => [
        'dbal' => [
            'url' => '%env(resolve:DATABASE_URL)%',
        ],
    ],
]);
```

## Directory Structure

```
src/
├── Attribute/              # Custom PHP attributes
├── Controller/             # Single-action controllers (__invoke)
├── Doctrine/               # Custom Doctrine types (no team-scoping filter — see Multi-Tenancy)
├── Entity/                 # Doctrine entities (with HasEvents)
├── Events/                 # Domain events (readonly final class)
├── Exceptions/             # Domain exceptions
├── FormData/               # Form data classes (mutable, for Symfony Forms)
├── Message/                # Commands (readonly final class)
├── MessageHandler/         # Command + Event handlers (#[AsMessageHandler])
├── Query/                  # Read-side queries (DBAL Connection)
├── Repository/             # Doctrine repositories
├── Results/                # Query result DTOs (readonly final class)
├── Services/               # Domain services, infrastructure adapters
├── Value/                  # Value objects, enums
└── Twig/                   # Twig extensions, components
```

## Entities

- Constructor-based initialization (not setters)
- `readonly` for immutable properties (ID, creation date)
- Public properties preferred over getters when no logic is needed
- Implement `EntityWithEvents` for entities that emit domain events
- UUID v7 for IDs (passed as constructor parameter from `IdentityProvider`)

## Value Objects & Enums

```php
enum DmarcPolicy: string
{
    case None = 'none';
    case Quarantine = 'quarantine';
    case Reject = 'reject';
}

readonly final class DnsRecord
{
    public function __construct(
        public string $type,
        public string $value,
        public int $ttl,
    ) {
    }
}
```

## Multi-Tenancy

- Every tenant-scoped entity has `team_id` FK
- **There is NO global Doctrine SQL filter, and nothing scopes your query for you.**
  `config/packages/doctrine.php` explains the choice: a filter covers only ORM queries, silently
  skipping raw DBAL reads — which is most of this read side — and it hides the security check from
  the call site. So **every** query touching tenant data must carry its own predicate, normally
  `team_id IN (:teamIds)` from `DashboardContext::getTeamIds()`.
- **This line used to claim the opposite**, and that is not a harmless doc bug. A cross-tenant defect
  shipped in the BYO-mailbox envelope ledger on 2026-07-28 precisely because an author deduped on a
  sender-supplied `Message-ID` with no tenant qualifier — reasonable if you believe a global filter
  has your back. If you are writing a query and cannot point at its team predicate, it does not have
  one.
- Watch the joins, not just the FROM. The 2026-07-28 fix also had to add predicates to a
  `mailbox_connection` join in `GetDomainIngestionMatrix` and a `dmarc_report` join in
  `GetMailboxDetail`: both queries scoped their primary table correctly and then joined out of the
  tenant. A row is owned by the team of the table it hangs off — a `dmarc_report` belongs to its
  *domain's* team, which is not necessarily the team of the mailbox the mail arrived in.
- Unique indexes are a tenancy surface too. `(source, message_id)` was global, so a
  sender-controlled header became a key shared across every tenant. Where a natural key comes from
  outside, scope the index — and beware that PostgreSQL treats NULLs as distinct, so simply appending
  a nullable tenant column silently stops constraining the NULL rows (see `Version20260728120000`,
  which uses two partial indexes for exactly this reason).
- API Platform extension for team-scoped queries
- Authorization via Symfony Security Voters
- Teams from day one in the data model

## Authentication

- Magic link only (no passwords) — DEC-035
- OAuth2 for Gmail/Microsoft IMAP connections from the start — DEC-034
- Session-based with long-lived sessions

## Testing

- **100% coverage for new and changed code** — enforced by a ratchet over recorded debt, not by `--coverage-min=100`. See [Coverage is a ratchet](#coverage-is-a-ratchet)
- **DAMA DoctrineTestBundle** — wraps each test in a transaction, rolls back after
- **Test bootstrap** creates and caches test DB via Doctrine migrations + fixtures
- Pattern from https://github.com/JanMikes/fajnesklady.cz/blob/main/tests/bootstrap.php and `TestingDatabaseCaching.php`
- **IdentityProvider** mocked in tests for deterministic UUIDs
- **ClockInterface** (PSR-20) mocked for deterministic timestamps
- **Infection mutation testing** from the start
- Tests describe business requirements — they are the specification
- **Never assert specific CSS/Tailwind classes** (spacing, font-size, responsive breakpoints, layout utilities) in tests. These change constantly during UI prototyping and have no business impact. Only assert semantic daisyUI tokens (e.g. `text-error`, `border-l-success`) when the test verifies a business rule like severity mapping.

### Coverage is a ratchet

CI does **not** run `--coverage-min=100`; on a codebase with several hundred partially-covered
infrastructure files that gate would fail on its first run and be switched off the same afternoon.
What `.github/workflows/ci.yml` actually runs, in the `tests` job, against the clover the test step
already produced:

```bash
php bin/coverage-audit.php coverage.xml --ratchet
```

`coverage-baseline.json` records, per file, how many uncovered statements that file is allowed. Today
that is **147 files and 1,258 statements** of debt out of 15,889 — **92.08% line coverage** — mostly
infrastructure adapters (IMAP, Stripe, API Platform state providers, the GitHub client, console
commands). **Existing debt is tolerated. New debt is not.** The gate fails when:

- a listed file's uncovered count **rises above** its recorded number;
- a file that is **not** listed has any uncovered line — new code is 100% covered, or it is an
  explicit exception someone added to the baseline in the same commit and justified in the PR;
- the baseline is **stale** — a file improved, reached 100%, or was deleted, and the number was not
  updated.

Per file, never one total: a single project-wide number lets one file improve while another rots.
Per count, never per line number: line numbers shift on every insertion above them, which would
report a wall of phantom findings after a formatting change and train everyone to re-record without
reading. The known hole in counting is that swapping one uncovered line for another inside an
already-listed file keeps the count equal — the new code is still in the diff, and the total cannot
grow.

That third rule is the burn-down, and it is why recording is manual: CI never writes the baseline
itself. An auto-update would ratchet *backwards* the first time it ran on a degraded build, and it
would hide the debt from the only place gaming is catchable — the diff.

The wider version of the counting hole is worth knowing, because that same stale rule creates it: a
change that both improves and degrades one listed file (113 → 110 while adding five uncovered lines)
fails as stale and is therefore *told* to re-record, which launders the new debt into a
smaller-looking number. The stale rule is both the burn-down and the bypass. When a re-record moves
a file, read what moved inside it, not just the direction.

For a line that genuinely cannot be reached from a test — a `false ===` guard on a syscall that
never fails in the suite — the existing `// @codeCoverageIgnore` convention still applies
(`ReportAttachmentExtractor`, `ImportDmarcReportCommand`). Like a baseline entry it is an exception
in plain sight; unlike one, it sits next to the line it excuses. Neither is a way to skip writing a
test you could have written.

```bash
# report every file below 100% and its uncovered lines — the distance still to go
docker compose exec app php bin/coverage-audit.php coverage.xml

# re-record after an improvement, then commit the smaller numbers
docker compose exec app php bin/coverage-audit.php coverage.xml --update
```

**Where the numbers come from.** The baseline is recorded from **CI's own clover**, downloadable as
the `coverage-clover` artifact of any run of the `tests` job. `php-code-coverage` intersects the
coverage driver's line map with its own static analysis, so pcov (CI) and Xdebug (the container)
disagree — measured at 26 statements across two files, the `#[ApiResource(...)]` argument lines in
`DomainResource` and `ReportResource`. Small, but the ratchet compares exact per-file counts, and CI
pins neither its PHP patch version nor its driver version. Recording from the report CI produced is
the only way to be sure of agreeing with the report CI will produce.

Measuring locally is still worth doing before you push — the container has Xdebug, not pcov, so the
driver needs switching on:

```bash
docker compose exec -e XDEBUG_MODE=coverage app vendor/bin/phpunit --coverage-clover=coverage.xml
docker compose exec app php bin/coverage-audit.php coverage.xml --ratchet
```

A local run therefore always ends with those two files reported as stale — `DomainResource` and
`ReportResource`, allowed 14, showing 1. Measured on one identical tree, that is the *entire*
difference between the drivers; anything else in the output is yours. **Do not `--update` from a
local report to silence them** — that records Xdebug's numbers and turns CI red instead. Re-record
from the artifact.

A `bin/console cache:clear --env=test` first is cheap insurance against a half-stale container,
though full runs across warm, cold and fresh-checkout caches produced identical numbers. If a report
and the baseline ever disagree wholesale, the tool lists the findings and then says it does not
believe them, so an environment mismatch is not mistaken for 40 real regressions.

### Browser smoke tests (`tests/Browser/`)

Playwright, run on the **host** (not in the app container). A deliberately small net for the
defects PHPUnit cannot see: CSS hit-testing (every report row opening the same report),
Stimulus/Turbo breakage that only shows up as a console error, and the axe accessibility
baseline. Everything else belongs in `tests/Unit` / `tests/Integration`.

**One-time setup on a fresh clone** — the sign-in secret is deliberately not committed:

```bash
echo 'SENDVERY_TEST_LOGIN_SECRET=pick-something-random' > .env.dev.local
docker compose restart app    # the app reads env files at boot
```

Then:

```bash
docker compose up -d          # the suite drives the running app at http://localhost
npx playwright test           # ~13 checks, ~25s; seeds demo data itself
npx playwright test reports   # one spec
```

- **Prerequisites are asserted, not assumed.** `tests/Browser/global-setup.ts` runs
  `sendvery:demo:seed` on every run (so specs can assert *exact* row counts), resolves the demo
  team's owner from the database, and fails with the exact commands to run if the app is
  unreachable, the secret is missing, or the login bypass does not answer.
- **Sign-in uses `/_test/login`**, not a magic link: `RequestMagicLinkHandler::MAX_REQUESTS_PER_HOUR`
  is 5 and fails silently past the cap. The route (`src/Controller/TestLoginController.php`) answers
  only in `dev`/`test`, only with the `SENDVERY_TEST_LOGIN_SECRET` shared secret, and only for a user
  that already exists. **The secret lives in `.env.dev.local` (gitignored *and* dockerignored) — never
  in `.env.dev`**, which is committed to a public repo and copied into every image: the endpoint signs
  in as any existing user in any team, so "it only answers in dev" is one gate, not two.
- **Never `page.mouse.click(x, y)`.** It does not scroll, so a below-the-fold element gets clicked
  at an off-screen point and reports a failure that is the test's fault. Use locators.
- **Zero console errors is enforced by the shared `context` fixture** in
  `tests/Browser/support/harness.ts` via `context.on('page', …)`, not by a block each spec copies.
  That covers the built-in `page` and any `context.newPage()`; a spec that builds its own context from
  the `browser` fixture escapes it, so take `page` or `context`. Two categories are annotated instead
  of failing — the dev toolbar's own `/_wdt/` requests, and resource failures from a **third-party
  origin** (the page loads Google Fonts, and a CDN blip must not fail the suite with a message
  blaming us). App-origin failures and every uncaught exception still fail.
- **The axe baseline is a ratchet**, not a gate: `tests/Browser/axe-baseline.json` records what is
  currently owed, and CI fails when a rule fires on a page the baseline does not record it on, or when
  a known rule spreads to more nodes. Fixes pass. No axe rule is disabled. Regenerate deliberately
  with `UPDATE_AXE_BASELINE=1 npx playwright test accessibility`. `/app` carries a documented ±1
  allowance (`MEASURED_NODE_SPREAD` in `support/axe.ts`) because `GetAllReports` has no ORDER BY
  tiebreaker — delete the entry once that lands.
- **Playwright and axe are pinned to exact versions** (and `playwright-core`/`axe-core` are pinned via
  `overrides`). `package-lock.json` is gitignored, so CI re-resolves every run; an axe minor adds
  rules, and a new rule reads as a new violation — red CI for a bump nobody made.
- CI runs this as the separate `browser` job in `.github/workflows/ci.yml`, against the PHP built-in
  server on port 8000 with `SENDVERY_CONSOLE_PREFIX=php` and `SENDVERY_TEST_LOGIN_SECRET` set in the
  job env.

## Unknown Is Not Failure

**Absent, unknown or not-yet-measured state must never render as failure. A desired outcome must never render as a warning.**

Four of the ~13 complaints in the first user-review pass were this single rule broken four times. The cost compounds: every false alarm teaches the user to distrust the next warning, including the real ones. In the user's words — *"this is basically useless alert … because otherwise it seems like something is wrong to me but this is totally okay and is actually desired"*, and *"i would prefer better feedback that it is not a failure but waiting for a first report"*.

### The four cases (all fixed — cite them rather than repeating them)

1. **Red `0.0% pass rate` on a domain with zero DMARC reports.** `GetDomainOverview` wrapped the division in `COALESCE(..., 0)`, so "no data" and "100% of mail failed" became the same number — and a comment in that file documented the behaviour as intentional. Fixed: `passRate` is `?float` end-to-end and every surface says "waiting for first report".
2. **"DKIM record changed for X" as an amber Warning when the previous value was empty** — i.e. the user had just correctly published a DKIM key for the first time. The desired outcome was reported as a possible problem. Fixed: `AlertSeverity::Success` + `AlertType::DnsRecordPublished`, rendered green, never emailed.
3. **"MX records not detected" on a domain with perfectly valid MX records.** MX state was read from the nightly `domain_health_snapshot`, which had not been written yet; unlike SPF/DKIM/DMARC, MX had no `*_verified_at` fallback — so "we have not checked yet" rendered as "it is broken". Fixed: state reads the authoritative latest `DnsCheckResult`.
4. **Amber "Unknown" badges on senders that were 100% DKIM-passing and 100% SPF-passing**, with no explanation and no stated action. "We have never asked you about this" looked identical to "this is a problem". Fixed: a three-state vocabulary — Authorized / Needs review / Not authorized — that separates never-reviewed from actively-rejected.

### Mechanical tells — grep for these

Each of the four had one. They are cheap to find and cheap to fix before a user sees them.

- **`COALESCE(<rate>, 0)` or `ELSE 0` in a rate, percentage, score or average expression.** Keep `NULLIF(<divisor>, 0)` — that is what produces the honest NULL. Never wrap it in a zero fallback. If ORDER BY needs a total order, use a separate sort-only expression and never select it (see `GetDomainOverview::PASS_RATE_SORT_EXPR`).
- **A non-nullable `float`/`int` in `src/Results/` or `src/Value/` for something *measured* that can have zero measurements.** Rates, scores, grades and averages are `?float`/`?int`. A non-nullable field forces the query to invent a number.
- **A "missing"/"broken" UI state derived from a table only a scheduled command writes** (`domain_health_snapshot`, blacklist results). Those tables are empty until the first cron run. Read the authoritative row instead (`dns_check_result`), or render "not checked yet".
- **A `bool` where the domain genuinely has three states: yes / no / never decided (or never checked).** Use an enum. `bool $isAuthorized` cannot tell "the user rejected this sender" from "the user has not looked yet"; `bool $isValid` cannot tell "malformed record" from "no record published".
- **An `{% else %}` or `default =>` arm that carries the error tone.** Unknown and future values fall into the last arm — make the last arm neutral and the error arm explicit.

### The positive obligation

Suppressing the number is not enough on its own — a bare blank where a value belongs also reads as broken. When there is no data, say **what the user is waiting for** and **whether they must act**:

- "Waiting for first report — usually within 24 hours" (nothing to do)
- "Not checked yet" (nothing to do)
- "Needs review" (something to do, and it is not an error)

`templates/components/_severity_glyph.html.twig` is the canonical implementation — `pass_rate_tone`, `pass_rate_class`, `pass_rate_value` and `pass_rate_stat` all have a null arm. Route pass rates through it instead of hand-rolling `>= 90 ? 'text-success' : (>= 70 ? 'text-warning' : 'text-error')`, which has no null branch and drifts.

The precedent predates the rule: `templates/components/DomainPassRateSparkline.html.twig` already rendered `—` for the empty case. That is precisely why the old red `0.0%` beside it was self-contradictory — two components describing the same absent data, one honestly and one as a catastrophe. When two surfaces disagree about the same missing value, the alarming one is the bug.

## Frontend: daisyUI 5 + Tailwind CSS 4

daisyUI is installed via npm (`package.json`) — NOT via Composer or the asset mapper importmap. The Tailwind CSS compiler (run by `symfonycasts/tailwind-bundle`) needs the Node.js daisyUI package to resolve `@plugin "daisyui"` and `@plugin "daisyui/theme"`.

### Theme definition (CRITICAL — v5 format)

daisyUI v5 uses `@plugin "daisyui/theme" {}` blocks with `--color-*` variables in oklch format. **Do NOT use the old v3/v4 format** (`--p`, `--pf`, `--pc`, `--b1`, `--b2`, etc.) — those variables are ignored by v5 and will produce a black-and-white broken UI.

Correct v5 format in `assets/styles/app.css`:
```css
@import "tailwindcss";
@plugin "daisyui";

@plugin "daisyui/theme" {
    name: "sendvery";
    default: true;
    color-scheme: light;
    --color-base-100: oklch(98.5% 0.002 247);
    --color-primary: oklch(49% 0.13 176);
    /* ... all --color-* variables ... */
    --radius-box: 0.75rem;
    --border: 1px;
    --depth: 1;
    --noise: 0;
}
```

Required variables: `--color-base-100`, `--color-base-200`, `--color-base-300`, `--color-base-content`, `--color-primary`, `--color-primary-content`, `--color-secondary`, `--color-secondary-content`, `--color-accent`, `--color-accent-content`, `--color-neutral`, `--color-neutral-content`, `--color-info`, `--color-success`, `--color-warning`, `--color-error` (each with `-content` variant), plus `--radius-selector`, `--radius-field`, `--radius-box`, `--size-selector`, `--size-field`, `--border`, `--depth`, `--noise`.

### Twig Components (`<twig:>` syntax)

Do NOT use `{% block content %}...{% endblock %}` inside `<twig:Component>` tags. The `TwigPreLexer` breaks when `<twig:>` tags are nested inside explicit `{% block %}` wrappers within component tags. Content inside `<twig:Component>...</twig:Component>` automatically goes into the default `content` block.

```twig
{# WRONG — breaks nested <twig:> tags #}
<twig:SectionContainer>
    {% block content %}
        <twig:PricingTable />
    {% endblock %}
</twig:SectionContainer>

{# CORRECT — content auto-maps to the content block #}
<twig:SectionContainer>
    <twig:PricingTable />
</twig:SectionContainer>
```

### Theme

Single light theme only (`data-theme="sendvery"` on `<html>`). Dark mode was intentionally removed — do not reintroduce a `sendvery-dark` theme or a `dark-mode` Stimulus controller without a product decision. Do NOT use Tailwind `dark:` prefix for theme-dependent styling — it won't work with daisyUI's data-theme approach.

### Marketing nav: no attention badges

The marketing-site top nav (`templates/components/Nav.html.twig`) intentionally has NO attention badges on its "Dashboard" CTA for signed-in users. The dashboard sidebar (TASK-060 / TASK-061 / quarantine badge) is the right home for live counts — surfacing them on public pages (Pricing, Learn, Tools) would feel intrusive and would leak the user's session state to over-the-shoulder onlookers. Do not propose mirroring sidebar badges onto the marketing nav.

## Docker

- Base image: `ghcr.io/thedevs-cz/php:8.5`
- Production Dockerfile pattern: https://github.com/JanMikes/fajnesklady.cz/blob/main/Dockerfile
- Local compose.yaml pattern: https://github.com/JanMikes/fajnesklady.cz/blob/main/compose.yaml
- FrankenPHP serves HTTP, Traefik (existing on server) handles TLS
- Messenger workers run as separate containers via `php bin/console messenger:consume`

## Crons

Recurring jobs are plain Symfony Console Commands scheduled by **system cron**, not Symfony Scheduler.

Sendvery runs on the **lily** host, and its crontab is versioned per-app in the lily infra repo at
`~/www/lily.srv/apps/sendvery/cron.d/sendvery` (lily decision D30). It is installed to
`/etc/cron.d/sendvery` by that app's `deploy.sh` (`install_cron`), and an app's cron changes also go
live on a push to the infra repo's `main` (the D36 reconciler treats cron as a class-C row with
`apply=none` — cron re-reads the directory itself, nothing is restarted).

> The old path `~/www/spare.srv/deployment/crontab` is **obsolete** — that was the pre-migration host.

Each entry is wrapped twice: `lily-cron-run <app> <slug>` (emits the lily metric feeding
`LilyCronJobFailed` and ships the line to Loki) *and* `sentry-cli monitors run` (Sentry catches a
*missed* run; lily catches *ran-and-failed*). Output goes to `/var/log/lily/sendvery-cron.log`. The
service invoked is `messenger-consumer`, not `worker`.

When you add a new scheduled command:

1. Build it as an idempotent `bin/console sendvery:*` command in `src/Command/`.
2. Add a line to `~/www/lily.srv/apps/sendvery/cron.d/sendvery` with a stable monitor slug, following
   the wrapping of the lines already there. Sub-hourly jobs carry `--failure-issue-threshold 2` (lily
   D37: a transient Sentry ingest stall otherwise fakes a missed check-in); daily-or-rarer jobs keep
   the default threshold so a genuine miss pages on the first occurrence.
3. **Deploy the app code before pushing the cron.** The cron goes live on push, so installing it ahead
   of the image that carries the command produces a failing job and a `LilyCronJobFailed` page.
4. Do **not** add `#[AsSchedule]` or `RecurringMessage` in the app — system cron owns scheduling.
5. Log the change in `~/www/lily.srv/docs/journal.md` (newest entry first) — that repo requires it.

Current entries (kept in sync with `apps/sendvery/cron.d/sendvery`):

- `*/15 * * * *` — `sendvery:mailbox:poll` (per-user IMAP/POP3 polling)
- `*/5 * * * *` — `sendvery:reports:poll-inbox` (central reports@sendvery.com inbox)
- `15 4 * * *` — `sendvery:reports:purge` (drop parsed/ignored envelopes past SENDVERY_ENVELOPE_PURGE_AFTER_DAYS)
- `30 4 * * *` — `sendvery:reports:quarantine:purge` (drop quarantined reports past their TTL)
- `0 3 * * *` — `sendvery:dns:check-all` (DNS record + verification re-check; writes one domain_health_snapshot per domain per run)
- `0 9 * * 1` — `sendvery:digest:send-all` (weekly digest)
- `0 0 * * *` — `sendvery:usage:reset` (roll expired monthly plan-usage counters forward, **and** queue release of reports parked by `QuarantineReason::PlanOverage` now that capacity has returned — a monthly roll is one of only two moments a team's allowance grows, the other being an upgrade)
- `45 4 * * *` — `sendvery:dmarc:purge` (per-team DMARC report retention purge from `PlanLimits::getRetentionDays`)
- `0 8 * * *` — `sendvery:plan-limits:warn-approaching` (email team owners crossing 80% of any plan cap; deduped by `team.plan_warning_at`)
- `0 4 * * *` — `sendvery:dns:sync-authorization-records` (reconcile Cloudflare RFC 7489 TXT records with active domains; creates missing, deletes stale)
- `30 5 * * *` — `sendvery:dmarc:auto-ramp` (DEC-058 auto-drive: safely advance managed DMARC policies with readiness gates + rollback; runs after the 03:00 DNS sweep refreshes cnameVerifiedAt, clear of the 04:xx purge window)
- `45 5 * * *` — `sendvery:dmarc:sync-hosted-records` (reconcile hosted managed-DMARC policy records: recreate/repair drift, dangling-safe teardown)
- `15 8 * * 1` — `sendvery:senders:review-reminder` (email team owners when senders awaiting review cross a volume threshold; deduped 30 days by a `NewUnknownSender` alert stamped `data.notification = 'senders_awaiting_review'`)
- `30 6 * * *` — `sendvery:ingestion:check-health` (W2: raise `AlertType::ReportsStopped` for domains silent past `max(3 × their own observed report cadence, 72h)`, and resolve the alert when reports resume). **Refuses to run unless `ingestion_source_status` proves our own central-inbox poll succeeded within the hour** — otherwise every domain looks silent for a reason that is ours, and the alert would send users to fix correct DNS. `--ignore-pipeline-health` overrides this for drills only, never for the cron row.
- `0 */6 * * *` — `sendvery:opensource:refresh-github-stats` (refresh cached GitHub stars/forks for the open-source page)
- `0 2 * * *` — `sendvery:blacklist:check-all` (W1/DEC-062: queue DNSBL lookups for the sending IPs of every paid-plan domain). Bounded three ways because public DNSBLs rate-limit and will null-route a noisy resolver: paid teams only, a **global** per-IP 24h freshness window (shared sending infrastructure is checked once, not once per customer), and a per-domain cap of 10 newest senders plus a 500-check sweep ceiling. Runs at 02:00, before the 03:00 DNS sweep, so a fresh listing is in place when the nightly snapshot is composed.

Ops:
- Re-run a failed envelope after a parser fix: `bin/console sendvery:reports:reprocess <envelope-id>`

## Comments

Document the WHY, not the what. Only document HOW if the implementation is non-obvious or surprising. No obvious comments like `// Get the domain`.

## Formatting

Follow PSR-12 and Symfony coding standards. Use PHP CS Fixer with Symfony preset.
