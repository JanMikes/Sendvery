# Sendvery — Project Overview

**Project name:** Sendvery
**Started:** 2026-03-24
**Status:** Brainstorming / Discovery
**Decisions made:** 48

## What is this?

A personal-first micro-SaaS for email health & deliverability, starting with DMARC report parsing and expanding into a comprehensive email authentication toolkit with AI-powered insights. Built to solve Jan's own problem first, then offered as a product. Vibecoded in one shot, designed to live on its own with minimal ongoing effort. Success = 2-5 new paying customers per month from organic SEO.

## Origin

Jan receives many DMARC reports daily and deletes them all — missing the opportunity to understand what's happening with his domain's email authentication.

## Documents

| # | Topic | File |
|---|-------|------|
| 01 | Vision & Problem Statement | [01-vision-and-problem.md](01-vision-and-problem.md) |
| 02 | Architecture & Tech Stack | [02-architecture.md](02-architecture.md) |
| 03 | Features & Roadmap (Phases 0A → 0B → 1 → 2 → 3 → 4) | [03-features-roadmap.md](03-features-roadmap.md) |
| 04 | Data Model & Protocols (SPF/DKIM/DMARC specs) | [04-data-model-protocols.md](04-data-model-protocols.md) |
| 05 | Monetization & Marketing Strategy | [05-monetization.md](05-monetization.md) |
| 06 | Competitive Landscape | [06-competitive-landscape.md](06-competitive-landscape.md) |
| 07 | Decisions Log (48 decisions) | [07-decisions-log.md](07-decisions-log.md) |
| 08 | Open Questions | [08-open-questions.md](08-open-questions.md) |
| 09 | Design & Branding | [09-design-and-branding.md](09-design-and-branding.md) |
| 10 | Libraries & Tools Research | [10-libraries-and-tools.md](10-libraries-and-tools.md) |
| 11 | Startup Essentials (SWOT, KPIs, MVP, funnel, risks) | [11-startup-essentials.md](11-startup-essentials.md) |

## Tech Stack

PHP 8.5 · Symfony 8 · FrankenPHP · PostgreSQL 16 · Tailwind CSS · Stimulus/Turbo · API Platform · Stripe · Sentry

## Key Decisions

- **Personal-first** → fake door landing page → closed beta → public launch
- **Open source** (AGPL-3.0), self-hosted always free
- **Symfony monolith** with FrankenPHP behind Traefik on Hetzner
- **Teams from day one** in the data model
- **AI as opt-in add-on** ($3.99/mo), not default — keeps base price low ($5.99)
- **Two ingestion methods:** user IMAP OR our hosted mail (Seznam Email Profi)
- **100% test coverage** mandatory — tests ARE the business spec
- **English only** at launch, i18n infrastructure ready for adding languages later
- **Magic link auth** — no passwords
- **OAuth2** for Gmail/Microsoft from the start
- **POP3 + IMAP** — both protocols supported
