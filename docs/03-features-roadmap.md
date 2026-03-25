# Features & Roadmap

**Last updated:** 2026-03-24

---

## Phase 0A — Fake Door Landing Page (deploy ASAP, in parallel with 0B)

**Goal:** Start SEO, validate demand, collect leads. Ship this FIRST.

**Scope:**
- [ ] Symfony project scaffold (PHP 8.5, Symfony 8, FrankenPHP, Postgres)
- [ ] Landing page (Twig): hero, live DNS checker, "used by" logos, problem statement, how it works, features, security expertise, domain health preview, open source callout, pricing ("coming soon"), FAQ, final CTA
- [ ] Separate tool pages with SEO-optimized content + interactive checker:
  - `/tools/spf-checker` — SPF record lookup + "What is SPF?" explainer + FAQ
  - `/tools/dkim-checker` — DKIM record lookup + "What is DKIM?" explainer + FAQ
  - `/tools/dmarc-checker` — DMARC record lookup + "What is DMARC?" explainer + FAQ
  - `/tools/email-auth-checker` — combined all-in-one check
  - `/tools/dns-monitoring` — DNS record change monitoring explainer + "enter domain to see current records" + CTA for ongoing monitoring alerts
  - `/tools/mx-checker` — MX record lookup + mail server connectivity check + TLS verification
  - `/tools/blacklist-checker` — check if domain/IP is on any major blacklists (Spamhaus, Barracuda, SORBS, etc.)
  - `/tools/domain-health` — all-in-one domain health report card (A-F grade), shareable URL, combines all checks
  - Each page: unique SEO content, structured data, internal links, beta signup CTA
- [ ] Beta signup form + BetaSignup entity (email, domain_count, pain_point)
- [ ] Confirmation email via Symfony Mailer
- [ ] Knowledge Base (Twig templates or markdown-rendered, `/learn/*` routes)
- [ ] First 2-3 evergreen SEO guides published
- [ ] Basic SEO: meta tags, OpenGraph, sitemap.xml, robots.txt
- [ ] Deploy to Hetzner behind Traefik

**Not in scope:** User accounts, DMARC parsing, dashboards, billing

**Success criteria:** Pages indexed by Google, DNS tools driving organic traffic, beta emails collecting.

---

## Phase 0B — Personal Use (build in parallel with 0A)

**Goal:** Stop deleting DMARC reports. Get personal visibility into email auth.

**Scope:**
- [ ] IMAP connection to personal mailbox
- [ ] Download and unzip/ungzip DMARC report attachments
- [ ] Parse aggregate report XML (RFC 7489)
- [ ] Store parsed data in PostgreSQL
- [ ] Basic web dashboard (Twig + Turbo) — personal use, no auth yet
- [ ] CLI summary output ("last 7 days: X reports, Y pass, Z fail, top senders")
- [ ] SPF/DKIM/DMARC record validation (reuse DNS checker from landing page)

**Not in scope:** Multi-user, billing, AI, teams

**Success criteria:** Jan stops deleting DMARC reports and actually understands what's in them.

---

## Phase 1 — Closed Beta (invite from email list)

**Goal:** Let real users in. Validate that people want this. Collect feedback.

**Scope:**
- [ ] User authentication (magic link or password)
- [ ] Onboarding flow (add domain, choose ingestion method)
- [ ] Two ingestion methods:
  - IMAP credentials (user provides their mailbox)
  - Dedicated receiving address (user sets `rua=` to our domain via Seznam Profi)
- [ ] DMARC aggregate report parsing and visualization
  - Pass/fail rates over time
  - Source IP breakdown
  - Sender/org identification
- [ ] SPF/DKIM/DMARC record validation (extended from DNS checker)
  - SPF: syntax, lookup count (10-limit), flattening suggestions
  - DKIM: key lookup, strength check
  - DMARC: policy analysis, alignment, progression recommendation
- [ ] DNS change monitoring
  - Periodic scan of SPF/DKIM/DMARC records (via Symfony Scheduler)
  - Detect and alert on any changes ("your SPF record changed today")
  - History of DNS record states over time
  - Alert if record becomes invalid or is removed
- [ ] Basic alerting
  - New unknown sender detected
  - Spike in failures
  - Policy recommendation
  - DNS record changed
- [ ] Basic email digest (non-AI) — weekly summary stats
- [ ] IMAP credential encryption (AES-256-GCM)
- [ ] Invite beta users in batches (10, 50, 100...)
- [ ] Feedback mechanism (in-app or email)

**Not in scope:** Billing, AI, teams, HTML email analysis

**Success criteria:** 10-50 beta users actively using it and providing feedback.

---

## Phase 2 — Public Launch + Billing

**Goal:** Open to public. Start charging. Stripe integration.

**Scope:**
- [ ] Stripe integration (subscriptions, checkout, customer portal, webhooks)
- [ ] Plan enforcement (domain limits, feature flags per tier)
- [ ] Public registration (open from beta)
- [ ] Sender inventory / discovery
  - Auto-map all services sending as your domain
  - Auth status per sender (Mailchimp ✓, random IP ✗)
- [ ] Blacklist monitoring
  - IP/domain blacklist checking (Spamhaus, Barracuda, SORBS, etc.)
- [ ] Email HTML/content analysis
  - Spam score estimation
  - Link validation
  - Image-to-text ratio
- [ ] MX record checker + mail server health
  - Verify MX records resolve, prioritize correctly
  - Check mail server responds (SMTP connect test)
  - TLS certificate validation on mail server
- [ ] Reverse DNS (PTR) lookup tool
  - Verify sending IP has valid reverse DNS
  - Flag mismatches between PTR and forward DNS
- [ ] Domain health score / report card
  - Aggregate grade (A-F) across SPF, DKIM, DMARC, MX, blacklists, TLS
  - Shareable public URL (badge for README, email signature)
  - PDF export of full domain health report
- [ ] Exportable PDF reports
- [ ] GitHub repo goes public (AGPL)
- [ ] Docker Hub image published
- [ ] Product Hunt launch
- [ ] HN / Reddit announcements

**Success criteria:** First paying customers. Revenue > $0.

---

## Phase 3 — AI & Teams

**Goal:** Ship the differentiating features. Enable collaboration.

**Scope:**
- [ ] AI Insights add-on ($3.99/mo)
  - Weekly AI digest email (plain-English summary)
  - On-demand "explain this" for reports and alerts
  - Guided remediation ("add this DNS record")
  - Anomaly explanation
- [ ] Team features (enabled for Team tier)
  - Team invitations
  - Role-based access (owner/admin/member/viewer)
  - Team-scoped dashboards
- [ ] Integrations
  - Slack notifications
  - Webhook API
- [ ] Multi-domain management improvements
- [ ] Advanced alerting configuration

**Success criteria:** AI add-on adoption rate > 20% of Personal users. Teams tier has paying customers.

---

## Phase 4 — Scale / Agency / Enterprise

**Goal:** Serve agencies and MSPs managing many domains.

**Scope:**
- [ ] White-label reports
- [ ] API access (API Platform, public docs)
- [ ] BIMI validation and setup guidance
- [ ] MTA-STS monitoring
- [ ] TLS-RPT parsing
- [ ] Advanced AI analysis (deeper on-demand investigation)
- [ ] SLA-based alerting
- [ ] Enterprise onboarding

---

## Overall Feature Matrix

| Feature | 0A Landing | 0B Personal | 1 Beta | 2 Launch | 3 AI+Teams | 4 Enterprise |
|---------|-----------|-------------|--------|----------|------------|--------------|
| SEO tool pages (SPF/DKIM/DMARC) | ✓ | | ✓ | ✓ | ✓ | ✓ |
| Knowledge Base (evergreen SEO guides) | ✓ | | ✓ | ✓ | ✓ | ✓ |
| Beta email collection | ✓ | | | | | |
| DMARC report parsing | | ✓ | ✓ | ✓ | ✓ | ✓ |
| Web dashboard | | ✓ | ✓ | ✓ | ✓ | ✓ |
| IMAP ingestion | | ✓ | ✓ | ✓ | ✓ | ✓ |
| Dedicated RUA address | | | ✓ | ✓ | ✓ | ✓ |
| SPF/DKIM/DMARC validation | | ✓ | ✓ | ✓ | ✓ | ✓ |
| DNS change monitoring | | | ✓ | ✓ | ✓ | ✓ |
| Basic alerts | | | ✓ | ✓ | ✓ | ✓ |
| User authentication | | | ✓ | ✓ | ✓ | ✓ |
| Credential encryption | | | ✓ | ✓ | ✓ | ✓ |
| Stripe billing | | | | ✓ | ✓ | ✓ |
| Sender inventory | | | | ✓ | ✓ | ✓ |
| Blacklist monitoring | | | | ✓ | ✓ | ✓ |
| HTML email analysis | | | | ✓ | ✓ | ✓ |
| PDF reports | | | | ✓ | ✓ | ✓ |
| AI Insights (add-on) | | | | | ✓ | ✓ |
| Teams / RBAC | | | | | ✓ | ✓ |
| Slack / webhooks | | | | | ✓ | ✓ |
| White-label | | | | | | ✓ |
| Public API | | | | | | ✓ |
| BIMI / MTA-STS / TLS-RPT | | | | | | ✓ |
