# Sendvery — CLAUDE.md

This file contains architecture conventions, coding standards, and project context for vibecoding. Follow these rules strictly when generating code.

## Project

Sendvery is an email health & deliverability micro-SaaS. DMARC report parsing with AI-powered insights. Open source (AGPL-3.0), self-hosted always free.

## Tech Stack

- **PHP 8.5** (strict_types=1 everywhere)
- **Symfony 8.0** (upgrade to 8.1 May 2026, target 8.4 LTS Nov 2027)
- **FrankenPHP** worker mode (built-in Caddy, no separate web server)
- **PostgreSQL 16** (single DB for app data + Messenger queue transport)
- **Doctrine ORM** + **DBAL** (ORM for writes, DBAL for reads)
- **Symfony Messenger** (Doctrine transport) for async commands and domain events
- **Tailwind CSS 4** + **daisyUI** + **ApexCharts** for frontend
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

All classes are `readonly final` by default unless there's a specific reason not to (e.g., Doctrine entities can't be readonly due to lazy loading, but their properties should be readonly where possible).

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

## Docker

- Base image: `ghcr.io/thedevs-cz/php:8.5`
- Production Dockerfile pattern: https://github.com/JanMikes/fajnesklady.cz/blob/main/Dockerfile
- Local compose.yaml pattern: https://github.com/JanMikes/fajnesklady.cz/blob/main/compose.yaml
- FrankenPHP serves HTTP, Traefik (existing on server) handles TLS
- Messenger workers run as separate containers via `php bin/console messenger:consume`

## Crons

Symfony Console Commands triggered by Symfony Scheduler or system cron:

- Poll IMAP/POP3 mailboxes (every 5-15 min)
- DNS record checks (daily)
- Weekly digest emails
- Blacklist checks (daily, later phase)

## Comments

Document the WHY, not the what. Only document HOW if the implementation is non-obvious or surprising. No obvious comments like `// Get the domain`.

## Formatting

Follow PSR-12 and Symfony coding standards. Use PHP CS Fixer with Symfony preset.
