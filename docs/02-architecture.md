# Architecture & Tech Stack

**Last updated:** 2026-03-24
**Status:** Decided

## Tech Stack

| Layer | Choice | Notes |
|-------|--------|-------|
| **Language** | PHP 8.5 | Latest stable (8.5.4). Pipe operator, clone-with, URI parser |
| **Framework** | Symfony 8.0 | Monolith. Upgrade to 8.1 (May 2026), target 8.4 LTS (Nov 2027) |
| **App Server** | FrankenPHP | Worker mode (3x throughput vs PHP-FPM). Built-in Caddy = free HTTPS. No separate Nginx needed |
| **API** | API Platform | REST/GraphQL for future user-facing API |
| **Database** | PostgreSQL 16 | Single DB for everything including queues |
| **ORM** | Doctrine | Standard Symfony ORM |
| **Queues** | Symfony Messenger | Doctrine transport (Postgres) initially, technology-agnostic — can swap to Redis/RabbitMQ later |
| **Crons** | Symfony Console Commands | Periodic tasks via system cron or Symfony Scheduler |
| **Email sending** | Symfony Mailer | External SMTP via Seznam Email Profi |
| **Email receiving** | IMAP/POP3 client | Both protocols supported (DEC-039). OAuth2 for Gmail/Microsoft (DEC-034) |
| **Payments** | Stripe | Subscriptions, checkout, webhooks |
| **Auth** | Magic link (email) | No passwords. Session-based with long-lived sessions (DEC-035) |
| **i18n** | Symfony Translation | English only at launch, i18n infrastructure ready (DEC-036) |
| **IDs** | UUID v7 | Via `IdentityProvider::nextIdentity()` — mockable in tests |
| **Docker base** | `ghcr.io/thedevs-cz/php8.5:latest` | FrankenPHP + Caddy, worker mode, opcache, extensions included |
| **Error tracking** | Sentry | PHP SDK + Symfony integration |
| **Frontend** | Twig + Stimulus/Turbo (Hotwire) | Server-rendered via Symfony UX, part of the monolith |
| **Testing** | PHPUnit + Symfony Test | **100% coverage mandatory** — tests ARE the business spec |
| **Deployment** | Docker + Docker Compose | Self-hosted on Hetzner dedicated (Ubuntu) |

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     DOCKER COMPOSE                           │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  Symfony Application (FrankenPHP — worker mode)      │    │
│  │                                                     │    │
│  │  ┌─────────────┐  ┌────────────┐  ┌─────────────┐  │    │
│  │  │ Web UI      │  │ API        │  │ CLI Commands │  │    │
│  │  │ (Twig)      │  │ (API Plat) │  │ (Console)   │  │    │
│  │  └──────┬──────┘  └─────┬──────┘  └──────┬──────┘  │    │
│  │         └───────────────┼────────────────┘          │    │
│  │                         ▼                           │    │
│  │  ┌──────────────────────────────────────────────┐   │    │
│  │  │            Service Layer                      │   │    │
│  │  │                                              │   │    │
│  │  │  ┌────────────┐ ┌──────────┐ ┌────────────┐ │   │    │
│  │  │  │ IMAP       │ │ Report   │ │ DNS        │ │   │    │
│  │  │  │ Ingestion  │ │ Parser   │ │ Checker    │ │   │    │
│  │  │  └────────────┘ └──────────┘ └────────────┘ │   │    │
│  │  │  ┌────────────┐ ┌──────────┐ ┌────────────┐ │   │    │
│  │  │  │ Alert      │ │ AI       │ │ Stripe     │ │   │    │
│  │  │  │ Engine     │ │ Analysis │ │ Billing    │ │   │    │
│  │  │  └────────────┘ └──────────┘ └────────────┘ │   │    │
│  │  └──────────────────────┬───────────────────────┘   │    │
│  │                         ▼                           │    │
│  │  ┌──────────────────────────────────────────────┐   │    │
│  │  │  Symfony Messenger (async jobs)              │   │    │
│  │  │  Transport: Doctrine (Postgres)              │   │    │
│  │  │                                              │   │    │
│  │  │  Messages:                                   │   │    │
│  │  │  - FetchImapMailboxMessage                   │   │    │
│  │  │  - ParseDmarcReportMessage                   │   │    │
│  │  │  - CheckDnsRecordsMessage                    │   │    │
│  │  │  - SendDigestEmailMessage                    │   │    │
│  │  │  - AnalyzeWithAiMessage (later)              │   │    │
│  │  └──────────────────────────────────────────────┘   │    │
│  │                                                     │    │
│  │  ┌──────────────────────────────────────────────┐   │    │
│  │  │  Symfony Scheduler (cron)                    │   │    │
│  │  │                                              │   │    │
│  │  │  - Poll IMAP mailboxes (every 5-15 min)      │   │    │
│  │  │  - DNS record checks (daily)                 │   │    │
│  │  │  - Send weekly digests (weekly)              │   │    │
│  │  │  - Blacklist checks (daily, later)           │   │    │
│  │  └──────────────────────────────────────────────┘   │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                             │
│  ┌──────────────────┐                                       │
│  │  PostgreSQL 16   │                                       │
│  │  - App data      │                                       │
│  │  - Messenger     │                                       │
│  │    transport     │                                       │
│  │  - Encrypted     │                                       │
│  │    credentials   │                                       │
│  └──────────────────┘                                       │
│                                                             │
└─────────────────────────────────────────────────────────────┘

External services:
  ┌──────────────────┐  ┌──────────┐  ┌──────────┐
  │ Seznam Email     │  │ Stripe   │  │ Sentry   │
  │ Profi (SMTP +   │  │ (billing)│  │ (errors) │
  │ IMAP for our     │  │          │  │          │
  │ hosted mailbox)  │  │          │  │          │
  └──────────────────┘  └──────────┘  └──────────┘
  ┌──────────────────┐
  │ Anthropic API    │
  │ (Claude, later)  │
  └──────────────────┘
```

## Docker Compose Structure

```yaml
# docker-compose.yml
# Assumes Traefik is running on the host as the reverse proxy (TLS, routing)
# FrankenPHP serves HTTP only — Traefik terminates TLS

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    depends_on:
      - database
    environment:
      SERVER_NAME: ":80"  # HTTP only — Traefik handles TLS
      DATABASE_URL: "postgresql://app:secret@database:5432/sendvery?serverVersion=16"
      MAILER_DSN: "smtp://user:pass@smtp.seznam.cz:465"
      SENTRY_DSN: "https://..."
      STRIPE_SECRET_KEY: "sk_..."
      ENCRYPTION_KEY: "..."  # For IMAP credential encryption
      FRANKENPHP_CONFIG: "worker ./public/index.php"
    volumes:
      - ./var:/app/var  # Logs, cache
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.sendvery.rule=Host(`sendvery.com`)"
      - "traefik.http.routers.sendvery.entrypoints=websecure"
      - "traefik.http.routers.sendvery.tls.certresolver=letsencrypt"
      - "traefik.http.services.sendvery.loadbalancer.server.port=80"
    networks:
      - traefik
      - default
    restart: unless-stopped

  worker:
    build: .
    command: php bin/console messenger:consume async --time-limit=3600
    depends_on:
      - database
    environment:
      # Same env vars as app (minus SERVER_NAME/FRANKENPHP_CONFIG)
    restart: unless-stopped

  scheduler:
    build: .
    command: php bin/console messenger:consume scheduler_default --time-limit=3600
    depends_on:
      - database
    environment:
      # Same env vars as app
    restart: unless-stopped

  database:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: app
      POSTGRES_PASSWORD: secret
      POSTGRES_DB: sendvery
    volumes:
      - pgdata:/var/lib/postgresql/data
    # No ports exposed — only accessible from app/worker/scheduler
    networks:
      - default

volumes:
  pgdata:

networks:
  traefik:
    external: true  # Shared Traefik network on the host
```

### Docker Base Image

Use `ghcr.io/thedevs-cz/php8.5:latest` from https://github.com/thedevs-cz/docker. Includes: FrankenPHP + Caddy, worker mode, file watching, opcache, extensions (bcmath, intl, pcntl, zip, uuid, pdo_pgsql, opcache, apcu, gd, redis, xdebug, etc.), custom entrypoint hooks.

**Production Dockerfile:** Inspire by https://github.com/JanMikes/fajnesklady.cz/blob/main/Dockerfile

**Local compose.yaml:** Inspire by https://github.com/JanMikes/fajnesklady.cz/blob/main/compose.yaml

### FrankenPHP Worker Mode Notes

FrankenPHP worker mode keeps the Symfony kernel booted in memory between requests. This means:
- **Performance:** ~3x throughput vs PHP-FPM (no bootstrap cost per request)
- **Memory leaks:** Must be careful with services that accumulate state. Use `kernel.reset` tag and Symfony's `ResetInterface`
- **Behind Traefik:** FrankenPHP serves HTTP only. Traefik (existing on server) handles TLS, Let's Encrypt, HTTP/2, HTTP/3, and routing
- **Development:** FrankenPHP detects file changes and reloads automatically (HMR-like DX)
- **Messenger workers:** Still run as separate processes (not in FrankenPHP worker mode) via `php bin/console`

## Testing Strategy

**100% test coverage is mandatory. Tests describe business requirements.**

### Test pyramid:
1. **Unit tests** — Service classes, parsers, validators, value objects
2. **Integration tests** — Doctrine repositories, Messenger handlers, IMAP client
3. **Functional/API tests** — API Platform endpoints, web controllers
4. **End-to-end** — Full flows (report ingestion → parsing → storage → alerts)

### Testing tools:
- PHPUnit 11+ (Symfony's default)
- Symfony WebTestCase / ApiTestCase
- Doctrine test fixtures
- PHPUnit coverage enforcement in CI (`--coverage-min=100`)
- Possibly: Infection (mutation testing) for quality beyond coverage

### Test-first approach:
Since this is vibecoded, the workflow is:
1. Write business requirement as a test
2. Vibe-code the implementation
3. Verify tests pass and coverage stays at 100%
4. Review generated code with Symfony expertise

## Email Configuration

### Sending (Symfony Mailer)
- Transport: SMTP via Seznam Email Profi
- Used for: digest emails, alerts, transactional emails (welcome, password reset)
- DSN: `smtp://user:pass@smtp.seznam.cz:465`

### Receiving (IMAP)
- Option 1: User's own mailbox (user provides IMAP credentials)
- Option 2: Our hosted mailbox on Seznam Email Profi (user sets rua= in DNS)
- Both connect via IMAP — same code path
- Library: TBD (php-imap, Webklex IMAP, or similar)

## Internationalization (i18n)

- Symfony Translation component from day one
- ICU message format for plurals, dates, etc.
- Default language: English
- Additional languages: English only at launch (DEC-036). Infrastructure ready for adding languages later
- All user-facing strings in translation files, none hardcoded

## Security Architecture

### IMAP Credential Storage
- Application-level AES-256-GCM encryption
- Encryption key from environment variable (never in DB or code)
- Each credential gets unique IV
- OAuth2 where providers support it (Gmail, Microsoft) to avoid storing passwords
- IMAP connections use TLS/SSL only

### General
- Docker containers run as non-root
- HTTPS via Traefik (existing reverse proxy on server, automatic Let's Encrypt)
- Symfony security component for authentication
- CSRF protection on all forms
- Rate limiting on API endpoints
- Stripe webhook signature verification

---

## PHP Code Architecture Conventions

These conventions are mandatory. They will also be included in the project's `CLAUDE.md` for vibecoding context.

### Core Principles

- **Strongly typed PHP 8.5** — strict_types=1 everywhere, no mixed types unless absolutely necessary
- **Objects over arrays** — never use associative arrays for structured data. Use value objects, DTOs, readonly classes
- **Immutability preferred** — use `readonly` classes and properties. Mutations via explicit methods that return new instances or are clearly named
- **Modern PHP** — prefer `readonly public` properties over getters/setters. Use constructor promotion, named arguments, enums, match expressions, first-class callables, pipe operator where it improves readability
- **Convention over configuration** — follow Symfony defaults, don't fight the framework
- **Simple, decoupled, readable** — clean architecture, well unit-testable, minimal inheritance, prefer composition
- **Document the WHY** — code comments explain reasoning and business context, not what the code does. Only document HOW if the implementation is non-obvious or surprising
- **12-factor app** — config from env vars, stateless processes, port binding, dev/prod parity

### CQRS Implementation (Symfony Messenger)

Inspired by https://github.com/MySpeedPuzzling/myspeedpuzzling.com/

**Commands** (`src/Message/`):
- Write operations — represent user intent
- Immutable `readonly final class` with constructor
- Named as imperative verb: `AddDomain`, `ConnectMailbox`, `ParseDmarcReport`
- Handlers NEVER return anything
- If the caller needs an ID, provide it to the command via `IdentityProvider::nextIdentity()` (UUID v7)
- Static factory methods from form data when applicable

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

**Command Handlers** (`src/MessageHandler/`):
- `#[AsMessageHandler]` attribute
- `readonly final class` with single `__invoke()` method
- Emit domain events via entities
- Handle one command per handler

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

**Queries** (`src/Query/`):
- Read operations — bypass command bus entirely
- Inject `Doctrine\DBAL\Connection` directly for raw SQL (not entity manager)
- Return Results DTOs, not entities
- `readonly final class` with descriptive method names

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

**Results** (`src/Results/`):
- `readonly final class` DTOs for query responses
- Static `fromDatabaseRow()` factory method with docblock type annotation for the array shape
- Never entities — always plain data objects

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

### Domain Events

Entities emit domain events. A Doctrine listener collects them and dispatches via Symfony Messenger after flush.

**EntityWithEvents interface + HasEvents trait:**

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

**Entities emit events in constructor and mutation methods:**

```php
#[ORM\Entity]
class MonitoredDomain implements EntityWithEvents
{
    use HasEvents;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        public readonly UuidInterface $id,
        // ...
    ) {
        $this->recordThat(new DomainAdded($this->id, $this->team->id));
    }
}
```

**Event handlers** use the same `#[AsMessageHandler]` attribute:

```php
#[AsMessageHandler]
readonly final class CheckDnsWhenDomainAdded
{
    public function __invoke(DomainAdded $event): void
    {
        // Trigger initial DNS check for the new domain
    }
}
```

**DomainEventsSubscriber** (Doctrine listener):
- Collects events from entities implementing `EntityWithEvents` on postPersist/postUpdate/postRemove
- Dispatches all collected events on postFlush via `MessageBusInterface`
- Follows the pattern from MySpeedPuzzling — see `src/Services/DomainEventsSubscriber.php`

### Messenger Configuration

```php
// config/packages/messenger.php
return App::config([
    'framework' => [
        'messenger' => [
            'buses' => [
                'command_bus' => [
                    'middleware' => [
                        'doctrine_transaction',
                    ],
                ],
            ],
            'failure_transport' => 'failed',
            'transports' => [
                'sync' => ['dsn' => 'sync://'],
                'failed' => ['dsn' => 'doctrine://default?queue_name=failed'],
                'async' => ['dsn' => '%env(MESSENGER_TRANSPORT_DSN)%'],
            ],
            'routing' => [
                // Commands that need immediate UI feedback: sync
                // Background work (email sending, AI analysis): async
                // Domain events: decide per event
            ],
        ],
    ],
]);
```

### Identity Provider

All entity IDs use UUID v7 (time-ordered, sortable) via `IdentityProvider`:

```php
readonly final class IdentityProvider
{
    public function nextIdentity(): UuidInterface
    {
        return Uuid::uuid7();
    }
}
```

**Why a service, not direct `Uuid::uuid7()` calls:** Enables test mocking. Tests can provide deterministic IDs for assertions.

```php
// In controller or form handler:
$domainId = $this->identityProvider->nextIdentity();
$this->commandBus->dispatch(new AddDomain($domainId, $teamId, $domainName));

// After dispatch, redirect to:
return $this->redirectToRoute('domain_detail', ['id' => $domainId]);
```

### Controllers

**Single action controllers** with `__invoke()`:

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

- One controller = one route = one action
- No `index`, `show`, `create` methods on the same class
- Controller names describe the action: `AddDomainController`, `ShowDomainDetailController`, `ListDomainsController`

### Configuration

**Symfony 8 PHP configs using `App::config()` syntax:**

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

No YAML configs. All PHP.

### Directory Structure

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
tests/
├── Unit/                   # Pure unit tests (no DB, no container)
├── Integration/            # Tests with DB (WebTestCase, DAMA DoctrineTestBundle)
├── bootstrap.php           # Test bootstrap (database caching)
└── TestingDatabaseCaching.php  # Doctrine migrations + fixtures bootstrap
```

### Testing Conventions

- **DAMA DoctrineTestBundle** for test database — wraps each test in a transaction, rolls back after
- **Test bootstrap** creates and caches the test database schema via Doctrine migrations + fixtures
- Pattern from https://github.com/JanMikes/fajnesklady.cz/blob/main/tests/bootstrap.php and `TestingDatabaseCaching.php`
- **IdentityProvider** mocked in tests to provide deterministic UUIDs
- **ClockInterface** (PSR-20) mocked for deterministic timestamps
- **100% coverage enforced in CI** — `--coverage-min=100`
- **Infection mutation testing** from the start

### Entities

- Constructor-based initialization (not setters)
- `readonly` for immutable properties (ID, creation date)
- Public properties preferred over getters when no logic is needed
- Implement `EntityWithEvents` for entities that emit domain events
- Use `Uuid::uuid7()` for IDs (via `IdentityProvider` in application code, direct in entity constructors via constructor parameter)

### Value Objects & Enums

```php
// Use PHP 8.1+ enums for fixed sets
enum DmarcPolicy: string
{
    case None = 'none';
    case Quarantine = 'quarantine';
    case Reject = 'reject';
}

// Use readonly classes for compound values
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

### Multi-Tenancy

- Every tenant-scoped entity has `team_id` FK
- Doctrine SQL filter for automatic team scoping (registered globally)
- API Platform extension for team-scoped queries
- Authorization via Symfony Security Voters
