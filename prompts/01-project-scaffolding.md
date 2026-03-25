# Stage 1: Project Scaffolding

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS. This is a brand new project — nothing exists yet except documentation.

**Before writing any code, read these files to understand the project and its conventions:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, coding standards, tech stack. Follow these strictly.
2. `docs/02-architecture.md` — Full architecture details, Docker Compose structure, FrankenPHP notes
3. `docs/10-libraries-and-tools.md` — Recommended packages and their versions

## What Already Exists

Only documentation files in `docs/` and `CLAUDE.md`. No application code yet.

## What to Build

Set up the complete Symfony 8 project skeleton with Docker, ready for development.

### 1. Symfony Project

Create a new Symfony 8.0 project with these requirements:
- PHP 8.5 with `strict_types=1` in every file
- Symfony 8.0 skeleton (webapp pack)
- Configure using PHP config files only (`App::config()` syntax) — **no YAML configs**

### 2. Docker Setup

Create Docker configuration based on the patterns described in `CLAUDE.md`:
- **Base image:** `ghcr.io/thedevs-cz/php8.5:latest` from https://github.com/thedevs-cz/docker
- **docker-compose.yml** with services: `app` (FrankenPHP), `worker` (Messenger consumer), `database` (PostgreSQL 16)
- FrankenPHP in worker mode (`FRANKENPHP_CONFIG: "worker ./public/index.php"`)
- Traefik labels on the app service (see `docs/02-architecture.md` for exact labels)
- PostgreSQL 16-alpine with volume for data persistence
- Worker service runs `php bin/console messenger:consume async --time-limit=3600`
- Reference `docs/02-architecture.md` → "Docker Compose Structure" section for the full compose layout

### 3. Core Composer Packages

Install these packages (check `docs/10-libraries-and-tools.md` for rationale):

**require:**
- `symfony/orm-pack` (Doctrine ORM + DBAL + migrations)
- `symfony/messenger` (async commands + events)
- `symfony/mailer` (email sending)
- `symfony/security-bundle` (auth)
- `symfony/translation` (i18n ready)
- `symfony/twig-bundle` + `symfony/ux-turbo` + `symfony/stimulus-bundle` (Hotwire)
- `symfony/asset-mapper` (no Webpack/Vite)
- `symfonycasts/tailwind-bundle` (Tailwind CSS 4, no Node.js)
- `api-platform/core` (REST API, for later phases)
- `ramsey/uuid` (UUID v7 generation)
- `symfony/clock` (PSR-20 ClockInterface)
- `symfony/scheduler` (cron scheduling)
- `sentry/sentry-symfony` (error tracking)

**require-dev:**
- `symfony/test-pack` (PHPUnit, WebTestCase)
- `dama/doctrine-test-bundle` (transaction-wrapping tests)
- `infection/infection` (mutation testing)
- `symfony/debug-bundle`
- `symfony/web-profiler-bundle`
- `phpunit/phpunit` (if not in test-pack)

### 4. Database Configuration

- Configure Doctrine for PostgreSQL via `DATABASE_URL` env var
- Create initial migration (empty DB, just the schema setup)
- Configure Messenger to use Doctrine transport: `doctrine://default?queue_name=messages`

### 5. Tailwind CSS 4 + daisyUI

- Install Tailwind CSS 4 via `symfonycasts/tailwind-bundle`
- Add daisyUI as a Tailwind plugin
- Create a base `app.css` that imports Tailwind layers
- Create a minimal `base.html.twig` layout template that loads the CSS

### 6. Testing Infrastructure

- Configure PHPUnit with coverage enforcement
- Create `phpunit.xml.dist` with:
  - Test suite pointing to `tests/`
  - Coverage minimum enforcement (set to 100 as a target)
  - DAMA DoctrineTestBundle listener enabled
- Create `infection.json5` config for mutation testing
- Create a minimal test that verifies the app kernel boots

### 7. Configuration Files (PHP only)

Create Symfony config files using `App::config()` syntax for:
- `config/packages/doctrine.php`
- `config/packages/messenger.php` (see `CLAUDE.md` → Messenger Configuration)
- `config/packages/framework.php`
- `config/packages/twig.php`
- `config/packages/security.php` (minimal, just firewalls)
- `config/packages/asset_mapper.php`

### 8. Git Setup

- Create `.gitignore` appropriate for Symfony 8 + Docker
- Create `.env` with sensible defaults (DATABASE_URL, MESSENGER_TRANSPORT_DSN, etc.)

## Verification Checklist

Before considering this stage complete:
- [ ] `docker compose up -d` starts all services without errors
- [ ] `docker compose exec app php bin/console about` shows Symfony 8.0, PHP 8.5
- [ ] `docker compose exec app php bin/console doctrine:migrations:migrate` runs clean
- [ ] `docker compose exec app php bin/console messenger:consume --limit=1` works
- [ ] `docker compose exec app vendor/bin/phpunit` runs the kernel boot test and passes
- [ ] Tailwind CSS compiles (check `php bin/console tailwind:build`)
- [ ] Visiting `http://localhost` shows the base Twig template
- [ ] All config files are PHP (no YAML in `config/packages/`)

## What Comes Next

Stage 2 will add the core infrastructure: EntityWithEvents trait, DomainEventsSubscriber, IdentityProvider service, and the test bootstrap with database caching. The project skeleton from this stage must be solid — everything else builds on it.
