# Stage 2: Core Infrastructure

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions. Pay special attention to: EntityWithEvents, HasEvents trait, DomainEventsSubscriber, IdentityProvider, CQRS pattern, testing conventions.
2. `docs/02-architecture.md` — Sections: "Domain Events", "Identity Provider", "Testing Conventions", "PHP Code Architecture Conventions"

## What Already Exists (Stage 1 completed)

- Symfony 8.0 project with PHP 8.5
- Docker setup: FrankenPHP + PostgreSQL 16 + Messenger worker
- Tailwind CSS 4 + daisyUI + AssetMapper
- PHPUnit + DAMA DoctrineTestBundle + Infection configured
- Symfony Messenger with Doctrine transport
- All PHP config files (no YAML)
- Base Twig layout template
- A kernel boot test

## What to Build

The foundational infrastructure that every entity, command, query, and test will depend on. This is the skeleton inside the skeleton.

### 1. EntityWithEvents Interface + HasEvents Trait

Create exactly as specified in `CLAUDE.md` → "Domain Events" section:

**`src/Entity/EntityWithEvents.php`** — interface with `recordThat(object $event): void` and `popEvents(): array`

**`src/Entity/HasEvents.php`** — trait implementing the interface. Private `$events` array, `recordThat()` appends, `popEvents()` returns and clears.

### 2. DomainEventsSubscriber

**`src/Services/DomainEventsSubscriber.php`** — Doctrine event subscriber that:
- Listens to `postPersist`, `postUpdate`, `postRemove` lifecycle events
- Collects events from entities implementing `EntityWithEvents`
- On `postFlush`, dispatches all collected events via `MessageBusInterface`
- Clears the collected events after dispatch
- Register as a Doctrine event listener (not subscriber — Symfony 8 prefers listeners)

Pattern reference: https://github.com/MySpeedPuzzling/myspeedpuzzling.com/ (mentioned in CLAUDE.md)

### 3. IdentityProvider

**`src/Services/IdentityProvider.php`** — `readonly final class` with `nextIdentity(): UuidInterface` that returns `Uuid::uuid7()`. This is the ONLY place in the entire codebase where UUIDs are generated directly.

### 4. Test Bootstrap with Database Caching

Create the test infrastructure described in `CLAUDE.md` → Testing section:

**`tests/bootstrap.php`:**
- Boots Symfony kernel in test environment
- Calls `TestingDatabaseCaching::refresh()` to ensure test DB is current
- Pattern from https://github.com/JanMikes/fajnesklady.cz/blob/main/tests/bootstrap.php

**`tests/TestingDatabaseCaching.php`:**
- Hashes all migration files + fixture files
- Compares hash with stored hash in test DB (or a cache file)
- If hash differs: drops and recreates DB, runs all migrations, loads fixtures
- If hash matches: DB is already up to date, skip rebuild
- This makes test runs fast after the first run

**`phpunit.xml.dist`** update:
- Set `bootstrap="tests/bootstrap.php"`
- Ensure DAMA listener is active

### 5. Base Test Case Classes

**`tests/IntegrationTestCase.php`:**
- Extends `Symfony\Bundle\FrameworkBundle\Test\KernelTestCase`
- Provides helper method `getService(string $class)` for container access
- Provides method to get a mocked IdentityProvider with predictable UUIDs
- Provides method to get a frozen Clock

**`tests/WebTestCase.php`:**
- Extends `Symfony\Bundle\FrameworkBundle\Test\WebTestCase`
- Provides authenticated client helpers (for later, when auth exists)
- Provides `assertResponseIsSuccessful()` and common assertion helpers

### 6. Test for IdentityProvider

**`tests/Unit/Services/IdentityProviderTest.php`:**
- Verify `nextIdentity()` returns a valid UUID
- Verify each call returns a different UUID
- Verify UUIDs are v7 (time-ordered)

### 7. Test for DomainEventsSubscriber

**`tests/Integration/Services/DomainEventsSubscriberTest.php`:**
- Create a dummy entity implementing `EntityWithEvents` (test double)
- Persist it with recorded events
- Verify events are dispatched to the message bus after flush
- Verify events are cleared after dispatch

### 8. Test for HasEvents Trait

**`tests/Unit/Entity/HasEventsTest.php`:**
- Test `recordThat()` stores events
- Test `popEvents()` returns all events and clears the list
- Test multiple events are returned in order

### 9. Directory Structure

Ensure these directories exist (even if empty, create `.gitkeep`):
```
src/Attribute/
src/Controller/
src/Doctrine/
src/Entity/
src/Events/
src/Exceptions/
src/FormData/
src/Message/
src/MessageHandler/
src/Query/
src/Repository/
src/Results/
src/Services/
src/Value/
src/Twig/
tests/Unit/
tests/Integration/
```

## Verification Checklist

- [ ] `vendor/bin/phpunit` passes all tests (IdentityProvider, HasEvents, DomainEventsSubscriber)
- [ ] Test bootstrap creates/caches the test database correctly
- [ ] Running tests a second time is fast (DB cache hit)
- [ ] `vendor/bin/infection --min-msi=100` passes for the files with tests
- [ ] IdentityProvider generates valid UUID v7
- [ ] DomainEventsSubscriber correctly dispatches events after Doctrine flush
- [ ] All classes follow conventions: `readonly final class`, `strict_types=1`, proper namespacing

## What Comes Next

Stage 3 builds the identity layer: Team, User, TeamMembership entities with the patterns established here (EntityWithEvents, IdentityProvider, HasEvents). The DomainEventsSubscriber will handle their domain events. Tests will use the bootstrap and test case classes created here.
