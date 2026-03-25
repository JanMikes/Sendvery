# Stage 14: Advanced Features & Launch Preparation

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions.
2. `docs/03-features-roadmap.md` — Phase 2 scope: sender inventory, blacklist monitoring, domain health score, PDF reports, GitHub public, Docker Hub, launch.
3. `docs/10-libraries-and-tools.md` — `jbboehr/dnsbl` for blacklist checking.
4. `docs/05-monetization.md` — Go-to-market: Product Hunt, HN, Reddit announcements.
5. `docs/09-design-and-branding.md` — Domain Health page spec (A-F grade, shareable URL).

## What Already Exists (Stages 1-13 completed)

- Full Symfony 8 project, all infrastructure
- Phase 0A complete (marketing, DNS tools, beta signup, Knowledge Base)
- Phase 0B complete (DMARC parsing, ingestion, dashboard)
- Phase 1 complete (auth, onboarding, monitoring, alerts, digest, beta)
- Stripe billing (subscriptions, plan enforcement, checkout)
- All tests passing

## What to Build

The differentiating Phase 2 features and everything needed for public launch.

### 1. Sender Inventory / Discovery

**Goal:** Auto-map all services sending email as the user's domain. Show which are authorized (pass) and which are unknown (fail).

**`src/Entity/KnownSender.php`:**
- `id` (UUID v7)
- `monitoredDomain` (ManyToOne → MonitoredDomain)
- `sourceIp` (string)
- `hostname` (nullable string — reverse DNS)
- `organization` (nullable string — e.g., "Google", "Mailchimp", "SendGrid")
- `label` (nullable string — user-assigned label like "Our newsletter tool")
- `isAuthorized` (bool — user marks as known/unknown)
- `firstSeenAt` (DateTimeImmutable)
- `lastSeenAt` (DateTimeImmutable)
- `totalMessages` (int)
- `passRate` (float)

**`src/Services/SenderDiscovery.php`** — `readonly final class`:
- Processes DMARC records to build sender inventory
- Groups records by source IP
- Resolves IP to hostname (reverse DNS, cached)
- Maps hostname to organization (simple mapping table: `*.google.com` → Google, `*.sendgrid.net` → SendGrid, etc.)
- Creates/updates KnownSender entries

**`src/Services/OrganizationMapper.php`** — maps hostnames to known email service providers:
- Maintains a list of patterns: `*.google.com`, `*.outlook.com`, `*.amazonses.com`, `*.sendgrid.net`, `*.mailchimp.com`, `*.mailgun.org`, etc.
- Returns organization name or null if unknown

**Event handler:** `src/MessageHandler/UpdateSenderInventoryOnReport.php`
- Listens to `DmarcReportProcessed`
- Calls SenderDiscovery to update sender inventory

**Dashboard page:** `src/Controller/Dashboard/SenderInventoryController.php`
- Route: `/app/domains/{id}/senders`
- Table: IP, hostname, organization, authorized status, first/last seen, messages, pass rate
- User can mark senders as authorized/unauthorized
- Filter: authorized, unauthorized, all

### 2. Blacklist Monitoring

Install blacklist checking library:
```bash
composer require jbboehr/dnsbl
```

**`src/Services/BlacklistChecker.php`** — `readonly final class`:
- Input: IP address or domain
- Checks against major DNSBLs: Spamhaus (SBL, XBL, PBL), Barracuda, SORBS, SpamCop, CBL, UCEPROTECT
- Returns `BlacklistCheckResult`: listed/not listed per DNSBL, with listing reason if available

**`src/Entity/BlacklistCheckResult.php`:**
- `id` (UUID v7)
- `monitoredDomain` (ManyToOne → MonitoredDomain)
- `ipAddress` (string)
- `checkedAt` (DateTimeImmutable)
- `results` (json — per-DNSBL results)
- `isListed` (bool — true if listed on ANY blacklist)

**Scheduler:** Daily blacklist check for all sender IPs associated with monitored domains.

**Alert:** If a domain's sending IP is newly listed → create `ip_blacklisted` alert (critical).

**Dashboard page:** `src/Controller/Dashboard/BlacklistStatusController.php`
- Route: `/app/domains/{id}/blacklist`
- Shows blacklist status per IP, check history

**Update domain health tool page** (Stage 5) to include blacklist check in the public tool.

### 3. Domain Health Score (Dashboard + Public)

Extend `DomainHealthScorer` from Stage 5:
- Include blacklist status in scoring
- Include sender authorization ratio (% of traffic from authorized senders)
- Persist scores: create `DomainHealthSnapshot` entity to track score over time

**`src/Entity/DomainHealthSnapshot.php`:**
- `id` (UUID v7)
- `monitoredDomain` (ManyToOne → MonitoredDomain)
- `grade` (string A-F)
- `score` (int 0-100)
- `spfScore`, `dkimScore`, `dmarcScore`, `mxScore`, `blacklistScore` (per-category)
- `checkedAt` (DateTimeImmutable)

**Dashboard page:** `src/Controller/Dashboard/DomainHealthController.php`
- Route: `/app/domains/{id}/health`
- Show: overall grade (big, prominent), per-category scores, trend chart, recommendations
- "Share your score" — generates a public shareable URL

**Public shareable page:** `src/Controller/PublicDomainHealthController.php`
- Route: `/health/{domain}/{hash}` — hash prevents enumeration
- Shows domain health grade and summary (no sensitive data)
- "Check your own domain" CTA → tool page

### 4. PDF Report Export

**`src/Services/PdfReportGenerator.php`** — `readonly final class`:
- Generates a comprehensive PDF report for a domain
- Contents: domain health score, DMARC pass/fail trends, sender inventory, DNS status, alerts summary, recommendations
- Use a PHP PDF library (TCPDF, Dompdf, or mPDF)
- Branded with Sendvery logo and colors

```bash
composer require dompdf/dompdf
```

**`src/Controller/Dashboard/ExportDomainReportController.php`:**
- Route: `/app/domains/{id}/export/pdf`
- Generates and downloads PDF
- Requires Personal plan or higher

### 5. Public Registration (Open from Beta)

Update auth flow:
- Remove beta-only restriction
- Anyone can sign up via magic link
- New users start on Free plan (1 domain)
- Onboarding flow unchanged

Update marketing pages:
- Change "Join the beta" → "Get started free"
- Update all CTAs from beta language to public launch language

### 6. Launch Preparation — GitHub

**README.md** at project root:
- Project description, screenshots
- Self-hosting instructions (docker compose up)
- Configuration guide (env vars)
- Development setup guide
- Contributing guidelines
- License (AGPL-3.0)

**LICENSE** file: AGPL-3.0 full text

**CONTRIBUTING.md**: contribution guidelines

**.github/workflows/ci.yml** (if using GitHub Actions):
- Run tests
- Run PHPStan/Psalm (if added)
- Run Infection
- Check coding standards

### 7. Launch Preparation — Docker Hub

**Dockerfile.production** (or update existing):
- Multi-stage build
- Optimized for production (no dev dependencies, opcache configured)
- Document env vars needed
- Health check endpoint

**docker-compose.production.yml:**
- Production-ready compose file
- No exposed ports (Traefik handles routing)
- Proper restart policies
- Volume for persistent data

### 8. API Platform Foundation

**Set up API Platform** for public API (Phase 4, but foundation now):
- Configure API Platform in `config/packages/api_platform.php`
- Create API resources for: MonitoredDomain (read), DmarcReport (read), DomainHealthScore (read)
- JWT or API key authentication
- Rate limiting
- Documentation auto-generated at `/api/docs`
- Only enabled for Team plan and above

### 9. Database Migrations

Create migrations for: `known_sender`, `blacklist_check_result`, `domain_health_snapshot` tables.

### 10. Tests

**Unit tests:**
- SenderDiscovery logic
- OrganizationMapper (pattern matching)
- BlacklistChecker (mock DNSBL queries)
- DomainHealthScorer with full category coverage
- PdfReportGenerator (verify output is valid PDF)
- All new value objects, enums, Results DTOs

**Integration tests:**
- Sender inventory updates when report is processed
- Blacklist check creates results, triggers alert when listed
- Domain health snapshot persists over time
- PDF export generates downloadable file
- API Platform endpoints return correct data with auth
- Public domain health page accessible without auth

**Functional tests:**
- Sender inventory page renders
- Blacklist status page renders
- Domain health page with grade and categories
- PDF download works
- Public health page accessible
- API endpoints return JSON
- Public registration works (no beta restriction)

## Verification Checklist

- [ ] Sender inventory auto-populates from DMARC reports
- [ ] Blacklist monitoring checks IPs against major DNSBLs
- [ ] Domain health score calculates and displays correctly
- [ ] Health score trends over time (snapshots)
- [ ] Shareable public health page works
- [ ] PDF report generates with correct content
- [ ] Public registration works (no beta gate)
- [ ] README.md complete with self-hosting instructions
- [ ] Docker production image builds and runs
- [ ] API Platform endpoints work with authentication
- [ ] All CTAs updated from beta to public language
- [ ] All tests pass, 100% coverage
- [ ] **Phase 2 is complete — ready for public launch**

## Launch Checklist

After Stage 14 is complete and all tests pass:

1. Deploy to Hetzner behind Traefik
2. Verify production works end-to-end (register → add domain → connect mailbox → receive reports)
3. Push to GitHub (AGPL)
4. Publish Docker image to Docker Hub/GHCR
5. Submit to Product Hunt
6. Post on HN (Show HN)
7. Post on relevant subreddits (r/selfhosted, r/sysadmin, r/webdev)
8. Monitor Sentry for errors
9. Monitor beta signups converting to paid

## What Comes Next (Future Phases)

Phases 3 and 4 are not covered by these build prompts. They include:
- **Phase 3:** AI Insights (Claude-powered weekly digest, "explain this" feature), Teams (invitations, RBAC), Slack/webhook integrations
- **Phase 4:** White-label reports, full public API, BIMI/MTA-STS/TLS-RPT, enterprise features

These can be planned as separate build prompt series when the time comes.
