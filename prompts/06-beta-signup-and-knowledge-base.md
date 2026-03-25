# Stage 6: Beta Signup & Knowledge Base

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, CQRS pattern.
2. `docs/05-monetization.md` — Knowledge Base strategy (renamed from blog), SEO content strategy.
3. `docs/03-features-roadmap.md` — Phase 0A scope, what "done" looks like: beta emails collecting, pages indexed, DNS tools driving traffic.
4. `docs/09-design-and-branding.md` — Knowledge Base as evergreen guides, no publishing cadence.

## What Already Exists (Stages 1-5 completed)

- Full Symfony 8 project with Docker, Tailwind + daisyUI
- Core infrastructure + identity layer (Team, User, TeamMembership)
- Marketing layout, homepage (14 sections), navigation, footer
- 8 interactive DNS tool pages with real SPF/DKIM/DMARC/MX checkers, SEO content, structured data
- Sitemap, robots.txt, meta tags, FAQ JSON-LD on all tool pages

## What to Build

The final pieces of Phase 0A: beta email collection and the Knowledge Base for long-tail SEO.

### 1. BetaSignup Entity

**`src/Entity/BetaSignup.php`** — `final class`:
- `id` (UUID v7, readonly)
- `email` (string)
- `domainCount` (nullable int — "how many domains do you manage?")
- `painPoint` (nullable string — "what's your biggest email auth pain point?")
- `source` (string — which page they signed up from, e.g., "homepage", "spf-checker")
- `signedUpAt` (DateTimeImmutable, readonly)
- `confirmedAt` (nullable DateTimeImmutable — after email confirmation)
- `confirmationToken` (string, readonly — random token for email confirmation)
- Implements EntityWithEvents, records `BetaSignupCreated` event

### 2. CQRS for Beta Signup

**Command:** `src/Message/RegisterBetaSignup.php`
- `signupId` (UuidInterface)
- `email` (string)
- `domainCount` (?int)
- `painPoint` (?string)
- `source` (string)

**Handler:** `src/MessageHandler/RegisterBetaSignupHandler.php`
- Creates BetaSignup entity
- Generates confirmation token (random 64-char hex string)
- Persists

**Event:** `src/Events/BetaSignupCreated.php`
- `signupId`, `email`, `confirmationToken`

**Event Handler:** `src/MessageHandler/SendBetaConfirmationEmail.php`
- Listens to `BetaSignupCreated`
- Sends confirmation email via Symfony Mailer with link containing the token

### 3. Beta Signup Form & Controllers

**`src/FormData/BetaSignupData.php`** — mutable class for Symfony Form:
- `email` (string, required, validated as email)
- `domainCount` (?int, optional)
- `painPoint` (?string, optional, max 500 chars)

**`src/Controller/BetaSignupController.php`** — handles form submission:
- GET: renders form (can also be embedded as a Turbo Frame in other pages)
- POST: validates, dispatches RegisterBetaSignup command, shows success message
- Rate limit: max 5 signups per IP per hour

**`src/Controller/ConfirmBetaSignupController.php`** — handles email confirmation:
- Route: `/beta/confirm/{token}`
- Finds BetaSignup by token, sets `confirmedAt`
- Shows "You're confirmed!" page

### 4. Beta Signup UI Components

**Embed signup form on multiple pages:**
- Homepage final CTA section → Turbo Frame loading the signup form
- Each tool page → CTA section at bottom → same Turbo Frame
- Dedicated `/beta` signup page with full form + explanation

The form should be:
- Minimal: just email required, other fields optional
- Inline: fits naturally within the page flow
- Responsive: works on mobile
- Include domain count as a select (1, 2-5, 5-10, 10-50, 50+)
- Include pain point as optional textarea

### 5. Confirmation Email

Create an HTML email template (`templates/emails/beta_confirmation.html.twig`):
- Subject: "Confirm your Sendvery beta signup"
- Simple, branded design (match landing page colors)
- Confirmation link
- Brief "what happens next" message
- Plain text alternative

### 6. Knowledge Base

**Route structure:** `/learn/{slug}` for individual guides

**`src/Controller/KnowledgeBaseIndexController.php`:**
- Route: `/learn`
- Lists all published guides as cards (title, excerpt, category)
- Organized by category: "Email Authentication Basics", "DNS & Records", "Deliverability"

**`src/Controller/KnowledgeBaseArticleController.php`:**
- Route: `/learn/{slug}`
- Renders a specific guide from Twig templates (not database — these are static content, version-controlled)

**Guide storage:** Each guide is a Twig template in `templates/knowledge_base/articles/`. This keeps them in version control and avoids needing a CMS.

**Create 3 initial guides:**

1. **"What is DMARC and Why Should You Care?"** (`what-is-dmarc.html.twig`)
   - Plain-English DMARC explanation for non-technical readers
   - How DMARC protects against email spoofing
   - The three DMARC policies (none, quarantine, reject)
   - How to set up DMARC (step by step)
   - Link to DMARC checker tool
   - ~1000-1500 words

2. **"SPF Record: The Complete Guide"** (`spf-record-guide.html.twig`)
   - What SPF does and how it works
   - SPF syntax explained (mechanisms, qualifiers)
   - The 10 DNS lookup limit and how to stay under it
   - Common SPF mistakes and how to fix them
   - Link to SPF checker tool
   - ~1200-1800 words

3. **"Email Authentication: SPF, DKIM, and DMARC Explained"** (`email-authentication-explained.html.twig`)
   - Overview of all three protocols and how they work together
   - Why you need all three (not just one)
   - The email authentication flow (sending → DNS checks → receiving)
   - Common misconceptions
   - Link to all-in-one email auth checker
   - ~1500-2000 words

**Each guide template includes:**
- SEO meta tags (title, description)
- Open Graph tags
- Article JSON-LD structured data (schema.org Article)
- Table of contents (auto-generated from headings)
- "Related tools" sidebar linking to checker pages
- "More guides" section at the bottom
- Beta signup CTA

### 7. Update Sitemap

Add to `sitemap.xml`:
- `/beta` signup page
- `/learn` index
- All `/learn/{slug}` guide pages
- Set appropriate `<changefreq>` and `<priority>` values

### 8. Migration

Create migration for `beta_signup` table.

### 9. Tests

**Unit tests:**
- BetaSignup entity (construction, events)
- BetaSignupData validation (email required, pain point max length)
- RegisterBetaSignupHandler (creates entity, records event)
- SendBetaConfirmationEmail handler (sends email with correct token link)

**Integration tests:**
- Full signup flow: submit form → entity created → email sent → confirm token → confirmedAt set
- Duplicate email handling (allow re-signup? or reject?)
- Invalid token returns 404

**Functional tests:**
- GET /beta returns 200 with form
- POST /beta with valid data returns success
- POST /beta with invalid email returns validation error
- GET /beta/confirm/{token} with valid token returns success
- GET /learn returns 200 with guide listing
- GET /learn/what-is-dmarc returns 200 with article content
- All Knowledge Base pages have correct SEO meta tags
- Sitemap includes new pages

## Verification Checklist

- [ ] Beta signup form works end-to-end (submit → email → confirm)
- [ ] Confirmation emails are sent (check Symfony Mailer profiler)
- [ ] Signup form is embedded on homepage and tool pages
- [ ] Knowledge Base index lists all 3 guides
- [ ] Each guide renders with full content, TOC, structured data
- [ ] Sitemap updated with all new pages
- [ ] All tests pass, 100% coverage on new code
- [ ] Phase 0A is now feature-complete and deployable

## What Comes Next

**Phase 0A is complete after this stage.** The landing page, tool pages, beta signup, and Knowledge Base can be deployed to start SEO indexing.

Stage 7 begins Phase 0B (personal use): DMARC report parsing. This is the core product functionality — parsing XML aggregate reports and storing the data.
