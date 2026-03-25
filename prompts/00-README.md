# Sendvery — Build Prompts for Claude Code

## How to Use

Each numbered file is a self-contained prompt for a fresh Claude Code session. Between stages, **clear context** (`/clear` or start a new session). Each prompt tells Claude Code what to read, what's already done, and what to build next.

**Workflow per stage:**
1. Start a fresh Claude Code session in the project directory
2. Paste the prompt from the next numbered file
3. Let Claude Code work through it (it will read docs, write code, run tests)
4. Verify the result (tests pass, app runs, functionality works)
5. Commit the changes: `git add -A && git commit -m "Stage N: <description>"`
6. Clear context, move to the next stage

**Important:** Each prompt is designed to be pasted AS-IS. Don't modify them. They contain the exact instructions Claude Code needs, including which docs to read and what conventions to follow.

## Stages Overview

| # | Stage | Phase | What Gets Built |
|---|-------|-------|-----------------|
| 01 | Project Scaffolding | Setup | Symfony 8 skeleton, Docker (FrankenPHP + Postgres), Tailwind 4, daisyUI, AssetMapper, PHPUnit + Infection config, CI-ready |
| 02 | Core Infrastructure | Setup | EntityWithEvents + HasEvents trait, DomainEventsSubscriber, IdentityProvider, test bootstrap (DAMA + DB caching), base test helpers |
| 03 | Identity & Multi-Tenancy | Setup | Team, User, TeamMembership entities, Doctrine team filter, security voters, role enum |
| 04 | Landing Page & Layout | 0A | Marketing layout, hero section, base Twig components, responsive nav, footer with SEO links, dark mode |
| 05 | DNS Tools & SEO Pages | 0A | DNS checker service (SPF/DKIM/DMARC/MX), all 8 tool pages with interactive checkers, SEO meta, structured data, FAQ schema |
| 06 | Beta Signup & Knowledge Base | 0A | BetaSignup entity + form + confirmation email, Knowledge Base routes + layout + first 2-3 guides, sitemap.xml, robots.txt |
| 07 | DMARC Report Parsing | 0B | MonitoredDomain entity, DmarcReport + DmarcRecord entities, XML parser service, value objects (DmarcPolicy, etc.), CLI summary command |
| 08 | Email Ingestion | 0B | MailboxConnection entity, IMAP/POP3 client service, polling command (Scheduler), attachment extraction (zip/gzip), report processing pipeline via Messenger |
| 09 | Personal Dashboard | 0B | Dashboard layout (sidebar + main), domain overview, DMARC pass/fail charts (ApexCharts), sender breakdown table, report list + detail views |
| 10 | Auth & Onboarding | 1 | Magic link authentication, user registration flow, onboarding wizard (add domain → choose ingestion → verify DNS), session management |
| 11 | DNS Monitoring & Alerts | 1 | DnsCheckResult entity, scheduled DNS checks (Scheduler), change detection, Alert entity + alerting engine, email notifications for critical alerts |
| 12 | Weekly Digest & Beta Polish | 1 | Non-AI weekly digest email, IMAP credential encryption (Halite), beta user invitation system, in-app feedback mechanism, error states + empty states |
| 13 | Stripe Billing | 2 | Stripe integration (subscriptions, checkout, webhooks, customer portal), plan enforcement (domain limits, feature flags per tier), billing settings page |
| 14 | Advanced Features & Launch Prep | 2 | Sender inventory/discovery, blacklist monitoring, domain health score (A-F grade), PDF export, public GitHub repo prep, Docker Hub image, deployment docs |

## Prerequisites

Before starting Stage 01, ensure:
- Docker Desktop or Docker Engine is installed
- Git is initialized in the project directory
- The `docs/` directory contains all 11 planning documents
- `CLAUDE.md` is in the project root

## State Tracking

After each stage completes, the prompt for the next stage tells Claude Code exactly what exists. This is how continuity is maintained across cleared contexts.

## Modifying Stages

If you need to add features or change scope mid-build:
1. Update the relevant `docs/` files
2. Update `CLAUDE.md` if architecture conventions change
3. Modify the next unstarted prompt to reflect changes
4. Never modify prompts for stages already completed
