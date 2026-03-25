# Decisions Log

**Last updated:** 2026-03-24

Track key decisions, their rationale, and any alternatives considered.

---

## Decision Format

```
### DEC-XXX: [Title]
**Date:** YYYY-MM-DD
**Status:** Decided / Revisiting / Superseded
**Decision:** What was decided
**Rationale:** Why
**Alternatives considered:** What else was on the table
**Impact:** What this affects
```

---

### DEC-001: Personal-first approach
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Build for personal use first (POC), then expand to MVP for others.
**Rationale:** Scratching own itch ensures the tool is genuinely useful. Avoids premature optimization for multi-tenant/billing before validating the core value.
**Alternatives considered:** Jump straight to SaaS MVP; fork parsedmarc
**Impact:** Simplifies initial architecture (no auth, single user, SQLite)

### DEC-002: Two ingestion methods
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Support both IMAP (user provides credentials) and dedicated receiving address (user sets rua= to our domain).
**Rationale:** IMAP is better for personal/self-hosted use and doesn't require DNS changes. Dedicated address is lower friction for SaaS users.
**Alternatives considered:** IMAP only; receiving address only; Gmail/Microsoft Graph API
**Impact:** Need to build two ingestion pipelines (but IMAP is needed for POC anyway)

### DEC-003: AI as core differentiator
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Use Anthropic Claude API as the AI layer for analysis and recommendations.
**Rationale:** No competitor offers AI-powered DMARC analysis. Jan has Anthropic subscription. Claude can turn XML data into actionable plain-English insights.
**Alternatives considered:** No AI (just dashboards); OpenAI; local LLM
**Impact:** Ongoing API cost per analysis, dependency on Anthropic API availability

### DEC-004: Docker + self-hosted deployment
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Always run in Docker, deployed on self-hosted infrastructure.
**Rationale:** Full control, no cloud vendor lock-in, aligns with personal-first approach.
**Alternatives considered:** PaaS (Railway, Fly.io), bare metal, Kubernetes
**Impact:** Need Docker Compose setup from day one. Simplifies deployment story.

### DEC-005: Hosted mail via Seznam Email Profi
**Date:** 2026-03-24
**Status:** Decided
**Decision:** For option 2 (our hosted mail), use Seznam Email Profi as the mail provider. We connect to it via IMAP — no need to run our own mail server.
**Rationale:** Avoids complexity of running MX/Postfix/Dovecot. Seznam Profi is a reliable Czech email provider. Both ingestion options end up being IMAP under the hood.
**Alternatives considered:** Self-hosted Postfix+Dovecot; Migadu; Mailcow; cloud MX
**Impact:** Simplifies architecture — both paths are "connect to IMAP, download reports." Reduces ops burden significantly.

### DEC-006: Secure secrets storage is critical
**Date:** 2026-03-24
**Status:** Decided (implementation TBD)
**Decision:** User IMAP/POP3 credentials must be stored with strong encryption at rest.
**Rationale:** We're asking users to trust us with mailbox access. A credential leak would be catastrophic.
**Alternatives considered:** N/A — this is a hard requirement
**Impact:** Need to choose encryption approach (application-level encryption, Vault, SOPS, or similar). Affects database schema and deployment.

### DEC-007: AI features deferred to later phase
**Date:** 2026-03-24
**Status:** Decided
**Decision:** AI-powered analysis via Claude API is a later feature, not in POC or initial MVP.
**Rationale:** Focus POC on core parsing and visibility first. AI adds complexity and cost. Can be layered on once the data pipeline works.
**Alternatives considered:** AI from day one
**Impact:** Simplifies POC scope. AI becomes a paid-tier differentiator later.

### DEC-008: Symfony monolith with API Platform
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Symfony 7 monolith with API Platform for the user-facing API. Twig for frontend (exact JS approach TBD — HTMX or Stimulus/Turbo).
**Rationale:** Jan is extremely skilled with Symfony, which is critical for reviewing vibecoded output. Monolith keeps everything in one repo and one test suite. API Platform provides a future user-facing API with minimal effort. Symfony's testing story is excellent for the 100% coverage requirement.
**Alternatives considered:** Next.js full-stack (can't review as confidently), Symfony API + separate SPA (two things to test), Laravel
**Impact:** Single repo, single test suite, single deployment. Frontend testing is simpler with server-rendered templates.

### DEC-009: 100% test coverage as business specification
**Date:** 2026-03-24
**Status:** Decided
**Decision:** 100% test coverage is mandatory. Tests describe business requirements. PHPUnit coverage enforcement in CI.
**Rationale:** Project is fully vibecoded — tests are the safety net and the specification. Without Jan writing the code himself, tests are the primary way to verify correctness. Test-first workflow: write requirement as test → vibe-code implementation → verify.
**Alternatives considered:** N/A — this is a hard requirement
**Impact:** Affects development workflow fundamentally. Every feature starts with a test. CI blocks on coverage < 100%. May also use mutation testing (Infection) for quality beyond line coverage.

### DEC-010: PostgreSQL from the start
**Date:** 2026-03-24
**Status:** Decided
**Decision:** PostgreSQL 16 as the only database, also used for Messenger queue transport.
**Rationale:** Avoids SQLite limitations. PG handles concurrent access, Messenger's Doctrine transport, and scales to multi-tenant. One service in Docker Compose instead of PG + Redis.
**Alternatives considered:** SQLite for POC (too limited), Redis for queues (premature, Messenger is transport-agnostic)
**Impact:** Docker Compose includes PostgreSQL. Messenger uses Doctrine transport. Can add Redis later if needed without code changes.

### DEC-011: External SMTP via Seznam Email Profi
**Date:** 2026-03-24
**Status:** Decided
**Decision:** All outbound email (digests, alerts, transactional) sent via Symfony Mailer through Seznam Email Profi SMTP. No self-hosted mail sending.
**Rationale:** Seznam Profi is already used for the hosted IMAP mailbox. Using it for SMTP too means one provider for all email. No reputation/deliverability concerns of self-hosted SMTP.
**Alternatives considered:** Self-hosted Postfix, Amazon SES, Mailgun, SendGrid
**Impact:** Simple Mailer DSN config. No mail server to maintain. Single provider for both sending and receiving.

### DEC-012: Stripe for billing
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Stripe for all payment processing — subscriptions, checkout, customer portal.
**Rationale:** Industry standard, excellent API, handles SCA/PSD2, customer portal for self-service billing management.
**Alternatives considered:** Paddle (handles EU VAT), LemonSqueezy
**Impact:** Need Stripe webhook handler, subscription lifecycle management, plan enforcement logic.

### DEC-013: Multi-language ready from day one, English only at launch
**Date:** 2026-03-24
**Status:** Updated
**Decision:** Use Symfony Translation component from the start. All user-facing strings in translation files, ICU message format. But launch with **English only** — add Czech and others later based on demand.
**Rationale:** The infrastructure for i18n is cheap to set up with Symfony (just use translation keys everywhere). But actually translating all content takes time. English covers 90%+ of the target market. Czech can be added when/if needed.
**Alternatives considered:** English only (no i18n infra), full CZ+EN launch
**Impact:** All templates use translation keys from day one (easy to add languages later), but only English translation files need to be complete at launch.

### DEC-014: Sentry for error tracking
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Sentry for error tracking and monitoring.
**Rationale:** Standard for PHP/Symfony apps. Self-hosted Sentry is an option if cost is a concern. Critical for vibecoded project — need visibility into runtime errors.
**Alternatives considered:** Self-hosted Sentry, Bugsnag, logging only
**Impact:** Sentry SDK in Symfony, DSN in environment config.

### DEC-015: Open source with free self-hosted tier
**Date:** 2026-03-24
**Status:** Decided
**Decision:** The project is open source. Self-hosted is always free. Paid tiers are for the hosted/managed version only.
**Rationale:** Open source builds trust — critical when users give you IMAP credentials (they can audit the code). Free self-hosted is a strong marketing signal and lowers the barrier to adoption. Community contributions improve the product. Paid tiers sell convenience (hosting, managed infrastructure, support), not the software itself.
**Alternatives considered:** Closed source; open core (parser open, UI closed); source-available (BSL/SSPL)
**Impact:** Need to choose a license (MIT, AGPL, Apache 2.0?). Revenue comes from hosted service, not software licensing. Docker Hub image for easy self-hosting. Documentation must be good enough for self-hosters.

### DEC-016: Multi-tenant / teams architecture from day one
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Build the data model with teams/organizations as a first-class concept from the start. Every entity (domain, report, mailbox, alert config) belongs to a team. A user belongs to one or more teams with a role.
**Rationale:** Retrofitting multi-tenancy is one of the most painful refactors in any SaaS. Doing it from the start costs very little extra (a few foreign keys and a middleware/event listener for scoping queries). Critical for agency tier and future team features.
**Alternatives considered:** Single-user first, add teams later (cheaper to start but expensive to retrofit)
**Impact:** Affects the entire data model. Every Doctrine entity needs a `team` relation. API Platform needs team-scoped filtering. Symfony security voters need team-aware authorization. But this is straightforward in Symfony and prevents a massive migration later.

### DEC-017: AGPL-3.0 license
**Date:** 2026-03-24
**Status:** Decided
**Decision:** License the project under AGPL-3.0.
**Rationale:** AGPL requires anyone who modifies and hosts the software as a service to also open-source their changes. Prevents competitors from taking the code and offering a competing hosted service without contributing back. Standard choice for open-source SaaS (Sentry, GitLab, Grafana all use AGPL or similar). Doesn't limit self-hosting at all.
**Alternatives considered:** MIT (too permissive for SaaS), Apache 2.0 (same issue), BSL/SSPL (not truly open source)
**Impact:** Add LICENSE file. All source files get AGPL header. Doesn't affect users or self-hosters — only affects competitors who want to host it as a service.

### DEC-018: Stimulus + Turbo (Hotwire) for frontend
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Use Symfony UX with Stimulus and Turbo (Hotwire) for frontend interactivity on top of Twig templates.
**Rationale:** Symfony's official recommendation, great integration via Symfony UX. Server-rendered with just enough JS for interactivity. Keeps testing simple — most logic stays server-side.
**Alternatives considered:** HTMX (simpler but less Symfony ecosystem support), SPA (overkill for this)
**Impact:** Symfony UX Turbo + Stimulus bundles. AssetMapper or Webpack Encore for asset pipeline.

### DEC-019: Hetzner dedicated server (Ubuntu)
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Self-hosted on Hetzner dedicated server running Ubuntu.
**Rationale:** Cost-effective, EU-based (GDPR friendly), full control, CZ-adjacent datacenter options.
**Alternatives considered:** Hetzner VPS, cloud PaaS, homelab
**Impact:** Need Docker + Docker Compose on Ubuntu. Possibly Traefik or Caddy as reverse proxy with Let's Encrypt.

### DEC-020: GitHub + GitHub Actions for CI/CD
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Host code on GitHub. Use GitHub Actions for CI (test suite, coverage enforcement, static analysis).
**Rationale:** Free for public repos (AGPL). Standard for open-source. Good ecosystem for PHP/Symfony workflows.
**Alternatives considered:** GitLab CI (self-hostable but unnecessary complexity)
**Impact:** Need GitHub Actions workflow: PHPUnit with coverage, PHPStan/Psalm, CS fixer. Coverage must be enforced at 100%.

### DEC-021: Project name — Sendvery
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Project name is "Sendvery". GitHub repo: sendvery. Target domains: sendvery.com, sendvery.io (to be verified/purchased).
**Rationale:** "Send" + "delivery" mashup. Broad enough for a full email health platform (not limited to DMARC). No existing company or product found. Short, brandable, easy to pronounce internationally.
**Alternatives considered:** Mailivo, AuthMail, Delivra (taken), Mailivery (taken), MailPulse, various others
**Impact:** Repo name, package name, Docker image name, branding, domain purchase needed.

### DEC-022: PHP 8.5, Symfony 8, FrankenPHP
**Date:** 2026-03-24
**Status:** Decided (supersedes parts of DEC-008)
**Decision:** Use PHP 8.5, Symfony 8.0 (upgrade to 8.1 when available May 2026), and FrankenPHP as the application server. Replaces the earlier PHP 8.3+/Symfony 7/PHP-FPM+Nginx plan.
**Rationale:**
- **PHP 8.5** — stable since Nov 2025 (currently 8.5.4). Includes pipe operator, clone-with, new URI parser. Latest and greatest.
- **Symfony 8.0** — released Nov 2025, requires PHP 8.4+. Currently at 8.0.7. Symfony 8.1 drops May 2026, 8.4 LTS in Nov 2027.
- **FrankenPHP** — production-ready, 3x throughput vs PHP-FPM for Symfony apps. Worker mode keeps Symfony booted in memory. Built on Caddy (free HTTPS, no separate Nginx needed). Official Symfony integration. SymfonyLive Paris 2026 featured deep DX integration.
**Alternatives considered:** PHP-FPM + Nginx (traditional but slower), Swoole/OpenSwoole (less Symfony integration), RoadRunner
**Impact:** Simpler Docker setup (no separate Nginx container). Built-in HTTPS via Caddy. Worker mode requires careful attention to memory leaks and service resets between requests. No need for separate Caddy/Nginx decision — FrankenPHP IS the web server.

### DEC-023: Traefik as reverse proxy (existing infra)
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Traefik is already running on the Hetzner server as the reverse proxy for all services. Sendvery sits behind Traefik. FrankenPHP's built-in Caddy only serves HTTP internally — Traefik handles TLS termination, Let's Encrypt, and routing.
**Rationale:** Existing infrastructure. All services on the server already use Traefik. No reason to change this pattern.
**Alternatives considered:** N/A — this is existing infra
**Impact:** FrankenPHP listens on HTTP only (port 80 internally). Docker Compose uses Traefik labels for routing. No Caddy TLS config needed. Caddy data/config volumes can be removed from Docker Compose.

### DEC-024: Pricing model finalized
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Four hosted tiers + self-hosted free. Free (1 domain, solo), Personal $5.99/mo (5 domains, solo), Team $49.99/mo (50 domains, 10 members), Enterprise (custom). Add-ons: $1/domain/mo, $2/seat/mo.
**Rationale:** Simple, predictable pricing. Per-domain model (not per-volume) is easier to understand. Add-ons give flexibility without tier explosion. Gap between Personal and Team is intentional — may add mid-tier later based on demand data.
**Alternatives considered:** Per-volume pricing, hybrid per-domain+volume
**Impact:** Stripe Products & Prices structure defined. Need plan enforcement logic in Symfony (domain count, seat count, feature flags per plan).

### DEC-025: AI as opt-in add-on, not default
**Date:** 2026-03-24
**Status:** Decided (supersedes AI parts of DEC-024)
**Decision:** AI analysis is a paid add-on ($3.99/mo) for Personal tier. Included for free in Team and Enterprise. Not available on Free tier. Self-hosted users get AI if they provide their own Anthropic API key.
**Rationale:** Keeps base price ($5.99) as low as possible for aggressive competitive positioning ("cheapest DMARC monitoring"). AI has per-use API costs — making it opt-in means only paying users cover those costs. Creates a natural upsell funnel: Free → Personal → Personal + AI → Team (AI included). Future flexibility: can fold AI into all paid tiers when API costs drop, creating a positive "we upgraded your plan for free" moment.
**Alternatives considered:** AI included in all paid tiers (higher base price needed), AI as separate standalone product
**Impact:** Need feature flag for AI per team. Stripe add-on product. Blurred AI preview as upsell teaser in UI. Self-hosted config option for own API key.

### DEC-026: Fake door landing page → closed beta → public launch
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Deploy a landing page with real DNS checker tool + beta signup ASAP. Build personal-use tool in parallel. Invite beta users from email list. Add billing, AI, teams incrementally.
**Rationale:** Start SEO indexing early (compounds over time). DNS checker provides immediate value and attracts organic traffic. Beta signup validates demand before building billing/teams/AI. Zero pressure to ship everything at once.
**Alternatives considered:** Build everything then launch (slow, no validation), launch MVP directly (no SEO runway)
**Impact:** Phase 0A (landing page) becomes the first thing to ship. Decouples marketing timeline from product timeline. Email list becomes warm leads for launch.

### DEC-027: Two distinct designs (marketing + dashboard)
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Two separate design languages sharing a color palette/brand: (1) Landing page / marketing — distinctive, SEO-focused, slightly playful, interactive elements (live DNS checker, animations). Must NOT look like generic Tailwind template. (2) Dashboard / admin — clean, data-dense, functional, Tailwind + headless components. Both use Tailwind CSS but with different layouts and personality.
**Rationale:** Landing page needs to sell and differentiate. Dashboard needs to be efficient and functional. Trying to make one design do both leads to mediocrity.
**Alternatives considered:** Single design system for both, buy premium template
**Impact:** Need brand identity (colors, logo, typography) before detailed design. Logo to be created by modifying/combining existing SVGs into something unique. Dashboard uses Tailwind + headless components (Twig). Landing page is custom Tailwind with distinctive elements.

### DEC-028: Separate SEO-optimized tool pages for SPF, DKIM, and DMARC
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Create dedicated standalone pages for each DNS check tool: SPF Checker, DKIM Checker, DMARC Checker (plus a combined "Email Auth Checker"). Each page targets specific keywords, has its own content/explainer, and acts as an independent SEO landing page with its own conversion funnel.
**Rationale:** People search for specific checks ("check my SPF record", "DKIM lookup", "DMARC analyzer"). Separate pages capture each keyword cluster independently and rank better than one combined tool page. Each page becomes its own lead generation funnel. This is exactly what MXToolbox, dmarcian, and EasyDMARC do — and they rank well for it.
**Alternatives considered:** Single combined DNS checker page (misses specific keyword targeting)
**Impact:** 8 tool pages total: SPF Checker, DKIM Checker, DMARC Checker, Email Auth Checker, DNS Monitoring, MX Checker, Blacklist Checker, Domain Health (see DEC-031). Each needs unique content, meta tags, structured data. Same underlying DNS/network checking services, different presentation. Massive SEO surface area.

---

### DEC-029: Security-first content strategy for tool pages
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Every tool page follows a three-part narrative: educate → reveal risk → offer solution. Content highlights specific attack vectors, the "set and forget" trap, and concrete scenarios (not abstract FUD). Tone is expert and empowering, not fear-mongering.
**Rationale:** Security content demonstrates domain expertise, builds trust, and creates urgency for monitoring (the paid product). Visitors who understand the risks convert better than those who just run a one-time check. Competitors show results but don't explain *why it matters* — this is a content gap we fill.
**Alternatives considered:** Generic tool pages with just results (misses the education and conversion angle)
**Impact:** Each tool page needs ~500-800 words of security-focused content. Increases page depth and dwell time (good for SEO). Creates natural funnel from free tool → monitoring subscription.

---

### DEC-030: Knowledge Base instead of blog
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Replace the blog concept with a Knowledge Base (`/learn/*` routes) — evergreen, static educational pages. No publishing dates, no cadence, no "posted on". Write once, update as needed.
**Rationale:** A blog creates pressure to publish regularly. Stale blogs with "last post: 6 months ago" actively hurt credibility. A Knowledge Base with 5-7 evergreen guides ranks just as well for long-tail SEO without the maintenance burden. The tool pages themselves are the primary SEO surface area — the knowledge base supplements them.
**Alternatives considered:** Traditional blog (requires ongoing content creation), no written content (misses long-tail SEO)
**Impact:** Simpler content strategy. 5-7 guides written at launch, updated occasionally. No blog engine needed — just Twig templates. `/learn/*` routes.

---

### DEC-031: Expanded tool pages (MX Checker, Blacklist Checker, Domain Health)
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Add 3 more free tool pages to Phase 0A: MX Checker (`/tools/mx-checker`), Blacklist Checker (`/tools/blacklist-checker`), and Domain Health Report Card (`/tools/domain-health`). Total: 8 tool pages.
**Rationale:** These are standard offerings from competitors (MXToolbox, dmarcian). Low implementation cost (reuse DNS/network checking infrastructure). Each captures its own keyword cluster. The Domain Health page is particularly valuable — aggregate A-F score is shareable, drives repeat visits, and naturally funnels to paid monitoring. Blacklist checking has high urgency (people search for it when their email is already bouncing).
**Alternatives considered:** Keep only 5 tool pages (misses easy SEO surface area for tools we'll build anyway)
**Impact:** 3 additional pages to build. MX checker needs SMTP connect test + TLS cert check. Blacklist checker needs queries to major DNSBLs. Domain Health aggregates all other checks into a single score. All feed into the same conversion funnel.

---

### DEC-032: Landing page trust elements using Jan's own companies
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Use logos from Jan's own businesses as the "Used by" social proof bar on the landing page. Supplement with: live tools as trust builders, open source badge, technology/security badges, founder story, FAQ section. No fake testimonials — add real ones from beta users when available.
**Rationale:** Real logos (even from the founder's own companies) are more trustworthy than none. The interactive tools themselves are the strongest trust signal — they prove the product works. Combined with the security-expert content, open source transparency, and technical credibility (encryption details, tech stack), the landing page builds trust through demonstrated competence rather than social proof we don't have yet.
**Alternatives considered:** No logos until external customers (looks empty), fake/placeholder testimonials (destroys trust if discovered)
**Impact:** Need to collect logos from Jan's companies. Landing page structure defined as 14-section layout. Each section serves a specific trust-building function.

---

### DEC-033: No lifetime deals
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Monthly and annual subscriptions only. No lifetime deals.
**Rationale:** Lifetime deals create future liability. At $5.99/mo, a $99 lifetime deal would take 17 months to pay back — and the user stays forever for free after that. Not worth the complexity or the future cost.

---

### DEC-034: OAuth2 for Gmail/Microsoft from the start
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Implement OAuth2 authentication for Gmail and Microsoft 365 from the start, alongside traditional IMAP password auth. Requires registering Sendvery as an OAuth app with Google and Microsoft.
**Rationale:** Gmail restricts "less secure app" access. Many users (especially Gmail and Microsoft 365) can't use plain IMAP passwords easily. Jan manages 4-10 domains across multiple providers including Gmail/Microsoft. OAuth2 is necessary for good UX and broad provider coverage.
**Impact:** Need to register OAuth apps with Google Cloud Console and Microsoft Azure AD. OAuth2 flow implementation. Token storage and refresh logic. More complex but necessary.

---

### DEC-035: Magic link authentication only
**Date:** 2026-03-24
**Status:** Decided
**Decision:** User authentication via magic link (email-based login) only. No passwords.
**Rationale:** Simpler to build (no password hashing, reset flows, or security concerns). More secure (no password database to breach). Users log in infrequently (the digest email IS the product), so magic link UX is fine. Low-frequency-login tools don't need password convenience.
**Impact:** Login flow: enter email → receive link → click → logged in. Session-based auth with long-lived sessions. No password storage, no password reset, no password requirements.

---

### DEC-036: English only at launch, i18n infrastructure ready
**Date:** 2026-03-24
**Status:** Decided (updates DEC-013)
**Decision:** Launch with English only. Symfony Translation component set up from day one (all strings in translation files), but only English translations need to be complete. Czech and other languages added later based on demand.
**Rationale:** English covers 90%+ of the target market. Translation takes time. Infrastructure-ready means adding a language later is just creating a new translation file, not refactoring code.

---

### DEC-037: Support DMARC forensic reports (ruf) with PII redaction
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Support parsing DMARC forensic reports (ruf). Redact or hash PII fields (email addresses, subjects) before storage. Show failure details without storing raw personal data.
**Rationale:** Forensic reports provide valuable failure detail that aggregate reports don't. PII redaction addresses GDPR concerns. Even though most providers don't send ruf, the ones that do provide actionable debugging info.
**Impact:** Parser needs to handle ruf XML format. PII redaction logic. Privacy policy must document ruf handling. Storage of hashed/redacted forensic data.

---

### DEC-038: Google Analytics + Google Search Console for analytics
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Use Google Analytics for page-level traffic analytics and Google Search Console for SEO/search performance monitoring.
**Rationale:** Standard, free, familiar. GA provides traffic and conversion data. GSC shows which queries bring traffic and how tool pages rank. No need for privacy-focused alternatives at this scale.

---

### DEC-039: POP3 support required alongside IMAP
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Support POP3 as an ingestion method alongside IMAP. Some users may have DMARC reports in POP3-only mailboxes.
**Rationale:** POP3 is still used, especially on older hosting providers. Adding POP3 support widens the user base at relatively low additional complexity (most IMAP libraries also support POP3).
**Impact:** IMAP/POP3 library choice must support both protocols. See 10-libraries-and-tools.md for updated comparison.

---

### DEC-040: No dashboard template, custom UX-focused build
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Build the dashboard UI from scratch with Tailwind CSS + Twig components. No purchased or adapted template. Focus on clean UX, information density, and usability.
**Rationale:** Templates create dependency and often need heavy customization anyway. Vibecoding can produce a clean dashboard from scratch with specific UX requirements. daisyUI provides component primitives, the rest is custom.

### DEC-041: CQRS pattern for commands and queries
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Use CQRS (Command Query Responsibility Segregation). Commands are readonly final classes in `src/Message/`, dispatched via Symfony Messenger. Queries are readonly final classes in `src/Query/` that use DBAL Connection directly with raw SQL, returning result DTOs from `src/Results/`. Handlers in `src/MessageHandler/` use `#[AsMessageHandler]`.
**Rationale:** Clean separation of write and read paths. Commands go through Doctrine ORM for consistency. Queries bypass ORM for performance and flexibility — no lazy loading surprises, exact SQL control. Pattern proven in production at myspeedpuzzling.com.
**Alternatives considered:** Repository-only pattern, API Platform resource classes for reads
**Impact:** Directory structure, all data flow patterns, testing approach

### DEC-042: Strongly typed PHP 8.5 conventions
**Date:** 2026-03-24
**Status:** Decided
**Decision:** All classes are `readonly final` by default. Public properties over getters. Constructor promotion everywhere. Value objects over primitive types for domain concepts. Enums for finite sets. Immutable by default. No `mixed`, no `array<mixed>` — always typed. DTOs are readonly final with public properties.
**Rationale:** PHP 8.5 has full readonly class support. Strict typing catches bugs at compile time, makes code self-documenting, and enables better IDE support. Immutability prevents accidental state mutation.
**Alternatives considered:** Traditional getter/setter pattern, less strict typing
**Impact:** All PHP code in the project

### DEC-043: Single-action controllers with __invoke()
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Every controller has exactly one public method: `__invoke()`. One route = one controller class. No multi-action controllers.
**Rationale:** Single Responsibility Principle. Each controller is small, testable, and has a clear purpose. Avoids fat controllers. Works naturally with Symfony's `#[AsController]` and route attributes.
**Alternatives considered:** Traditional multi-action controllers
**Impact:** All HTTP controllers, routing configuration

### DEC-044: IdentityProvider service for UUID v7
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Always use `Uuid::uuid7()` for new entity IDs, wrapped in an `IdentityProvider` service (`->nextIdentity()`). Caller generates the ID upfront and passes it into commands. Never let the database or ORM generate IDs.
**Rationale:** UUID v7 is time-ordered (good for DB indexing). IdentityProvider wrapper enables test mocking — tests can predict IDs. Caller-generated IDs mean the command dispatcher knows the ID before the handler runs, enabling optimistic responses and event correlation.
**Alternatives considered:** Auto-increment IDs, UUID v4, database-generated IDs
**Impact:** All entity creation, all commands, test infrastructure

### DEC-045: Domain events via EntityWithEvents pattern
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Entities implement `EntityWithEvents` interface with `HasEvents` trait. Events are recorded on the entity during business logic, then collected and dispatched by a `DomainEventsSubscriber` Doctrine listener after flush. Events go through Symfony Messenger for async processing.
**Rationale:** Domain events decouple side effects (emails, notifications, audit logs) from core business logic. Dispatching after flush ensures events only fire when data is persisted. Pattern proven at myspeedpuzzling.com.
**Alternatives considered:** Direct service calls for side effects, Doctrine lifecycle events
**Impact:** All entities with side effects, event handling infrastructure

### DEC-046: Symfony 8 PHP configuration with App::config()
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Use Symfony 8's native PHP configuration files exclusively. No YAML config. Use `App::config()` syntax in `config/packages/*.php` files.
**Rationale:** PHP config is type-safe, IDE-autocomplete friendly, and the direction Symfony is moving. No YAML parsing overhead. Consistent with Symfony 8 defaults.
**Alternatives considered:** YAML configuration (Symfony traditional)
**Impact:** All Symfony configuration files

### DEC-047: Docker base image from thedevs-cz/php8.5
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Use `ghcr.io/thedevs-cz/php8.5:latest` as the Docker base image. Reference Dockerfile and compose.yaml patterns from fajnesklady.cz repository.
**Rationale:** Jan maintains this image — it's pre-configured with PHP 8.5, common extensions, and FrankenPHP. Consistent with other projects. Known working configuration.
**Alternatives considered:** Official PHP Docker images, custom Dockerfile from scratch
**Impact:** Docker setup, CI pipeline, deployment

### DEC-048: Testing conventions (DAMA, bootstrap caching, Infection)
**Date:** 2026-03-24
**Status:** Decided
**Decision:** Use DAMA DoctrineTestBundle for transaction-wrapping tests (each test rolls back). Bootstrap caches the test database via `TestingDatabaseCaching.php` pattern (hash migrations + fixtures, rebuild only on change). Infection mutation testing from the start. 100% test coverage is mandatory — tests ARE the business specification.
**Rationale:** DAMA makes tests fast by avoiding DB recreation. Bootstrap caching from fajnesklady.cz pattern saves even more time. Infection catches tests that pass but don't actually verify behavior. 100% coverage in a vibecoded project means the AI-generated code is verified by AI-generated tests — the tests are the safety net.
**Alternatives considered:** Separate test database per run, no mutation testing initially
**Impact:** Test infrastructure, CI pipeline, development workflow

---

*Add new decisions above this line*
