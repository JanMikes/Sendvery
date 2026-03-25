# Startup Essentials

**Last updated:** 2026-03-24

Practical startup fundamentals for a bootstrapped solo-founder product. No MBA busywork — only things that directly help build and sell.

**Reality check:** This is a personal-first project that will be vibecoded in one shot and then live on its own with minimal ongoing effort. Infrastructure is essentially free (owned VPS, $10/yr domain). Success = 2-5 new paying customers per month from organic SEO alone. No growth hacking, no hustle culture, no pressure.

---

## What This Is

A **micro-SaaS** — a small, focused product that solves a specific problem, generates passive income from organic SEO traffic, and requires minimal ongoing maintenance after the initial build. Not a startup chasing hockey-stick growth. Not expecting skyrocket. Just a well-built tool that solves a real problem in an interesting way, leveraging AI as a differentiator, and makes some "easy money" while doing it.

---

## SWOT Analysis

### Strengths
- **Founder is the target user** — Jan has the exact problem Sendvery solves. Product decisions come from real pain, not assumptions.
- **Deep Symfony expertise** — vibecoding with a framework you can review means faster iteration with fewer hidden bugs.
- **AI differentiator is real** — no competitor offers AI-powered analysis. Claude API integration is straightforward.
- **Open source trust** — AGPL means users can verify what happens with their data. Self-hosted option removes "trust us" objection entirely.
- **Low infrastructure cost** — existing Hetzner server, FrankenPHP is efficient, PostgreSQL handles everything. Near-zero marginal cost per user until serious scale.
- **8 free SEO tool pages** — massive organic search surface area before spending a cent on ads.
- **Price advantage** — $5.99 for 5 domains vs competitors at $19-35 for 2 domains.

### Weaknesses
- **Solo founder** — no co-founder means slower development, no second opinion, single point of failure for support/ops/code.
- **No existing audience** — no email list, no Twitter following, no community. Starting from zero.
- **Vibecoded = review bottleneck** — 100% test coverage mitigates but Jan still needs to review all generated code.
- **Brand recognition: zero** — competing against established names (dmarcian since 2012, EasyDMARC, Valimail).
- **Email auth market is niche** — but that's fine for a micro-SaaS. We don't need 10,000 customers. 50-100 paying users at $7/mo avg is meaningful passive income.

### Opportunities
- **"Set and forget" trap is universal** — every domain owner has this problem, most don't know it. Education-first content strategy can create demand.
- **Competitors are overpriced** — dmarcian $19.99 for 2 domains is 3x our price for less than half the domains. Clear price disruption opportunity.
- **AI is trending** — "AI-powered" is a genuine differentiator AND a marketing hook right now.
- **Self-hosted/open-source movement** — growing community of people who want alternatives to SaaS (r/selfhosted has 500k+ members).
- **Compliance pressure increasing** — Google/Yahoo 2024 sender requirements pushed DMARC adoption. More domains = more people needing monitoring.
- **Content gap** — most competitors' tool pages are dry and technical. Security-first educational content can outrank them.
- **Domain health score** — shareable A-F grade is viral mechanic (people share their scores, others check theirs).

### Threats
- **parsedmarc is free and good enough** — technical users who can self-host may never pay.
- **Big players could add AI** — dmarcian or EasyDMARC could ship AI features and neutralize our differentiator.
- **Google Postmaster Tools is free** — covers some of the same ground for Gmail-focused users.
- **Burnout risk** — solo founder building, marketing, supporting, and maintaining alone.
- **IMAP credential trust** — users may not trust a new startup with their mailbox credentials (mitigated by self-hosted option).
- **Market education cost** — many potential users don't know they have a problem. Content marketing takes months to compound.

---

## Key Metrics (keep it simple)

This is a micro-SaaS, not a VC-backed startup. Only track what matters:

| Metric | What tells us it's working |
|--------|---------------------------|
| **New paying customers / month** | 2-5 from organic SEO = success |
| **Total paying customers** | Growing, not shrinking |
| **Monthly churn** | If >10%, something is wrong with the product |
| **Tool page traffic** | Are the SEO pages ranking? Are people using the free tools? |
| **Signups** | Are people creating accounts after using free tools? |

That's it. No NPS surveys, no complex funnel metrics, no dashboards of dashboards. If 2-5 people per month find us on Google and pay $5.99, the project is a success.

---

## MVP Definition

Since this is vibecoded, the phased approach is less about "what can I build in 2 weeks" and more about "what's the priority order." DMARC parsing is the MVP because it solves Jan's personal need — the rest gets built in the same vibecoding push.

**The personal MVP (build first, non-negotiable):**
- IMAP connection to Jan's mailbox
- Parse DMARC XML reports
- Store in PostgreSQL
- Web dashboard showing reports, pass/fail rates, sender breakdown
- Jan stops deleting DMARC reports

**The rest (build alongside, vibecoded as one project):**
- All 8 tool pages with security content
- Landing page with full structure
- Knowledge base guides
- User auth, onboarding, multi-tenant
- Stripe billing
- Weekly digest emails
- DNS monitoring + alerts
- Beta signup flow

Since it's all vibecoded at once with 100% test coverage, there's no reason to artificially phase the build. The phased roadmap (03-features-roadmap.md) describes what to **deploy** when, not what to build when. Build it all, deploy incrementally: landing page first (SEO starts compounding), then open beta, then billing.

---

## Customer Journey (SEO-only acquisition)

The only acquisition channel is organic search. No ads, no cold outreach, no growth hacking. People find us on Google, use the free tools, and some of them sign up and pay.

```
GOOGLE SEARCH
│  "check my dmarc record", "spf checker", "why email going to spam",
│  "is my domain blacklisted", "email authentication checker"
│
▼
TOOL PAGE (free, no signup needed)
│  Runs check → sees results + security risk explanation
│  Reads "why this matters" + "what can go wrong" content
│  Sees domain health grade (A-F)
│
▼
REALIZES THEY NEED ONGOING MONITORING
│  "Your SPF was fine today. But what about tomorrow when your
│   marketing team adds a new ESP?"
│  → "Get ongoing monitoring from $5.99/mo" CTA
│  → or "Join the beta" during early phase
│
▼
SIGNS UP (free tier: 1 domain)
│  Adds domain → connects IMAP or sets RUA address
│  Sees first parsed DMARC report → "wow, I had no idea"
│
▼
USES IT (receives weekly digest)
│  Weekly email: "3 reports, all passing, SPF healthy"
│  Alert: "Your SPF record changed — was this you?"
│  → The digest IS the product for most users
│
▼
PAYS (hits limit or wants more)
│  Needs 2nd domain → upgrades to Personal ($5.99/mo)
│  Wants AI explanation → adds AI add-on ($3.99/mo)
│  Manages clients → upgrades to Team ($49.99/mo)
```

**The only two conversion points that matter:**
1. **Tool page → signup** — the free tool results must be good enough to make them think "I need this ongoing." Security content does the heavy lifting here.
2. **Free → paid** — hitting the 1-domain limit naturally. No aggressive upselling.

---

## Revenue Projections (realistic, zero-effort growth)

**Success definition: 2-5 new paying customers per month from organic SEO alone.** No active marketing effort after initial build and deploy. The tool pages do the selling.

| Timeline | New customers/mo | Total paying | MRR | Notes |
|----------|-----------------|-------------|-----|-------|
| Month 3 | 2-3 | ~8 | ~$55 | SEO starting to index, early adopters |
| Month 6 | 3-4 | ~25 | ~$175 | SEO compounding, tool pages ranking |
| Month 12 | 4-5 | ~60 | ~$420 | Steady organic growth |
| Month 24 | 5 | ~120 | ~$840 | Covers all costs + nice passive income |

**Assumptions:**
- 2-5 new customers per month (purely organic, zero effort)
- Average revenue per user: ~$7/mo (mostly Personal, some with AI add-on)
- Monthly churn: ~5%
- No paid ads, ever (unless it makes sense later)
- This is a one-shot vibecoded project that lives on its own

**Costs:**
- VPS: $0 (already owned)
- Domain: ~$10/yr
- Stripe fees: ~3%
- AI API: ~$0.01-0.05 per report analysis (Claude Haiku)
- Sentry: free tier
- **Total fixed cost: ~$10/yr.** Break-even from customer #1.

**If it does better than expected:** Great. If a Team tier customer shows up ($49.99/mo), that's a bonus. If it stays at 2-3 customers/mo, that's still $500+/yr in passive income from a project that solved Jan's own problem. Either way, it's a win.

---

## Risk Register

Since costs are essentially zero (owned VPS, $10/yr domain) and the project solves Jan's personal need regardless of commercial success, the risk profile is very different from a typical startup. There's no financial risk — only time risk and motivation risk.

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Loss of motivation after initial build** | High | High | The project solves Jan's own problem — he'll use it daily regardless. Vibecoding the whole thing at once means there's no "months of boring work" phase. Ship it, deploy it, let it run. |
| **SEO doesn't bring traffic** | Medium | Medium | 8 tool pages with real functionality = 8 keyword clusters. If after 6 months organic traffic is near zero, the project still works for Jan personally. Low stakes. |
| **IMAP trust barrier** | Medium | Low | Self-hosted option removes this. Open source code means anyone can verify. IMAP creds encrypted at rest. If people don't trust it, they use self-hosted. |
| **Nobody pays** | Medium | Low | Even with zero paying customers, Jan has a working DMARC tool. Financial downside is $10/yr for the domain. |
| **GDPR/legal issues** | Low | Medium | DMARC aggregate reports contain IPs, not personal data. Forensic reports (ruf) contain PII — skip ruf support or handle carefully. Add privacy policy + terms of service. |
| **Vibecoded quality issues** | Medium | Medium | 100% test coverage + mutation testing + Jan's Symfony expertise for code review. Tests describe business requirements, so correctness is enforced. |
| **Competitor ships AI** | Low | Low | Price + open source + all-in-one are independent advantages. If competitors add AI, we're still the cheapest option with the most domains per tier. |
| **Server gets overwhelmed** | Very Low | Medium | FrankenPHP worker mode is efficient. PostgreSQL handles plenty. At 2-5 new users/month, scale won't be an issue for years. Cross that bridge if it comes. |

---

## Churn Prevention

Simple: people stay because they use it and receive reports.

1. **Weekly email digest** — the product IS the digest for most users. "2 new reports, all passing, SPF healthy" is a weekly reminder that the guard is watching. If the digest stops being useful, they cancel. If it catches a real issue, they stay forever.

2. **Alerts on real problems** — "Your SPF record changed" or "New unknown sender detected" are high-value moments. One caught issue pays for a year of subscription in the user's mind.

3. **Data accumulates** — DNS change history, report trends, sender inventory. The longer someone uses Sendvery, the more irreplaceable the historical data becomes.

4. **Annual pricing** — 17% discount for annual. Reduces cancellation opportunities from 12x/year to 1x/year.

---

## Support Strategy

Email. That's it. Keep it simple.

- Paying customers get email support (response within 48 hours, realistically faster)
- Free users get GitHub Issues (community helps)
- FAQ page on the website answers the obvious questions upfront
- If the same question comes up 3 times, fix the UX or add a help tooltip — don't write more docs

---

## Pre-Launch Checklist

### Must-have before going live
- [ ] **Domain:** Purchase sendvery.com, configure DNS (A record → Hetzner, MX for email)
- [ ] **Stripe:** Account created, verified, test checkout works end-to-end
- [ ] **Terms of Service:** Page on the website (can use a generator + review, doesn't need a lawyer for MVP)
- [ ] **Privacy Policy:** GDPR-compliant page explaining what data we collect, how IMAP credentials are stored (AES-256-GCM encrypted at rest), data retention policy
- [ ] **Security notes:** Dedicated section or page explaining encryption, open source transparency, self-hosted option
- [ ] **GDPR compliance:** Cookie banner (minimal, privacy-first), data export/deletion capability for users, clear data processing description
- [ ] **Email sending:** Seznam SMTP working, SPF/DKIM/DMARC configured for sendvery.com itself (eat our own dogfood!)
- [ ] **SSL:** Handled by Traefik + Let's Encrypt (already in place)

### Nice-to-have before launch (but not blockers)
- [ ] Google Search Console verified
- [ ] Plausible or similar privacy-friendly analytics
- [ ] OpenGraph images for social sharing
- [ ] GitHub repo (private initially, public at Phase 2)
- [ ] Sentry project for error tracking
- [ ] PostgreSQL backup cron

---

## What We're NOT Doing

This is a micro-SaaS, not a startup. These are explicitly out of scope:

- **No paid ads** — organic SEO only. If SEO doesn't work, the project still works for Jan personally.
- **No mobile app** — responsive web is enough.
- **No investor pitch** — there's nothing to pitch. It's a profitable side project from day 1.
- **No elaborate onboarding** — connect IMAP, see results. Done.
- **No feature parity with enterprise tools** — we're not Valimail. We're the $5.99 alternative.
- **No content calendar** — knowledge base, not blog. Write once, update occasionally.
- **No growth hacking** — the tool pages sell themselves. No A/B testing, no drip campaigns, no funnels-of-funnels.
- **No active marketing after launch** — deploy, let SEO compound, answer support emails. That's the entire ongoing workload.
- **No overengineering** — one Hetzner server, one database, one codebase. Scale when scale is needed (it probably won't be).
