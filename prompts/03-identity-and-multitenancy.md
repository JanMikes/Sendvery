# Stage 3: Identity & Multi-Tenancy

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, especially: entities, multi-tenancy, authentication, CQRS pattern, value objects & enums.
2. `docs/04-data-model-protocols.md` — Full database schema: Team, User, TeamMembership, authorization model, team-scoping strategy.
3. `docs/02-architecture.md` — Multi-tenancy section, security architecture.

## What Already Exists (Stages 1-2 completed)

- Symfony 8.0 project, Docker (FrankenPHP + PostgreSQL 16), Tailwind CSS 4 + daisyUI
- **Core infrastructure:** EntityWithEvents interface, HasEvents trait, DomainEventsSubscriber, IdentityProvider
- **Test infrastructure:** bootstrap.php with DB caching, IntegrationTestCase, WebTestCase base classes
- All PHP config, no YAML. Tests passing. Infection configured.

## What to Build

The identity and multi-tenancy layer. Every feature after this will be team-scoped.

### 1. Value Objects & Enums

**`src/Value/TeamRole.php`** — enum:
```php
enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';
}
```

**`src/Value/EmailAddress.php`** — readonly final class wrapping a validated email string. Constructor validates format. Use as a value object for User email and anywhere emails appear.

### 2. Entities

Follow conventions from `CLAUDE.md`: final classes (not readonly — Doctrine constraint), EntityWithEvents where applicable, UUID v7 IDs passed via constructor, `readonly` for immutable properties.

**`src/Entity/Team.php`:**
- `id` (UUID v7, readonly)
- `name` (string)
- `slug` (string, unique — for URLs)
- `stripeCustomerId` (nullable string — for Phase 2)
- `plan` (string, default 'free' — for Phase 2)
- `createdAt` (DateTimeImmutable, readonly)
- Implements `EntityWithEvents`, records `TeamCreated` event in constructor

**`src/Entity/User.php`:**
- `id` (UUID v7, readonly)
- `email` (string, unique)
- `locale` (string, default 'en')
- `lastLoginAt` (nullable DateTimeImmutable)
- `createdAt` (DateTimeImmutable, readonly)
- Implements `UserInterface` (Symfony Security)
- Implements `EntityWithEvents`, records `UserRegistered` event
- No password field — magic link auth only (DEC-035)
- `getRoles()` returns `['ROLE_USER']` always (role-based access is via TeamMembership, not Symfony roles)

**`src/Entity/TeamMembership.php`:**
- `id` (UUID v7, readonly)
- `user` (ManyToOne → User)
- `team` (ManyToOne → Team)
- `role` (TeamRole enum)
- `joinedAt` (DateTimeImmutable, readonly)
- Unique constraint on (user, team)

### 3. Domain Events

**`src/Events/TeamCreated.php`** — readonly final class with `teamId` (UuidInterface)

**`src/Events/UserRegistered.php`** — readonly final class with `userId` (UuidInterface), `email` (string)

### 4. Repositories

**`src/Repository/TeamRepository.php`:**
- `get(UuidInterface $id): Team` — throws `TeamNotFound` if missing
- `findBySlug(string $slug): ?Team`

**`src/Repository/UserRepository.php`:**
- `get(UuidInterface $id): User` — throws `UserNotFound` if missing
- `findByEmail(string $email): ?User`

**`src/Repository/TeamMembershipRepository.php`:**
- `findForUser(UuidInterface $userId): array` — returns all memberships for a user
- `findForTeam(UuidInterface $teamId): array` — returns all memberships in a team
- `findMembership(UuidInterface $userId, UuidInterface $teamId): ?TeamMembership`

### 5. Exceptions

**`src/Exceptions/TeamNotFound.php`** — extends `\DomainException`
**`src/Exceptions/UserNotFound.php`** — extends `\DomainException`

### 6. Doctrine Team Filter

**`src/Doctrine/TeamFilter.php`:**
- Doctrine SQL filter that auto-applies `WHERE team_id = :teamId` to all entities with a `team_id` column
- Enabled globally in Doctrine config
- The filter reads the current team ID from a `TeamContext` service

**`src/Services/TeamContext.php`:**
- Holds the current team ID for the request
- Set by a Symfony event listener on `kernel.request` (reads from session or token)
- Provides `getCurrentTeamId(): ?UuidInterface`
- Provides `setCurrentTeamId(UuidInterface $teamId): void`

### 7. Security Voters

**`src/Security/TeamVoter.php`:**
- Votes on Team-related operations (VIEW, EDIT, DELETE, MANAGE_MEMBERS)
- Uses TeamMembershipRepository to check user's role in the team
- Owner can do everything, Admin can manage, Member can view, Viewer is read-only
- Reference the authorization model table in `docs/04-data-model-protocols.md`

### 8. Commands (CQRS)

**`src/Message/CreateTeam.php`** — readonly final class with `teamId`, `name`, `ownerUserId`
**`src/MessageHandler/CreateTeamHandler.php`** — creates Team, creates TeamMembership (owner role), persists

### 9. Queries

**`src/Query/GetUserTeams.php`** — returns teams for a given user, using DBAL Connection (raw SQL), returns array of `UserTeamResult` DTOs

**`src/Results/UserTeamResult.php`** — readonly final class with `teamId`, `teamName`, `teamSlug`, `role`, `memberCount`

### 10. Database Migration

Create a Doctrine migration that creates:
- `team` table
- `user` table (note: no password column)
- `team_membership` table with unique constraint on (user_id, team_id)
- Appropriate indexes on team_id FKs

### 11. Tests

Write tests for EVERYTHING. 100% coverage is mandatory.

**Unit tests:**
- `TeamRole` enum values
- `EmailAddress` value object (valid/invalid emails)
- `TeamVoter` (all role permutations)
- `Team` entity (constructor sets fields, records event)
- `User` entity (constructor, UserInterface methods)

**Integration tests:**
- `TeamRepository` (get, findBySlug, not found throws)
- `UserRepository` (get, findByEmail)
- `TeamMembershipRepository` (all query methods)
- `CreateTeamHandler` (creates team + membership, events dispatched)
- `GetUserTeams` query (returns correct DTOs)
- `TeamFilter` (verifies queries are scoped to team)

## Verification Checklist

- [ ] All tests pass with 100% coverage for new code
- [ ] Doctrine migration runs clean
- [ ] TeamFilter correctly scopes queries (integration test proves it)
- [ ] CreateTeam command creates both Team and owner TeamMembership
- [ ] TeamVoter correctly enforces role-based access
- [ ] All classes are `readonly final` (except entities which are just `final`)
- [ ] No YAML config anywhere
- [ ] Infection mutation testing passes for new code

## What Comes Next

Stage 4 builds the landing page layout and marketing site structure. The Team/User entities from this stage will be used later for auth (Stage 10) and team management (Stage 3 features in Phase 3). For now, they exist as the data foundation.
