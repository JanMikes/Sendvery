# Monetization & Marketing Strategy

**Last updated:** 2026-03-24

## Pricing Model

### Self-Hosted — Always Free (open source)
- Unlimited everything
- All features
- Run on your own infrastructure (Docker)
- Community support (GitHub issues)
- **This is a marketing feature, not a limitation** — builds trust, drives adoption

### Hosted Free — 1 domain
- 1 domain
- Reasonable report volume (TBD — ~1,000 reports/mo or ~100k messages)
- DMARC report parsing + DNS validation (SPF/DKIM/DMARC)
- Basic email digest (no AI)
- 30-day data retention
- No team (solo only)
- **No AI**

### Hosted Personal — $5.99/mo
- Up to 5 domains
- Solo (no team members)
- Full report volume
- Real-time alerts
- Email HTML analysis
- Blacklist monitoring
- 1-year data retention
- Sender discovery / inventory
- **No AI** (available as add-on)

### Hosted Team — $49.99/mo
- Up to 50 domains
- Up to 10 team members
- Everything in Personal, plus:
- White-label PDF reports
- API access + webhooks
- Unlimited data retention
- **AI analysis included** (perk of Team tier)

### Enterprise — "Need more? Contact us"
- Custom domain/seat limits
- SLA
- Dedicated support
- Custom integrations
- AI included

### Add-ons (all tiers except Free)
- **AI Insights:** $3.99/mo — plain-English summaries, anomaly explanations, remediation guidance, weekly AI digest (Personal only — included in Team+)
- **Extra domain:** $1/mo per domain
- **Extra seat:** $2/mo per seat (Team+ only)

### What You Pay For (hosted tiers)
The software is free. Hosted tiers sell:
- Managed infrastructure (no Docker/server to maintain)
- Automatic updates
- Our hosted IMAP receiving address (no IMAP setup needed)
- Email support / priority support
- Data retention & backups

### AI Add-on Details
AI Insights ($3.99/mo) includes:
- Weekly AI digest email — plain-English summary of what happened
- Anomaly explanation — "this spike is likely caused by..."
- Remediation guidance — "add this DNS record to fix..."
- On-demand "explain this" for any report or alert
- Powered by Anthropic Claude API (costs covered by subscription)

**Marketing angle:** "The cheapest DMARC monitoring starts at $5.99. Want AI to explain it all? Add insights for $3.99."

**Future optionality:** If Anthropic API costs drop significantly, fold AI into all paid tiers as a value bump → "We just made AI Insights free for all paid plans!" = great retention email + press.

### Stripe Implementation

```
Stripe Products & Prices:

Product: "Sendvery Personal"
  Price: $5.99/mo (recurring)
  Price: $57.50/yr (~$4.79/mo, ~17% discount)

Product: "Sendvery Team"
  Price: $49.99/mo (recurring)
  Price: $479.90/yr (~$39.99/mo, ~17% discount)

Product: "AI Insights"
  Price: $3.99/mo (recurring, add-on for Personal)

Product: "Extra Domain"
  Price: $1.00/mo (metered/quantity-based)

Product: "Extra Seat"
  Price: $2.00/mo (metered/quantity-based)
```

Plan limits enforced in application logic (Symfony subscriber/event listener on domain creation, team invite, etc.), not in Stripe.

### Key Pricing Decisions (TBD)
- [x] ~~Per-domain or per-volume pricing?~~ → Per-domain tiers with generous volume. Add-on domains at $1/mo.
- [ ] **Annual discount?** Proposed: ~20% off (~2 months free). Personal: $57.50/yr, Team: $479.90/yr
- [ ] **Lifetime deal for early adopters?** Good for cash flow but creates future liability
- [x] ~~License choice?~~ → AGPL-3.0 (DEC-017)

### Smart upgrade nudges (implement in app)
- When a user on Personal adds enough extra domains that total cost ≥ Team price → show "Upgrade to Team and save" banner
- When a Team user's add-ons push total ≥ $100/mo → show "Contact us about Enterprise pricing"
- When Free user hits domain/report limit → show upgrade CTA with "unlock with Personal for $5.99/mo"
- When Personal user without AI views a report → show "Want this explained in plain English? Add AI Insights for $3.99/mo" teaser with a blurred/preview AI summary
- When Personal user with AI hits 5 domain limit → "Upgrade to Team for 50 domains + 10 members (AI included)"

### Annual pricing (recommended)
| Plan | Monthly | Annual (per month) | Annual total | Savings |
|------|---------|-------------------|-------------|---------|
| Personal | $5.99 | $4.99 | $59.88 | ~17% |
| Team | $49.99 | $41.59 | $499.00 | ~17% |

Annual pricing reduces churn and improves cash flow. ~17% discount (roughly 2 months free) is industry standard.

### Competitive positioning
| | Sendvery Personal | Sendvery + AI | dmarcian Basic | EasyDMARC Starter | PowerDMARC Basic |
|---|---|---|---|---|---|
| **Price** | **$5.99/mo** | **$9.98/mo** | $19.99/mo | $35.99/mo | $8/mo |
| **Domains** | 5 | 5 | 2 | 2 | 2 |
| **AI analysis** | No | **Yes** | No | No | No |
| **Self-hosted** | Free forever | Free forever | No | No | No |
| **SPF/DKIM/HTML** | Yes | Partial | Partial | Partial |

Sendvery is the cheapest option with the most domains AND unique AI features. This is a strong story.

## Revenue Model Considerations

**Why subscription makes sense:**
- Ongoing monitoring = recurring value
- AI analysis costs are per-use (Anthropic API)
- Blacklist checking requires ongoing API calls
- Data retention has storage costs

**Cost structure per user (rough estimate):**
- AI analysis: ~$0.01-0.05 per report summary (Claude Haiku/Sonnet)
- Storage: negligible at small scale
- Email sending (digests): ~$0.001 per email
- DNS lookups: negligible
- IMAP polling: compute time

**Break-even thinking:**
- At $10/mo per user, need ~100-200 paying users for meaningful revenue
- At $50/mo agency tier, need fewer but harder to acquire

## Go-to-Market: Fake Door → Closed Beta → Launch

### Phase 0: Fake Door Landing Page (deploy ASAP)

**Purpose:** Start SEO indexing, validate demand, collect email leads — while building the personal-use tool in the background.

**What to build:**
- Landing page at sendvery.com (part of the Symfony app, just Twig templates)
- Hero section: value proposition + "Join the Beta" CTA
- Free DNS checker tool (enter domain → instant SPF/DKIM/DMARC check)
  - This is REAL functionality, not fake — gives immediate value to visitors
  - No account needed — just enter a domain
  - Results page includes "Want ongoing monitoring? Join the beta"
- Feature overview (what Sendvery will do)
- Pricing page (show the planned tiers with "Coming soon")
- Knowledge Base section (evergreen SEO guides, no regular publishing cadence)
- Email collection form: "Join the closed beta — we'll notify you when it's ready"

**What happens when someone clicks "Sign Up" or "Start Free":**
- Show: "Sendvery is currently in closed beta. Enter your email to get early access."
- Collect: email, how many domains they manage (1, 2-5, 6-20, 20+), biggest email pain point (optional)
- Store in DB (simple `BetaSignup` entity)
- Send confirmation email: "You're on the list! We'll reach out when your spot opens."

**Why this works:**
- Google starts indexing immediately — SEO compounds over time
- DNS checker tool attracts organic traffic (people google "check my DMARC record")
- Email list = warm leads ready for launch day
- Survey data ("how many domains?") validates pricing tiers
- Zero pressure to build billing, teams, AI yet

### Phase 0 Tech Requirements (minimal)
- Twig landing page templates (hero, features, pricing, knowledge base, beta signup form)
- DNS lookup service (SPF/DKIM/DMARC record checker — real functionality)
- BetaSignup entity (email, domain_count, pain_point, created_at)
- Symfony Mailer for confirmation email
- Basic SEO: meta tags, sitemap.xml, robots.txt
- Knowledge Base as Twig templates or simple markdown-rendered pages (`/learn/*` routes)

### SEO Content Strategy (start publishing immediately)

**Target keywords (low competition, decent volume):**
- "check dmarc record" / "dmarc checker" / "dmarc lookup"
- "what is dmarc" / "dmarc explained"
- "spf record too many lookups" / "spf lookup limit"
- "how to set up dkim"
- "dmarc none vs quarantine vs reject"
- "email authentication guide"

**Content approach: Knowledge Base (not a blog)**

Instead of a traditional blog requiring a regular publishing cadence, Sendvery uses a **Knowledge Base** — a collection of evergreen, SEO-optimized guides. These are static pages that rank in search and never feel stale. No dates displayed, no "posted on" — just authoritative content. Write once, update occasionally, rank forever.

**Knowledge Base pages (write at launch, update as needed):**
1. `/learn/what-is-dmarc` — "DMARC in 5 minutes: what it is and why you need it" (evergreen explainer)
2. `/learn/spf-lookup-limit` — "SPF lookup limit: why your email is bouncing and how to fix it" (problem-specific, high intent)
3. `/learn/dmarc-none-to-reject` — "Moving from DMARC p=none to p=reject: a step-by-step guide" (actionable)
4. `/learn/email-authentication-guide` — "The complete guide to email authentication: SPF, DKIM, DMARC, BIMI" (pillar content)
5. `/learn/spf-broken` — "Your SPF record is probably broken and you don't know it" (security angle, "set and forget" trap)
6. `/learn/email-security-risks` — "5 email security risks you can't see without monitoring" (bridges all tool pages)
7. `/learn/dmarc-none-spoofing` — "What happens when your DMARC is set to none" (shows how p=none gets exploited)

**One personal story page (optional, on About or homepage):**
"I deleted my DMARC reports for years. Here's what I was missing." — Jan's authentic founder story. Not a blog post, just part of the About/brand narrative.

Each knowledge base page ends with DNS checker CTA + beta signup. Security-focused pages specifically funnel toward the monitoring value proposition — "checking once isn't enough."

### Phase 1: Personal Use (build in parallel with landing page)
- DMARC report ingestion (IMAP) for Jan's own domains
- Parser, storage, basic CLI/web dashboard
- This is the real product being built behind the scenes
- No auth, no billing, no teams needed yet

### Phase 2: Closed Beta (invite from email list)
- Add user authentication (magic link or password)
- Basic onboarding flow
- Invite beta users in batches (10, 50, 100...)
- Collect feedback aggressively
- Still no billing — beta is free

### Phase 3: Public Launch
- Enable Stripe billing
- Open registration
- Product Hunt launch
- Announce on HN, Reddit r/selfhosted, r/sysadmin
- GitHub repo goes public (AGPL)
- Docker Hub image published

### Phase 4: AI & Teams
- Add AI Insights add-on ($3.99/mo)
- Enable team features
- Enterprise tier

## Marketing Strategy (ongoing)

### Positioning
- NOT "enterprise email security" (Proofpoint, Agari territory)
- YES "email health for developers and small teams"
- Lead with price — "DMARC monitoring from $5.99/mo. 5 domains."
- Lead with simplicity — "Understand your email auth in plain English"
- Lead with open source — "Free forever if you self-host"
- Lead with security expertise — tool pages demonstrate deep knowledge of attack vectors, risks, and mitigation. The visitor should finish reading and think "these people understand email security better than I do — I need this."
- The "set and forget" narrative — every tool page reinforces that DNS records drift over time. New services get added, keys expire, providers migrate, records break silently. Checking once is a snapshot; monitoring is protection.

### Acquisition Channels
1. **SEO / Content** — knowledge base guides + DNS checker tools (start in Phase 0)
2. **Free DNS checker as lead gen** — organic traffic magnet
3. **Developer communities** — HN, Reddit r/sysadmin, r/selfhosted (Phase 3)
4. **Product Hunt** — launch day buzz (Phase 3)
5. **Open source community** — GitHub stars, Docker Hub pulls (Phase 3)
6. **Email list from beta** — warm leads for launch (Phase 2→3)

### Landing Page Messaging

**Hero:** "Your email, monitored. Understand your DMARC, SPF, and DKIM — not just charts, but answers."

**Subhero:** "Free DNS checker. Ongoing monitoring from $5.99/mo. Self-host free forever."

**CTA:** "Check your domain now" (DNS checker) + "Join the beta" (email capture)

**Alternative hero angle (security-forward):** "Your domain sends email every day. Do you know who else is sending as you?"

**Alternative subhero:** "Check your SPF, DKIM, and DMARC in seconds. Then stay protected with continuous monitoring that catches what you'd miss."

The security angle works especially well on tool pages where visitors arrive with a specific problem. The homepage can mix both — authority/expertise in the hero, simplicity/price in the features section.
