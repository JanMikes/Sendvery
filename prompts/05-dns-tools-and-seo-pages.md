# Stage 5: DNS Tools & SEO Pages

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions.
2. `docs/09-design-and-branding.md` — **READ FULLY.** Security-first content strategy per tool page, SEO keywords table, per-page security angles table, tone guidelines, visual elements.
3. `docs/04-data-model-protocols.md` — SPF (RFC 7208), DKIM (RFC 6376), DMARC (RFC 7489) protocol details and what to validate.
4. `docs/10-libraries-and-tools.md` — Recommended DNS/SPF/DKIM libraries: `spatie/dns`, `mlocati/spf-lib`.

## What Already Exists (Stages 1-4 completed)

- Full Symfony 8 project with Docker, Tailwind + daisyUI
- Core infrastructure (EntityWithEvents, IdentityProvider, DomainEventsSubscriber)
- Identity layer (Team, User, TeamMembership, TeamFilter)
- Marketing layout, homepage with 14 sections, navigation, footer
- 8 tool page routes (placeholder templates), static pages, SEO foundation (sitemap, robots.txt, meta tags)
- Stimulus controllers (dark mode, mobile nav, scroll-to)

## What to Build

The interactive DNS checking tools that power each tool page. These are the #1 SEO driver — free tools that provide immediate value and convert visitors to beta signups.

### 1. Install DNS/SPF Libraries

```bash
composer require spatie/dns mlocati/spf-lib
```

### 2. DNS Services

All services are `readonly final class` in `src/Services/Dns/`.

**`src/Services/Dns/SpfChecker.php`:**
- Input: domain name
- Uses `spatie/dns` to fetch TXT records, `mlocati/spf-lib` to parse and validate
- Returns `SpfCheckResult` value object with: raw record, is valid, mechanism count (vs 10 limit), flattened includes, issues found, recommendations
- Detect common problems: >10 lookups, `+all`, missing record, syntax errors, old/unused includes

**`src/Services/Dns/DkimChecker.php`:**
- Input: domain name + selector (user provides selector, or try common ones: `default`, `google`, `selector1`, `selector2`, `k1`, `s1`, `dkim`)
- DNS TXT query to `<selector>._domainkey.<domain>`
- Returns `DkimCheckResult`: raw record, key exists, key type (RSA/Ed25519), key length, issues
- Detect: missing key, weak key (1024-bit RSA), malformed record

**`src/Services/Dns/DmarcChecker.php`:**
- Input: domain name
- DNS TXT query to `_dmarc.<domain>`
- Parse DMARC record: extract p=, sp=, rua=, ruf=, adkim=, aspf=, pct= tags
- Returns `DmarcCheckResult`: raw record, policy, subdomain policy, rua addresses, ruf addresses, alignment settings, pct, issues, recommendations
- Detect: p=none (not enforcing), missing rua, pct<100, missing record entirely

**`src/Services/Dns/MxChecker.php`:**
- Input: domain name
- Fetch MX records, sort by priority
- For each MX host: resolve to IP, check if port 25 responds (with short timeout), check TLS support (STARTTLS)
- Returns `MxCheckResult`: list of MX records with priority + IP + reachable + TLS status, issues

**`src/Services/Dns/EmailAuthChecker.php`:**
- Input: domain name
- Orchestrates SPF + DKIM (common selectors) + DMARC + MX checks
- Returns `EmailAuthCheckResult`: combined results, overall pass/warn/fail status

**`src/Services/Dns/DomainHealthScorer.php`:**
- Input: `EmailAuthCheckResult`
- Calculates A-F grade based on: SPF valid + under 10 lookups, DKIM key found + strong, DMARC exists + enforcing (quarantine/reject), MX reachable + TLS
- Returns `DomainHealthScore`: grade (A-F), score (0-100), per-category scores, recommendations

### 3. Value Objects (Results)

All in `src/Value/Dns/`, all `readonly final class`:

- `SpfCheckResult` — rawRecord, isValid, mechanismCount, lookupCount, issues[], recommendations[]
- `DkimCheckResult` — rawRecord, keyExists, keyType, keyBits, selector, issues[], recommendations[]
- `DmarcCheckResult` — rawRecord, policy (DmarcPolicy enum), subdomainPolicy, ruaAddresses[], rufAddresses[], adkim, aspf, pct, issues[], recommendations[]
- `MxCheckResult` — records[] (each: host, priority, ip, reachable, tlsSupported), issues[]
- `EmailAuthCheckResult` — spf, dkim, dmarc, mx (composed of above results)
- `DomainHealthScore` — grade (A-F string), score (int 0-100), categories[] (each with name + score + status)
- `DnsIssue` — readonly final class with severity (info/warning/critical), message, recommendation

### 4. Tool Page Controllers (replace placeholders from Stage 4)

Each tool page controller:
- Handles GET (show page with empty checker form) and POST (run check, show results)
- Uses a Stimulus controller for async form submission (Turbo Frame or fetch)
- Returns partial HTML for the results section (Turbo Frame compatible)

Update all 8 tool page controllers to wire up the corresponding DNS service.

### 5. Tool Page Templates (full SEO content)

Each tool page template follows the pattern from `docs/09-design-and-branding.md` → "Security-First Content Strategy":

1. **H1 + subtitle** — SEO-optimized title (from keywords table)
2. **Interactive checker** — domain input, "Check" button, results area
3. **"What is [protocol]?"** — plain-English explainer (2-3 paragraphs)
4. **"Why it matters"** — real-world consequences of misconfiguration
5. **Results display** — pass/fail indicators, issues list, recommendations (shown after check)
6. **"Security risks & attack vectors"** — from the per-page security angles table in `docs/09-design-and-branding.md`
7. **"The set-and-forget trap"** — why checking once isn't enough → CTA for monitoring
8. **FAQ** — 4-6 questions per page, implement FAQ JSON-LD structured data (schema.org)
9. **CTA** — "Want ongoing monitoring? Join the beta"
10. **Internal links** — links to related tool pages and Knowledge Base guides

**Important:** Write real, substantive SEO content for each page. Not lorem ipsum. Use the protocol specs from `docs/04-data-model-protocols.md` and the security angles from `docs/09-design-and-branding.md` to write authoritative content.

### 6. Homepage DNS Checker Widget

Wire the `DnsCheckerWidget` Twig component from Stage 4 to the `EmailAuthChecker` service. When a user enters a domain on the homepage, show a summary result (overall pass/warn/fail + grade) with links to individual tool pages for details.

### 7. Stimulus Controllers for Checkers

**`dns-checker-controller`** — Stimulus controller that:
- Submits the domain form via fetch (or Turbo Frame)
- Shows loading spinner during check
- Renders results in-place without full page reload
- Handles errors gracefully (invalid domain, DNS timeout)

### 8. Structured Data

Add to each tool page:
- FAQ JSON-LD (`@type: FAQPage`)
- HowTo JSON-LD where applicable (e.g., "How to check your SPF record")
- BreadcrumbList JSON-LD

### 9. Tests

**Unit tests:**
- `SpfChecker` — mock DNS, test various SPF records (valid, >10 lookups, +all, missing, syntax error)
- `DkimChecker` — mock DNS, test key found/missing/weak
- `DmarcChecker` — mock DNS, test p=none/quarantine/reject, missing rua, pct<100
- `MxChecker` — mock DNS and socket connections
- `DomainHealthScorer` — test grading logic with various input combinations
- All value objects — construction, immutability

**Functional tests:**
- Each tool page GET returns 200 with correct content sections
- Each tool page POST with valid domain returns results
- Each tool page POST with invalid domain returns error message
- Homepage DNS checker returns combined results
- FAQ structured data is valid JSON-LD
- SEO meta tags are correct per page (title, description)

## Verification Checklist

- [ ] All 8 tool pages show interactive DNS checker and SEO content
- [ ] DNS checks work for real domains (test with `google.com`, `github.com`)
- [ ] Results display with clear pass/fail/warning indicators
- [ ] Domain health score grades correctly (A for well-configured domains)
- [ ] Homepage DNS checker widget works
- [ ] Every page has unique `<title>`, `<meta description>`, FAQ structured data
- [ ] All tests pass with 100% coverage
- [ ] Infection mutation testing passes

## What Comes Next

Stage 6 adds the beta signup form (BetaSignup entity, confirmation email) and Knowledge Base structure with first guides. After Stage 6, Phase 0A is complete and the site can be deployed for SEO indexing.
