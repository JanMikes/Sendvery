# Stage 4: Landing Page & Layout

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Conventions, especially: controllers (single-action, `__invoke()`).
2. `docs/09-design-and-branding.md` — **READ FULLY.** Landing page structure (14 sections), design principles, anti-patterns, trust elements, tool page list, color/typography direction, component approach, inspiration sites.
3. `docs/03-features-roadmap.md` — Phase 0A scope, what pages are needed.
4. `prompts/images-prompts.md` — **Reference.** Pre-generated images may be available in `assets/images/`. Use them where noted (hero background, "how it works" illustrations, background patterns, OG image). If images aren't generated yet, leave `<img>` tags with descriptive `alt` text and placeholder backgrounds — they'll be dropped in later.

## What Already Exists (Stages 1-3 completed)

- Symfony 8.0, Docker, Tailwind CSS 4 + daisyUI, AssetMapper
- Core infrastructure (EntityWithEvents, IdentityProvider, DomainEventsSubscriber)
- Identity layer (Team, User, TeamMembership entities, TeamFilter, Voters)
- Test infrastructure, all tests passing

## What to Build

The marketing website shell — layout, navigation, homepage, and the structural foundation for tool pages and Knowledge Base. The goal is a deployable landing page that starts SEO indexing.

### 1. Design System Setup

**Tailwind theme configuration:**
- Define brand color palette in Tailwind config. Choose something distinctive — NOT generic blue. Read `docs/09-design-and-branding.md` → Color Palette section for direction. Pick a deep teal or vibrant indigo primary.
- Configure typography: Inter for UI text, JetBrains Mono for code/DNS records
- Dark mode support (Tailwind's `dark:` variant, using class strategy for toggle)
- daisyUI theme customization to match brand colors

### 2. Base Twig Components

Create reusable Twig components (Symfony UX Twig Components) for the marketing site:

- **`MarketingLayout`** — full page layout: responsive nav, main content, footer
- **`Nav`** — responsive navigation bar with logo, links (Tools dropdown, Knowledge Base, Pricing, GitHub), dark mode toggle, "Check your domain" CTA button. Mobile hamburger menu.
- **`Footer`** — links to all 8 tool pages (SEO internal linking), Knowledge Base, GitHub, legal pages, "Built with ♥ and Symfony" or similar
- **`HeroSection`** — reusable hero with title, subtitle, CTA button, and optional visual/animation slot
- **`SectionContainer`** — consistent section wrapper with padding, max-width, optional background
- **`FeatureCard`** — icon + title + description card for feature highlights
- **`ToolCard`** — card linking to a specific tool page, with icon and brief description
- **`FaqAccordion`** — collapsible FAQ section (use daisyUI collapse/accordion)
- **`DnsCheckerWidget`** — domain input + "Check" button (UI only — logic comes in Stage 5)
- **`PricingTable`** — tier comparison grid with "coming soon" badge (read `docs/05-monetization.md` for tiers)

### 3. Homepage Controller & Template

**`src/Controller/HomepageController.php`** — single-action controller, route `/`

**`templates/homepage/index.html.twig`** — implements the 14-section landing page structure from `docs/09-design-and-branding.md`:

1. **Hero** — "Your email, your rules, your insight" or similar. CTA: "Check your domain now" → scrolls to DNS checker
2. **Live DNS checker widget** — domain input field, will be wired in Stage 5. For now, just the UI shell.
3. **"Used by" logo bar** — placeholder for Jan's company logos. Simple gray logo bar.
4. **Problem statement** — "Email authentication is set once and forgotten. Then things break silently."
5. **How it works** — 3 steps: Connect → Monitor → Act
6. **Feature highlights** — 3-4 key features with icons
7. **Security expertise section** — mini attack vector previews (teasers for tool pages)
8. **Domain Health Score preview** — example A-F grade report card mockup
9. **Open source callout** — AGPL, GitHub star badge placeholder, self-host message
10. **Pricing** — use PricingTable component, "coming soon" badge, tiers from `docs/05-monetization.md`
11. **Technical credibility** — "Built with Symfony, PostgreSQL, FrankenPHP. AES-256-GCM encrypted."
12. **FAQ** — 6-8 questions (Is data safe? How does IMAP work? Self-host? vs MXToolbox? Need to be technical? AI analysis?)
13. **Final CTA** — "Check your domain for free" + "Join the beta"
14. **Footer** — (from Footer component)

### 4. Tool Page Shell Routes

Create placeholder routes and controllers for all 8 tool pages (content comes in Stage 5):
- `/tools/spf-checker` — `SpfCheckerController`
- `/tools/dkim-checker` — `DkimCheckerController`
- `/tools/dmarc-checker` — `DmarcCheckerController`
- `/tools/email-auth-checker` — `EmailAuthCheckerController`
- `/tools/dns-monitoring` — `DnsMonitoringController`
- `/tools/mx-checker` — `MxCheckerController`
- `/tools/blacklist-checker` — `BlacklistCheckerController`
- `/tools/domain-health` — `DomainHealthController`

Each renders a minimal placeholder template extending the marketing layout, with the correct `<title>` and `<meta description>` from `docs/09-design-and-branding.md` → SEO keywords table.

### 5. Static Pages

- `/about/what-is-sendvery` — `WhatIsSendveryController` — placeholder template
- `/pricing` — `PricingController` — renders PricingTable component
- `/about/open-source` — `OpenSourceController` — AGPL info, GitHub link, self-hosting

### 6. SEO Foundation

- Create `templates/base_meta.html.twig` block that every page extends — includes `<title>`, `<meta description>`, OpenGraph tags, Twitter cards, canonical URL
- Create a `sitemap.xml` route that generates XML sitemap listing all public pages
- Create `robots.txt` route (or static file) allowing all crawlers, pointing to sitemap
- Add JSON-LD structured data on the homepage (Organization schema)

### 7. Stimulus Controllers

- **`dark-mode-controller`** — toggles dark mode, persists preference in localStorage
- **`mobile-nav-controller`** — hamburger menu toggle
- **`scroll-to-controller`** — smooth scroll to element (for "Check your domain now" → DNS checker)
- **`faq-controller`** — accordion behavior (if not using daisyUI's built-in)

### 8. Tests

**Functional tests (WebTestCase):**
- Homepage returns 200, contains expected sections (hero text, CTA, pricing)
- All 8 tool page routes return 200
- Static pages return 200
- Sitemap.xml returns valid XML with all public URLs
- Robots.txt returns correct content
- All pages have proper `<title>` and `<meta description>` tags
- Navigation links point to correct routes
- Pages render without errors in dark mode classes

## Verification Checklist

- [ ] All tests pass
- [ ] Homepage renders all 14 sections with correct structure
- [ ] All 8 tool page routes return 200
- [ ] Navigation works (links, mobile menu, dark mode toggle)
- [ ] Responsive: looks good on mobile, tablet, desktop
- [ ] SEO meta tags present on every page
- [ ] Sitemap.xml lists all public routes
- [ ] No YAML config
- [ ] Tailwind compiles with custom theme colors

## What Comes Next

Stage 5 wires up the DNS checker tools — the interactive SPF/DKIM/DMARC/MX lookup services that power the tool pages. These are the #1 SEO conversion driver. The page shells from this stage will be filled with real checker functionality and SEO content.
