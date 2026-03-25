# Design & Branding

**Last updated:** 2026-03-24
**Status:** Direction set, details TBD

---

## Brand Identity

### Name
**Sendvery** — "Send" + "delivery". Email health & deliverability platform.

### Logo Concept
- Start with an existing SVG icon as a base and customize/modify it into something unique
- Direction: something that combines the idea of email/sending with monitoring/health/protection
- Possible motifs: envelope + pulse/heartbeat, paper plane + shield, mail + checkmark, sending arrow + radar
- Should work as: favicon (16x16), navbar logo (32px height), full logo with wordmark, social media avatar
- Must be simple enough to be recognizable at small sizes
- NO generic stock icons — modify and make it ours

### Color Palette (TBD — initial direction)
- Should feel: modern, trustworthy, technical but approachable
- NOT: boring corporate blue (#0066cc that every SaaS uses)
- Consider: deep teal/cyan, vibrant indigo, warm coral as accent, or an unexpected combo
- Need: primary, secondary, accent, success (green), warning (amber), error (red), neutral grays
- Dark mode support from day one (both landing page and dashboard)

### Typography
- Clean sans-serif for UI (Inter, Plus Jakarta Sans, or similar)
- Possibly a slightly more expressive font for landing page headlines
- Monospace for code/DNS records display (JetBrains Mono, Fira Code)

---

## Design System: Two Distinct Designs

### 1. Landing Page / Marketing Site

**Purpose:** Convert visitors. SEO. Communicate value. Collect beta signups.

**Requirements:**
- Strong SEO focus (semantic HTML, fast loading, structured data)
- Catchy, distinctive design — must NOT look like generic Tailwind template
- Clean, responsive, modern, slightly playful
- Interactive elements (live DNS checker demo, possibly animations/video)
- Clear communication: what problem we solve, pricing, why we're better
- Space for AI messaging and differentiation

**Design Principles:**
- Personality over polish — better to be memorable than perfectly polished
- Show, don't tell — the DNS checker is a live demo, not a screenshot
- Breathing room — generous whitespace, not cramped
- Speed — no heavy JS frameworks, minimal above-the-fold JS
- Accessible — WCAG AA minimum

**Pages needed (Phase 0A):**
1. **Homepage** — hero, value prop, feature highlights, DNS checker CTA, social proof (later)
2. **SPF Checker** — `/tools/spf-checker` — dedicated SPF record lookup + explainer
3. **DKIM Checker** — `/tools/dkim-checker` — dedicated DKIM record lookup + selector input
4. **DMARC Checker** — `/tools/dmarc-checker` — dedicated DMARC record lookup + policy explainer
5. **Email Auth Checker** — `/tools/email-auth-checker` — combined all-in-one check
6. **DNS Monitoring** — `/tools/dns-monitoring` — DNS record change monitoring explainer + snapshot of current records + CTA for ongoing alerts
7. **MX Checker** — `/tools/mx-checker` — MX record lookup, mail server connectivity, TLS cert check
8. **Blacklist Checker** — `/tools/blacklist-checker` — check domain/IP against major blacklists (Spamhaus, Barracuda, SORBS, etc.)
9. **Domain Health** — `/tools/domain-health` — all-in-one report card (A-F grade), shareable URL, aggregate of all checks — this is the flagship SEO page
10. **What is Sendvery** — `/about/what-is-sendvery` — dedicated page explaining what the product is, what problem it solves, who it's for (the three personas), and why it exists. Written for someone who landed here from a tool page and wants to understand the bigger picture before signing up. Not a generic "about us" — more like a product manifesto. Includes the founder story ("I was deleting my DMARC reports..."), the security angle ("your email auth deserves continuous monitoring"), and the open source angle
11. **Features** — detailed feature breakdown with visuals
12. **Pricing** — tier comparison, "coming soon" badge, competitive comparison
13. **Knowledge Base** — evergreen guides (not a blog — no publishing cadence required, just static educational pages that rank for SEO)
14. **Beta Signup** — could be modal or dedicated page
15. **About / Open Source** — AGPL, GitHub link, self-hosting info, how to contribute

**SEO Strategy for Tool Pages (DEC-028):**

Each tool page is a standalone SEO landing page with:
- Its own `<title>`, `<meta description>`, `<h1>` targeting specific keywords
- Unique explainer content ("What is SPF?", "How DKIM works", etc.)
- The interactive checker tool
- FAQ section (targets "People Also Ask" snippets)
- Internal links to other tools and knowledge base guides
- CTA: "Want ongoing monitoring? Join the beta"
- Structured data (FAQ schema, HowTo schema where applicable)

Target keywords per page:

| Page | Primary Keywords | Search Intent |
|------|-----------------|---------------|
| SPF Checker | "spf checker", "spf record lookup", "check spf record", "spf validator" | Tool |
| DKIM Checker | "dkim checker", "dkim record lookup", "dkim key check", "check dkim" | Tool |
| DMARC Checker | "dmarc checker", "dmarc record lookup", "dmarc analyzer", "check dmarc" | Tool |
| Email Auth Checker | "email authentication checker", "email security check", "domain email check" | Tool |
| DNS Monitoring | "dns monitoring", "dns record change alert", "dns change detection", "spf record monitoring" | Tool / Informational |
| MX Checker | "mx record lookup", "mx checker", "mail server check", "mx record test" | Tool |
| Blacklist Checker | "blacklist check", "email blacklist lookup", "ip blacklist checker", "is my domain blacklisted" | Tool |
| Domain Health | "domain health check", "email domain score", "domain security check", "email authentication score" | Tool |
| Homepage | "email monitoring", "dmarc monitoring tool", "email deliverability" | Informational |
| Knowledge Base (`/learn/*`) | Long-tail keywords (see marketing doc) | Educational |

This creates 8 high-intent tool pages that each rank independently. MXToolbox does exactly this and dominates these keywords — but their UX is dated and cluttered. Clean, fast, modern tool pages can compete.

### Security-First Content Strategy (per tool page)

Every tool page follows a three-part narrative: **educate → reveal risk → offer solution**. The tone is confident and expert — not fear-mongering, but making the reader feel "these people know what they're talking about, and I clearly need this."

**Shared content pattern for each tool page:**

1. **What it is** — short, plain-English explanation of the protocol
2. **Why it matters** — real-world consequences when it's wrong (spoofing, phishing, deliverability loss, brand damage)
3. **Live checker** — interactive tool, instant results, visual pass/fail
4. **Security risks & attack vectors** — what can go wrong, explained clearly
5. **How to fix it** — actionable steps (not just "hire an expert")
6. **Why checking once isn't enough** — the "set and forget" trap → CTA for monitoring

**Per-page security angles:**

| Page | Key Risks to Highlight | "Set and Forget" Trap | Monitoring CTA |
|------|----------------------|----------------------|----------------|
| **SPF Checker** | SPF record exceeds 10-lookup limit after adding new services (MailChimp, Zendesk, etc.), old includes left from services you no longer use, `+all` misconfiguration allowing anyone to send as you | "You added HubSpot 6 months ago and broke your SPF without knowing. Every email since has been failing authentication." | "Get alerted when your SPF record changes or breaks the lookup limit" |
| **DKIM Checker** | Key rotation not happening (stale 1024-bit keys), selector misconfigured after DNS migration, third-party services publishing weak keys on your behalf | "You migrated DNS providers last year. Your DKIM key didn't follow. Receiving servers have been silently marking your email as unverified." | "Monitor all your DKIM selectors across providers — know the moment a key expires or disappears" |
| **DMARC Checker** | Still on `p=none` (monitoring only, no enforcement), aggregate reports going to a mailbox nobody reads, percentage tag (`pct`) accidentally set below 100, no `rua`/`ruf` reporting configured | "Your DMARC policy says 'none' — that means you're watching but not blocking. Attackers can still send email as your domain right now." | "Track your DMARC enforcement journey from p=none to p=reject with guided steps" |
| **Email Auth Checker** | Inconsistencies between SPF/DKIM/DMARC (e.g., SPF passes but DMARC fails due to alignment), subdomain policies missing, third-party senders not properly authorized | "Your SPF is green, your DKIM is green — but DMARC still fails because of domain alignment. Most checkers won't tell you that." | "Full-stack email authentication monitoring — one dashboard, all protocols, all domains" |
| **DNS Monitoring** | Unauthorized DNS changes (compromised registrar, rogue employee, accidental edit), TTL changes amplifying propagation of bad records, NS delegation changes, MX record hijacking | "Someone changed your MX record 3 days ago. Your email has been silently routing to a server you don't control." | "Continuous DNS surveillance — every record type, every change, instant alert" |
| **MX Checker** | MX pointing to decommissioned server, missing backup MX, no TLS on mail server (plaintext interception), expired TLS cert on mail server, MX priority misconfiguration causing load imbalance | "Your mail server's TLS certificate expired 2 weeks ago. Every email since has been transmitted in plaintext — readable by anyone on the network." | "Monitor your mail servers' health, TLS status, and connectivity 24/7" |
| **Blacklist Checker** | IP listed on Spamhaus/Barracuda/SORBS due to compromised account sending spam, shared hosting IP tainted by other tenants, domain blacklisted after a phishing incident you didn't cause | "Your sending IP was blacklisted 5 days ago because another tenant on your shared server sent spam. Your legitimate emails have been bouncing ever since." | "Daily blacklist scans across 50+ lists — know the moment you're listed, not when customers complain" |
| **Domain Health** | Cumulative small issues creating a major deliverability problem — SPF almost at limit, DKIM key aging, DMARC on p=none, no BIMI, MX TLS weak — each "fine" individually but together devastating | "Every single check shows 'mostly okay.' But combined, your domain scores a D. That's why 15% of your email lands in spam." | "One score, one dashboard, one place to see everything — check your domain health grade now" |

**Tone guidelines for security content:**
- Use concrete scenarios, not abstract warnings ("Your email to 50,000 newsletter subscribers is landing in spam" > "Misconfiguration may affect deliverability")
- Show the timeline: how long a problem can exist undetected without monitoring (days, weeks, months)
- Reference real incidents where possible (major brands losing deliverability, phishing campaigns exploiting weak DMARC)
- Position Sendvery as the "second set of eyes" — not replacing the admin, but catching what humans miss
- Never say "you're insecure" — say "here's what's happening behind the scenes that you deserve to see"
- End with empowerment, not fear: "Now you know. Here's how to stay on top of it."

**Visual elements for security sections:**
- Pass/fail indicators with color coding (green/amber/red)
- "Risk score" or "health grade" (A-F) for the overall domain — highly shareable, drives repeat visits
- Timeline visualization: "Days since last check" with increasing urgency colors
- Before/after comparison: domain without monitoring vs. with Sendvery
- Spoofing simulation preview: "This is what a spoofed email from your domain looks like" (mockup, not real)

**Interactive Elements:**
- [ ] **Live DNS checker** — type a domain, get instant SPF/DKIM/DMARC results with visual pass/fail indicators. This is the #1 interactive element and conversion driver.
- [ ] **Animated/visual explainer** — could be: how DMARC works (flow diagram), before/after comparison (without Sendvery vs with), or a short screen recording of the dashboard
- [ ] **Pricing calculator** — toggle between monthly/annual, see add-on costs dynamically
- [ ] **Dark mode toggle** — shows we care about details

**Inspiration (vibe, not copy):**
- Resend.com — developer-focused, clean, distinctive (not stock Tailwind)
- Linear.app — dark mode first, confident, minimal
- Plausible.io — open source SaaS, honest, clear pricing
- PostHog.com — playful, opinionated, hedgehog mascot gives personality
- Raycast.com — polished, fast, technical audience

**Anti-patterns (what to avoid):**
- Generic hero with gradient blob background
- Stock photos of people pointing at screens
- "Trusted by 10,000+ companies" when we have zero (use Jan's own companies instead — see Trust Elements below)
- Cookie-cutter Tailwind template with just colors swapped
- Walls of text with no visual hierarchy

### Landing Page Trust & Credibility Elements

A good marketing page earns trust through multiple signals. Every section should answer the visitor's unconscious question: "Can I trust these people with my email infrastructure?"

**Section-by-section landing page structure:**

1. **Hero** — value prop + instant CTA ("Check your domain now")
2. **Live DNS checker widget** — embedded tool, instant value, no signup required. This IS the trust builder — the tool itself demonstrates competence
3. **"Used by" logo bar** — Jan's own companies (real logos, real domains). Honest: these are the founder's businesses, not fake social proof. Even 3-4 real logos are better than none
4. **Problem statement** — "Email authentication is set once and forgotten. Then things break silently." + visual showing timeline of undetected issues
5. **How it works** — 3-step visual: Connect → Monitor → Act. Simple, clear, shows the product isn't complex
6. **Feature highlights** — 3-4 key features with icons and brief descriptions, each linking to the relevant tool page
7. **Security expertise section** — "Your email security, explained" — mini versions of the attack vector content from tool pages. Shows we know what we're talking about. Could include a "Did you know?" stat (e.g., "83% of domains have at least one email authentication misconfiguration")
8. **Domain Health Score preview** — show an example A-F grade report card. Visitors think "I wonder what MY score is" → drives tool usage
9. **Open source callout** — "Free forever if you self-host. AGPL licensed. Star us on GitHub." Trust signal: we have nothing to hide
10. **Pricing** — clean tier comparison, annual toggle, "coming soon" badge during beta
11. **Technical credibility** — small section: "Built with Symfony, PostgreSQL, FrankenPHP. All data encrypted at rest. Your IMAP credentials are AES-256-GCM encrypted." Developers notice this
12. **FAQ** — 6-8 questions covering: "Is my data safe?", "How does IMAP ingestion work?", "Can I self-host?", "What makes this different from MXToolbox?", "Do I need to be technical?", "How does AI analysis work?"
13. **Final CTA** — "Check your domain for free" + "Join the beta"
14. **Footer** — links to all tool pages (SEO internal linking), GitHub, legal, contact

**Trust signals woven throughout (not separate sections):**
- Real company logos (Jan's businesses) in a "Used by" bar — honest and authentic
- GitHub star count badge (once repo is public)
- "Open Source" badge near the logo/nav
- Technology badges: "Encrypted", "GDPR-friendly", "Self-hostable"
- Specific numbers over vague claims: "8 free DNS tools" not "comprehensive toolkit"
- The interactive tools ARE the trust — they prove the product works before any signup
- No fake testimonials — when real beta feedback comes in, add it then. Until then, let the tools speak
- Security content on tool pages demonstrates deep expertise (see Security-First Content Strategy above)

**Testimonials strategy (phased):**
- Phase 0A: Jan's companies as logo bar + "Built by a developer who was deleting his own DMARC reports" (founder story = authenticity)
- Phase 1 (beta): collect real quotes from beta users, even short ones. "Finally I can see what's happening with my email auth" is better than no testimonial
- Phase 2+: proper testimonial cards with name, company, photo

**Design details that build trust subtly:**
- HTTPS padlock + "Your data is encrypted" in footer
- Consistent, professional typography (no Comic Sans moments)
- Fast page load (FrankenPHP + minimal JS = quick)
- Proper mobile layout (broken mobile = instant distrust)
- Accessible contrast ratios and focus states
- No popups, no cookie walls (just a discreet banner), no dark patterns
- Real screenshot/mockup of the dashboard (not just abstract feature descriptions)

**Template approach:**
- Don't buy a marketing template — they all look the same
- Build custom with Tailwind CSS but add distinctive elements:
  - Custom illustrations or modified SVGs
  - Micro-animations (on scroll reveals, hover states)
  - Unique section shapes/dividers (not just straight lines)
  - A bit of personality in copy and visuals

### 2. Dashboard / Admin Panel

**Purpose:** Daily tool for monitoring email health. Functional, efficient, pleasant.

**Requirements:**
- Dashboard-style layout (sidebar nav, main content area)
- Clean, modern, responsive
- Matches corporate color system
- Tailwind CSS + headless components (adapted for Twig)
- Data-dense but not overwhelming
- Dark mode support

**Design Principles:**
- Function over form — users are here to work, not admire design
- Information density — show relevant data without requiring clicks
- Consistency — every page follows the same patterns
- Fast — server-rendered, minimal JS, instant navigation via Turbo

**Key Screens:**
1. **Dashboard overview** — health score per domain, recent alerts, key metrics
2. **Domain detail** — DMARC pass/fail chart, sender breakdown, DNS record status
3. **DMARC reports list** — filterable table, date range picker
4. **Report detail** — parsed XML data in readable format, source IP details
5. **DNS checker** — same as landing page but within dashboard context
6. **Alerts** — list of notifications, severity indicators
7. **Settings** — domain management, mailbox connections, alert preferences
8. **Team management** — members, roles, invitations (Phase 3)
9. **Billing** — Stripe customer portal redirect, plan info

**Component Library (Tailwind + headless):**
Build a small Twig component library:
- Buttons (primary, secondary, danger, ghost)
- Cards (stat card, chart card, table card)
- Tables (sortable, filterable, paginated)
- Forms (inputs, selects, toggles, file upload)
- Modals (confirmation, form modal)
- Alerts/toasts (success, warning, error, info)
- Navigation (sidebar, breadcrumbs, tabs)
- Charts (line, bar, donut — using Chart.js or similar)
- Status badges (pass/fail, severity levels)
- Empty states (no data yet, setup needed)

**Template approach:**
- Use an open-source Tailwind admin template as the starting point
- Customize colors, typography, and components to match brand
- Candidates to evaluate:
  - **Tabler** (MIT, Tailwind option, very complete) — tabler.io
  - **Windmill Dashboard** (MIT, Tailwind) — lightweight
  - **Mosaic** (Tailwind, React-based but layout/design can be adapted)
  - **Notus** (Creative Tim, MIT, Tailwind)
  - Build from scratch using Tailwind + Headless UI patterns

---

## Technical Implementation

### CSS Architecture
- Tailwind CSS 4 (latest)
- Symfony AssetMapper (no Webpack/Vite needed for server-rendered)
- Custom Tailwind theme config with brand colors
- Shared design tokens between landing page and dashboard
- Both designs share: color palette, typography, base components
- They differ in: layout, tone, density, personality

### Component Approach (Twig)
```twig
{# Example: reusable stat card component #}
{% component StatCard with {
    title: 'DMARC Pass Rate',
    value: '94.2%',
    trend: '+2.1%',
    trendDirection: 'up',
    icon: 'shield-check'
} %}
```

Use Symfony UX Twig Components for reusable UI elements.

### Icons
- Lucide Icons (MIT, clean, consistent) or Heroicons (by Tailwind team)
- For the logo: start with an SVG from icon set, modify/combine to make unique

### Charts
- Chart.js (lightweight, good Tailwind integration) or ApexCharts
- Server-rendered data passed to Stimulus controllers
- Consider: simple SVG sparklines for inline metrics (no JS needed)

---

## Design Decisions Still Needed

- [ ] Brand colors — need to pick a palette
- [ ] Logo — need to explore SVG base + modifications
- [ ] Landing page: custom illustrations vs modified SVGs vs abstract shapes?
- [ ] Landing page: video demo or animated walkthrough?
- [ ] Dashboard template: which base template to start from (Tabler vs custom)?
- [ ] Chart library: Chart.js vs ApexCharts?
- [ ] Font selection: Inter vs Plus Jakarta Sans vs something else?
- [ ] Mascot or no mascot? (PostHog's hedgehog approach — gives personality but might be too much)
