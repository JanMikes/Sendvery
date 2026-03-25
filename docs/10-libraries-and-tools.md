# Libraries, Tools & Open Source Resources

**Last updated:** 2026-03-24

Research into what exists that can do heavy lifting for Sendvery.

---

## Core Email/DNS Libraries

### DMARC XML Parsing

| Library | Stars | Status | Packagist | Notes |
|---------|-------|--------|-----------|-------|
| **liuch/dmarc-srg** | 275 | Active (Mar 2025) | N/A (full app) | Full DMARC report parser + viewer + summary reports. Best reference for parsing logic. |
| solarissmoke/php-dmarc | 63 | ABANDONED | solaris/php-dmarc | Archived, no updates since 2021. Avoid. |

**Recommendation:** Use **liuch/dmarc-srg** as reference for parsing logic, or write our own parser (DMARC XML schema is well-defined, not that complex).

### IMAP / POP3 Client

**Note on ext-imap:** Unbundled from PHP core in 8.4, moved to PECL. Still installable in Docker images via `pecl install imap`. Not a hard blocker, but pure PHP solutions are preferable to avoid PECL build dependencies.

**POP3 support is required** — some users may use POP3 mailboxes for DMARC reports.

| Library | Stars | Status | Packagist | IMAP | POP3 | Needs ext-imap | Notes |
|---------|-------|--------|-----------|------|------|---------------|-------|
| **Webklex/php-imap** | 436 | Active (v6.2.0, Apr 2025) | webklex/php-imap | ✓ native | ✓ (needs ext-imap) | Only for POP3 | Pure PHP for IMAP. OAuth2. IMAP IDLE. |
| **barbushin/php-imap** | 1,700+ | Active (v5.0.1) | php-imap/php-imap | ✓ | ✓ | Yes (all) | Mature, well-known. IMAP + POP3 + NNTP. Requires ext-imap for everything. |
| **Horde/Imap_Client** | — | Active | horde/imap-client | ✓ native | ✓ native | No | Most feature-complete. Fully native PHP for both IMAP and POP3. Enterprise-grade (used by Horde groupware for decades). |
| ddeboer/imap | 916 | Active (Dec 2025) | ddeboer/imap | ✓ | ✗ | Yes | Well-tested but IMAP only, requires ext-imap. |
| phpfui/php-imap | — | Active (v0.5.1, Feb 2026) | phpfui/php-imap | ✓ | ✓ | Falls back | Drop-in replacement for imap_* functions. PHP 8.2+. Uses ext-imap if available, native otherwise. |
| bartv2/imap-bundle | — | Active | bartv2/imap-bundle | ✓ | ? | ? | Symfony 8.0 bundle. Less documented. |

**Recommendation — decide during vibecoding, but top candidates:**

1. **Horde/Imap_Client** — best choice if we want both IMAP and POP3 without ext-imap. Most mature native PHP implementation. Downside: heavier dependency tree (Horde ecosystem).
2. **Webklex/php-imap + ext-imap in Docker** — use Webklex for IMAP (pure PHP), install ext-imap via PECL in Docker for POP3 fallback. Pragmatic approach.
3. **barbushin/php-imap (php-imap/php-imap) + ext-imap in Docker** — proven library, IMAP + POP3, just needs PECL ext-imap installed in the Docker image. Simple and battle-tested.

Since we control the Docker image, installing ext-imap from PECL is a one-liner in the Dockerfile. This makes barbushin/php-imap or Webklex viable even on PHP 8.5. The "no ext-imap" advantage matters less in Docker than on shared hosting.

### SPF Validation

| Library | Stars | Status | Packagist | Notes |
|---------|-------|--------|-----------|-------|
| **mlocati/spf-lib** | 67 | Active (Jun 2025) | mlocati/spf-lib | **RECOMMENDED.** Parse, build, validate SPF. Offline + online validation. All mechanisms. |
| Mika56/PHP-SPF-Check | 45 | Active (May 2025) | mika56/spfcheck | Simpler, focused on IP→domain validation. PHP 8.4 compatible. |

**Recommendation:** **mlocati/spf-lib** — most complete. Handles DNS lookup counting (the #1 SPF issue people have).

### DKIM Validation

| Library | Stars | Status | Packagist | Notes |
|---------|-------|--------|-----------|-------|
| PHPMailer/DKIMValidator | 22 | Transitional | phpmailer/dkimvalidator | Functional but maintainer notes "not actively maintaining current form." |
| angrychimp/php-dkim | 32 | Infrequent | angrychimp/php-dkim | Basic but functional. Last update Dec 2024. |

**Recommendation:** This area is thin. May need to write our own DKIM key lookup (it's just a DNS TXT query at `<selector>._domainkey.<domain>` and RSA key parsing — not complex).

### DNS Queries

| Library | Stars | Status | Packagist | Notes |
|---------|-------|--------|-----------|-------|
| **spatie/dns** | 606 | Active (Nov 2025) | spatie/dns | **RECOMMENDED for simplicity.** Clean API, Symfony 8 compatible. 2M+ installs. |
| mikepultz/netdns2 | — | Active | mikepultz/netdns2 | Advanced: DNSSEC, DoT, DoH. Pure PHP resolver. Best for production security. |
| remotelyliving/php-dns | 162 | Active | remotelyliving/php-dns | Abstraction layer, multiple backends (Google DNS, CloudFlare, local). Good for testing. |

**Recommendation:** **spatie/dns** for general lookups. Consider **netdns2** later for DNSSEC validation.

---

## Symfony & SaaS Infrastructure

### Stripe Integration

| Library | Stars | Status | Packagist | Notes |
|---------|-------|--------|-----------|-------|
| **tomedio/stripe-symfony** | New | Active (Mar 2025) | tomedio/stripe-symfony | Doctrine + API Platform integration. Subscription lifecycle, webhooks, usage-based billing. |
| getparthenon/parthenon | 173 | Active | — | Full SaaS boilerplate with billing, teams, multi-tenancy. Heavier. |

**Recommendation:** Evaluate **tomedio/stripe-symfony** — purpose-built for Symfony 8, but brand new (verify quality). Alternative: integrate Stripe PHP SDK directly (well-documented, battle-tested).

### Multi-Tenancy

| Library | Stars | Status | Packagist | Notes |
|---------|-------|--------|-----------|-------|
| RamyHakam/multi_tenancy_bundle | 104 | Active | ramyhakam/multi-tenancy-bundle | Database-per-tenant. Doctrine integration. |
| wecansync/symfony-multitenancy | — | Active (Dec 2024) | wecansync/symfony-multi-tenancy-bundle | Runtime DB switching. |

**Recommendation:** **Build our own** with Doctrine filters (DEC-016). Our model is simpler than database-per-tenant — we use shared database with `team_id` FK on every entity. A Doctrine SQL filter auto-applies `WHERE team_id = ?`. These bundles solve a different (harder) problem.

### Encryption (IMAP Credentials)

| Library | Stars | Status | Packagist | Notes |
|---------|-------|--------|-----------|-------|
| **paragonie/halite** | 1,141 | Active | paragonie/halite | **RECOMMENDED.** High-level libsodium wrapper. Battle-tested. Symmetric + asymmetric encryption. |
| sop/gcm | — | Active | sop/gcm | Pure AES-256-GCM. Direct control. |
| spomky-labs/php-aes-gcm | 72 | Active | spomky-labs/php-aes-gcm | AES-GCM with OpenSSL. |
| Native sodium_* | — | Built-in | — | PHP 8.5 has libsodium built-in. No library needed for basic use. |

**Recommendation:** **paragonie/halite** — proven, 1K+ stars, safer API than raw sodium_* calls. Critical for credential security.

### FrankenPHP + Symfony Docker

| Resource | Stars | Status | Notes |
|----------|-------|--------|-------|
| **dunglas/symfony-docker** | ~3,000 | Active | **THE starting point.** Official Symfony + FrankenPHP + Docker Compose. By Kévin Dunglas (FrankenPHP creator). PostgreSQL included. Production-ready. |

**Recommendation:** **Clone dunglas/symfony-docker and customize.** This is THE official way to start a Symfony + FrankenPHP project. Saves days of Docker config.

### Tailwind + Symfony

| Resource | Stars | Status | Packagist | Notes |
|----------|-------|--------|-----------|-------|
| **symfonycasts/tailwind-bundle** | 109 | Active | symfonycasts/tailwind-bundle | **RECOMMENDED.** No Node.js needed. Standalone Tailwind binary. AssetMapper integration. Tailwind 4.0+ support. |

**Recommendation:** **symfonycasts/tailwind-bundle** — official, zero Node.js overhead, works with AssetMapper.

### IP Blacklist Checking (DNSBL)

| Library | Stars | Status | Packagist | Notes |
|---------|-------|--------|-----------|-------|
| **jbboehr/dnsbl** | — | Active | jbboehr/dnsbl | Simplified DNSBL checking. Multiple blocklist support. 336K+ downloads. |
| nemavi/dnsbl | — | Active | nemavi/dnsbl | Custom DNSBL, whitelist, blacklist config. |

**Recommendation:** **jbboehr/dnsbl** for checking against Spamhaus, Barracuda, SORBS.

### Testing (Beyond PHPUnit)

| Tool | Stars | Status | Packagist | Notes |
|------|-------|--------|-----------|-------|
| **infection/infection** | 2,182 | Active (Jan 2026) | infection/infection | **RECOMMENDED.** Mutation testing. Goes beyond coverage — tests if tests actually catch bugs. MSI metric. |

**Recommendation:** **infection/infection** is essential for a vibecoded project with 100% coverage requirement. Coverage alone doesn't prove tests are good — mutation testing does.

---

## Frontend & Design Resources

### Dashboard UI

**Approach:** No template. Build clean, UX-focused dashboard from scratch with Tailwind CSS + Twig components. Focus on information density, usability, and clean layout. Vibecoded with strong UX/UI focus. Use daisyUI components where they fit, custom Twig components for the rest.

**Reference templates (for inspiration only, not as base):**

| Template | License | Notes |
|----------|---------|-------|
| TailAdmin | MIT | 500+ components, good layout reference. |
| Flowbite Admin | MIT | Vanilla JS components, clean design reference. |

### Landing Page Template

| Template | License | Status | Notes |
|----------|---------|--------|-------|
| Landwind | MIT | Active (2025) | SaaS landing page, Tailwind + Flowbite. Clean but somewhat generic. |
| Tailwind Toolbox | MIT | Active | Minimal starter. Needs heavy customization. |

**Recommendation:** **Build custom** using Tailwind + Flowbite components. Use Landwind as structural reference only. Landing page MUST be distinctive (DEC-027).

### Charts

| Library | License | Notes |
|---------|---------|-------|
| **ApexCharts** | MIT | **RECOMMENDED.** SVG rendering, better animations, extensive chart types. Stimulus controller available via stimulus-apexcharts. Superior for interactive dashboards. |
| Chart.js | MIT | Lighter but Canvas-based (less crisp). Simpler but fewer features. |

**Recommendation:** **ApexCharts** — richer for email monitoring dashboards. SVG rendering, zoom/pan, better Stimulus integration.

### Icons

| Library | License | Count | Notes |
|---------|---------|-------|-------|
| **Heroicons** | MIT | 292 | By Tailwind team. Native Tailwind sizing. Best for core UI. |
| **Lucide** | MIT | 1,500+ | Larger set. Better for domain-specific icons (email providers, etc.). |

**Recommendation:** **Heroicons** for core UI + **Lucide** for specialized icons.

### Component Libraries (Server-Rendered)

| Library | Stars | License | Notes |
|---------|-------|---------|-------|
| **daisyUI** | 40,500 | MIT | **RECOMMENDED.** Pure HTML/CSS, no framework lock-in. Semantic classes. Dark mode. Works perfectly with Twig. |
| FlyonUI | — | MIT | 80+ components, HTML-first. Clean design. Tailwind v4 compatible. |
| Flowbite | — | MIT | 400+ components, vanilla JS. Laravel/PHP support. |

**Recommendation:** **daisyUI** as primary component system. Supplement with Flowbite for specific components (charts, modals).

### SVG Illustrations

| Resource | License | Notes |
|----------|---------|-------|
| **unDraw** | Free (no attribution) | Professional, minimalist. Color customizer. Perfect for empty states, onboarding, landing page. |
| Humaaans | CC0 | Mix-and-match human figures. Good for team/about pages. |
| DrawKit | MIT | Hand-drawn aesthetic, includes animations. |
| Open Doodles | CC0 | Sketchy, playful style. |

**Recommendation:** **unDraw** as primary. Customize colors to match brand.

---

## Recommended Composer Packages (Initial)

```json
{
    "require": {
        "webklex/php-imap": "^6.2",
        "mlocati/spf-lib": "^3.3",
        "spatie/dns": "^2.7",
        "paragonie/halite": "^5.0",
        "symfonycasts/tailwind-bundle": "^0.12",
        "api-platform/core": "^4.0",
        "jbboehr/dnsbl": "^1.0"
    },
    "require-dev": {
        "infection/infection": "^0.32"
    }
}
```

## Key Takeaway

The PHP ecosystem has solid coverage for everything Sendvery needs. The biggest wins are:
1. **dunglas/symfony-docker** — saves days of Docker setup
2. **Webklex/php-imap** — pure PHP IMAP (critical since ext-imap is gone in PHP 8.5)
3. **mlocati/spf-lib** — SPF validation done right
4. **paragonie/halite** — credential encryption you can trust
5. **infection/infection** — mutation testing for a vibecoded project is a game-changer
6. **daisyUI** — server-rendered component library that actually works with Twig
7. **ApexCharts** — rich charting with Stimulus integration
