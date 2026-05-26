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
3. `docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff` — code style

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
- **100% test coverage mandatory** — tests ARE the business specification

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
├── Doctrine/               # Custom Doctrine types, filters (team scoping)
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
- Doctrine SQL filter for automatic team scoping (registered globally)
- API Platform extension for team-scoped queries
- Authorization via Symfony Security Voters
- Teams from day one in the data model

## Authentication

- Magic link only (no passwords) — DEC-035
- OAuth2 for Gmail/Microsoft IMAP connections from the start — DEC-034
- Session-based with long-lived sessions

## Testing

- **100% test coverage mandatory** — `--coverage-min=100` in CI
- **DAMA DoctrineTestBundle** — wraps each test in a transaction, rolls back after
- **Test bootstrap** creates and caches test DB via Doctrine migrations + fixtures
- Pattern from https://github.com/JanMikes/fajnesklady.cz/blob/main/tests/bootstrap.php and `TestingDatabaseCaching.php`
- **IdentityProvider** mocked in tests for deterministic UUIDs
- **ClockInterface** (PSR-20) mocked for deterministic timestamps
- **Infection mutation testing** from the start
- Tests describe business requirements — they are the specification
- **Never assert specific CSS/Tailwind classes** (spacing, font-size, responsive breakpoints, layout utilities) in tests. These change constantly during UI prototyping and have no business impact. Only assert semantic daisyUI tokens (e.g. `text-error`, `border-l-success`) when the test verifies a business rule like severity mapping.

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

Recurring jobs are plain Symfony Console Commands scheduled by **system cron**, not Symfony Scheduler. The crontab lives in `~/www/spare.srv/deployment/crontab` on the deployment host and is committed alongside the other projects' schedules. Each entry runs the command via `docker compose run --rm worker bin/console …` wrapped in `sentry-cli monitors run` so missed runs page us.

When you add a new scheduled command:

1. Build it as an idempotent `bin/console sendvery:*` command in `src/Command/`.
2. Add a line to `~/www/spare.srv/deployment/crontab` under the `## Sendvery` block with a stable monitor slug.
3. Do **not** add `#[AsSchedule]` or `RecurringMessage` in the app — system cron owns scheduling.

Current entries (kept in sync with `crontab`):

- `*/15 * * * *` — `sendvery:mailbox:poll` (per-user IMAP/POP3 polling)
- `*/5 * * * *` — `sendvery:reports:poll-inbox` (central reports@sendvery.com inbox)
- `15 4 * * *` — `sendvery:reports:purge` (drop parsed/ignored envelopes past SENDVERY_ENVELOPE_PURGE_AFTER_DAYS)
- `30 4 * * *` — `sendvery:reports:quarantine:purge` (drop quarantined reports past their TTL)
- `0 3 * * *` — `sendvery:dns:check-all` (DNS record + verification re-check; writes one domain_health_snapshot per domain per run)
- `0 9 * * 1` — `sendvery:digest:send-all` (weekly digest)
- `0 0 * * *` — `sendvery:usage:reset` (roll expired monthly plan-usage counters forward)
- `45 4 * * *` — `sendvery:dmarc:purge` (per-team DMARC report retention purge from `PlanLimits::getRetentionDays`)
- `0 8 * * *` — `sendvery:plan-limits:warn-approaching` (email team owners crossing 80% of any plan cap; deduped by `team.plan_warning_at`)
- `0 4 * * *` — `sendvery:dns:sync-authorization-records` (reconcile Cloudflare RFC 7489 TXT records with active domains; creates missing, deletes stale)
- Blacklist checks: daily (later phase)

Ops:
- Re-run a failed envelope after a parser fix: `bin/console sendvery:reports:reprocess <envelope-id>`

## Comments

Document the WHY, not the what. Only document HOW if the implementation is non-obvious or surprising. No obvious comments like `// Get the domain`.

## Formatting

Follow PSR-12 and Symfony coding standards. Use PHP CS Fixer with Symfony preset.
