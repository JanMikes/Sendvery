# Sendvery

**Email health & deliverability monitoring. DMARC report parsing with AI-powered insights.**

Open source (AGPL-3.0). Self-hosted always free.

## Features

- **DMARC Report Parsing** — Parse aggregate reports from IMAP mailboxes automatically
- **DNS Monitoring** — Track SPF, DKIM, DMARC, MX record changes with instant alerts
- **Sender Inventory** — Auto-discover all services sending email as your domain
- **Blacklist Monitoring** — Daily checks against Spamhaus, Barracuda, SORBS, and more
- **Domain Health Score** — A-F grade with per-category breakdown, shareable public URL
- **PDF Reports** — Export comprehensive domain health reports
- **Alerting** — Get notified about DNS changes, new unknown senders, failure spikes
- **Weekly Digest** — Summary of your email authentication status
- **Free DNS Tools** — SPF, DKIM, DMARC, MX checkers, and blacklist lookups
- **REST API** — API Platform-powered endpoints for programmatic access

## Tech Stack

- PHP 8.5 + Symfony 8.0
- FrankenPHP (built-in Caddy)
- PostgreSQL 16
- Tailwind CSS 4 + daisyUI + ApexCharts
- Stimulus + Turbo (Hotwire)
- Docker

## Self-Hosting

### Quick Start with Docker Compose

```bash
git clone https://github.com/janmikes/sendvery.git
cd sendvery
cp .env .env.local
# Edit .env.local with your settings (DATABASE_URL, MAILER_DSN, etc.)
docker compose -f compose.production.yaml up -d
```

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `DATABASE_URL` | PostgreSQL connection string | Yes |
| `APP_SECRET` | Symfony secret (generate random) | Yes |
| `MAILER_DSN` | Email sending DSN (smtp://...) | Yes |
| `DEFAULT_URI` | Your app's public URL | Yes |
| `ENCRYPTION_KEY` | Key for IMAP credential encryption | Yes |
| `STRIPE_SECRET_KEY` | Stripe API key (optional for self-hosted) | No |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook secret | No |
| `SENTRY_DSN` | Sentry error tracking DSN | No |

### Database Setup

```bash
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

### Running Workers

DMARC report processing happens asynchronously via Symfony Messenger:

```bash
docker compose exec app bin/console messenger:consume async --time-limit=3600
```

## Development Setup

### Prerequisites

- Docker and Docker Compose
- PHP 8.5 (for IDE support, optional)

### Getting Started

```bash
git clone https://github.com/janmikes/sendvery.git
cd sendvery
docker compose up -d
docker compose exec app composer install
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

### Running Tests

```bash
docker compose exec app vendor/bin/phpunit
```

### Code Quality

```bash
docker compose exec app vendor/bin/phpstan
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec app vendor/bin/infection
```

## Hosted Version

Don't want to self-host? Use the hosted version at [sendvery.com](https://sendvery.com):

- **Free** — 1 domain, basic features
- **Personal** ($5.99/mo) — 5 domains, alerts, blacklist monitoring, PDF reports
- **Team** ($49.99/mo) — 50 domains, 10 members, API access, AI insights included

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

AGPL-3.0. See [LICENSE](LICENSE) for the full text.

The software is free and open source. The hosted version sells managed infrastructure, automatic updates, and support.
