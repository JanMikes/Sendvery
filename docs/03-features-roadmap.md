# Features & Roadmap

**Last updated:** 2026-05-14

> **State of play (May 2026):** Phases 0A, 0B, 1, and most of Phase 2 are **shipped and merged on `main`**. Stripe billing is wired end-to-end — pricing CTAs route straight to Stripe checkout, gated only on whether the 12 Stripe prices have been created in the dashboard (see `docs/14-stripe-setup.md`). What remains: deploy to production with the sendvery.com domain, Product Hunt / HN announcement, real AI add-on impl (Phase 3 — plumbing is shipped, stub-first per DEC-057), team collaboration UI (Phase 3). Phase 4 (Enterprise / Public API / BIMI / MTA-STS) is descoped for now.

---

## Phase 0A — Fake Door Landing Page (deploy ASAP, in parallel with 0B)

**Goal:** Start SEO, validate demand, collect leads. Ship this FIRST.

**Scope:**
- [x] Symfony project scaffold (PHP 8.5, Symfony 8, FrankenPHP, Postgres)
- [x] Landing page (Twig): hero, live DNS checker, "used by" logos, problem statement, how it works, features, security expertise, domain health preview, open source callout, pricing ("coming soon"), FAQ, final CTA
- [x] Separate tool pages with SEO-optimized content + interactive checker:
  - `/tools/spf-checker` — SPF record lookup + "What is SPF?" explainer + FAQ
  - `/tools/dkim-checker` — DKIM record lookup + "What is DKIM?" explainer + FAQ
  - `/tools/dmarc-checker` — DMARC record lookup + "What is DMARC?" explainer + FAQ
  - `/tools/email-auth-checker` — combined all-in-one check
  - `/tools/dns-monitoring` — DNS record change monitoring explainer + "enter domain to see current records" + CTA for ongoing monitoring alerts
    - [ ] **TODO:** the interactive part is auth-only by nature. A one-shot DNS snapshot has no value without history to diff against, and persisting per-visitor snapshots in the public tool would duplicate the authenticated `MonitoredDomain → DnsSnapshot` flow. Current `/tools/dns-monitoring` page is intentionally a marketing CTA pointing to sign-in. When the authenticated snapshot/diff UI lands in the dashboard, link to it from this page.
  - `/tools/mx-checker` — MX record lookup + mail server connectivity check + TLS verification
  - `/tools/blacklist-checker` — check if domain/IP is on any major blacklists (Spamhaus, Barracuda, SORBS, etc.)
  - `/tools/domain-health` — all-in-one domain health report card (A-F grade), shareable URL, combines all checks
  - Each page: unique SEO content, structured data, internal links, beta signup CTA
- [x] Beta signup form + BetaSignup entity (email, domain_count, pain_point)
- [x] Confirmation email via Symfony Mailer
- [x] Knowledge Base (Twig templates, `/learn/*` routes)
- [x] First 2-3 evergreen SEO guides published
- [x] Basic SEO: meta tags, OpenGraph, sitemap.xml, robots.txt
- [ ] Deploy to Hetzner behind Traefik *(workflow + secrets being set up — see ~/www/spare.srv/deployment/sendvery)*

**Success criteria:** Pages indexed by Google, DNS tools driving organic traffic, beta emails collecting.

---

## Phase 0B — Personal Use (build in parallel with 0A)

**Goal:** Stop deleting DMARC reports. Get personal visibility into email auth.

**Scope:**
- [x] IMAP connection to personal mailbox (`webklex/php-imap` — DEC-049)
- [x] Download and unzip/ungzip DMARC report attachments
- [x] Parse aggregate report XML (RFC 7489)
- [x] Store parsed data in PostgreSQL
- [x] Basic web dashboard (Twig + Turbo)
- [x] CLI summary output (`bin/console app:dmarc:summary`)
- [x] SPF/DKIM/DMARC record validation

**Success criteria:** Jan stops deleting DMARC reports and actually understands what's in them.

---

## Phase 1 — Closed Beta (invite from email list)

**Goal:** Let real users in. Validate that people want this. Collect feedback.

**Scope:**
- [x] User authentication (magic link only — DEC-035)
- [x] Onboarding flow (add domain, choose ingestion method)
- [x] Two ingestion methods:
  - IMAP credentials (user provides their mailbox)
  - Dedicated receiving address *(infrastructure ready; per-mailbox routing still manual)*
- [x] DMARC aggregate report parsing and visualization
- [x] SPF/DKIM/DMARC record validation (extended from DNS checker)
- [x] DNS change monitoring (scheduled via Symfony Scheduler)
- [x] Basic alerting (new unknown sender, failure spike, policy recommendation, DNS record changed, blacklisting)
- [x] Basic weekly digest email (non-AI)
- [x] IMAP credential encryption (`paragonie/halite`)
- [x] Beta invitation system
- [x] In-app feedback mechanism

**Success criteria:** 10-50 beta users actively using it and providing feedback. *(In progress — accepting requests via `/request-access`.)*

---

## Phase 2 — Public Launch + Billing

**Goal:** Open to public. Start charging. Stripe integration.

**Scope:**
- [x] Stripe integration (subscriptions, checkout, customer portal, webhooks) — **code complete; fake-door removed 2026-05-22, ready for Stripe products to be created per `docs/14-stripe-setup.md`**
- [x] Plan enforcement (domain limits, feature flags per tier)
- [ ] Public registration (open from beta) — gated behind beta access requests
- [x] Sender inventory / discovery
- [x] Blacklist monitoring (free DNS-based RBLs only — DEC-051)
- [ ] Email HTML/content analysis *(deferred — needs a clear scope before we build)*
- [x] MX record checker + mail server health
- [ ] Reverse DNS (PTR) lookup tool *(MX checker covers some of this; standalone PTR tool not built)*
- [x] Domain health score / report card (A–F grade, shareable public URL, PDF export)
- [x] Exportable PDF reports
- [ ] GitHub repo goes public (AGPL) *(pending — code is AGPL-licensed but the repo is still private)*
- [ ] Docker Hub image published *(currently published to ghcr.io/janmikes/sendvery — Docker Hub mirror TBD)*
- [ ] Product Hunt launch
- [ ] HN / Reddit announcements

**Success criteria:** First paying customers. Revenue > $0. *(Blocked by: launch logistics — domain is live, deploy pipeline being wired, then create the 12 Stripe prices per `docs/14-stripe-setup.md`.)*

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

**Status:** Descoped (May 2026). Not pursuing white-label, public REST API, BIMI/MTA-STS/TLS-RPT, or enterprise-onboarding work in this iteration. Sendvery's near-term goal is to validate Personal/Team tiers with real users; agency tooling can come back into scope when (a) a paying customer asks for it, or (b) Personal/Team are validated and growth-y enough to justify the surface-area expansion.

The `src/ApiResource/` directory exists from the original API Platform install but is intentionally empty for now.

---

## Overall Feature Matrix

| Feature | 0A Landing | 0B Personal | 1 Beta | 2 Launch | 3 AI+Teams |
|---------|-----------|-------------|--------|----------|------------|
| SEO tool pages (SPF/DKIM/DMARC) | ✓ | | ✓ | ✓ | ✓ |
| Knowledge Base (evergreen SEO guides) | ✓ | | ✓ | ✓ | ✓ |
| Beta email collection | ✓ | | | | |
| DMARC report parsing | | ✓ | ✓ | ✓ | ✓ |
| Web dashboard | | ✓ | ✓ | ✓ | ✓ |
| IMAP ingestion | | ✓ | ✓ | ✓ | ✓ |
| Dedicated RUA address | | | ✓ | ✓ | ✓ |
| SPF/DKIM/DMARC validation | | ✓ | ✓ | ✓ | ✓ |
| DNS change monitoring | | | ✓ | ✓ | ✓ |
| Managed DMARC + auto-drive (CNAME, DEC-058) | | | ✓ | ✓ | ✓ |
| Basic alerts | | | ✓ | ✓ | ✓ |
| User authentication | | | ✓ | ✓ | ✓ |
| Credential encryption | | | ✓ | ✓ | ✓ |
| Stripe billing | | | | ✓ (fake-doored) | ✓ |
| Sender inventory | | | | ✓ | ✓ |
| Blacklist monitoring (free RBLs) | | | | ✓ | ✓ |
| PDF reports | | | | ✓ | ✓ |
| AI Insights (add-on) | | | | | ✓ |
| Teams / RBAC | | | | | ✓ |
| Slack / webhooks | | | | | ✓ |
