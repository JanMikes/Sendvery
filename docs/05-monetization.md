# Monetization & Pricing

**Last updated:** 2026-05-22

> **Current state (May 2026):** Stripe is wired end-to-end — pricing-page CTAs go to `dashboard_billing_upgrade` and the webhook handler reconciles plan state. The fake-door `/request-access` flow was removed on 2026-05-22; new sign-ups go straight to Stripe Checkout when the dashboard products + prices exist. AI variants gate on `ANTHROPIC_API_KEY` presence.
>
> The Stripe-dashboard setup runbook lives in `docs/14-stripe-setup.md`. The detailed implementation status lives in `docs/13-pricing-implementation-plan.md`. See DEC-052..DEC-057 for the pricing-model decisions that supersede DEC-024 and DEC-025.
>
> **Price display policy:** all advertised hosted prices are USD and shown as "VAT included where applicable". Jan is OSVČ in CZ and below the VAT threshold, so we collect a flat sticker price and do not break out VAT. If we cross the CZ threshold or expand to EU B2C scale, enable Stripe Tax + OSS at that point (no code changes required).

---

## Pricing Model

Four public tiers (Free + 3 paid) plus Enterprise. Each paid tier has a paired internal **AI variant** sold via a single "Add AI Insights" toggle on the pricing page. Two billing cadences: monthly and annual. Annual is exactly **2 months free** (`annual_total = 10 × monthly`) — clean math, clean marketing.

### Tier matrix

| | **Free** | **Personal** | **Pro** | **Business** |
|---|---|---|---|---|
| **Monthly price** | $0 | $5.99 | $23.99 | $59.99 |
| **+ AI (monthly)** | reach out | $9.99 | $33.99 | $79.99 |
| **Annual /mo** | $0 | $4.99 | $19.99 | $49.99 |
| **+ AI (annual /mo)** | reach out | $8.99 | $29.99 | $69.99 |
| **Annual total** | — | $59.88 | $239.88 | $599.88 |
| **Savings vs. monthly** | — | $12 / yr | $48 / yr | $120 / yr |
| **Domains** | 1 | 5 | 20 | 50 |
| **Reports/mo** | 100 | 1,000 | 10,000 | 50,000 |
| **Seats** | 1 | 1 | 3 | 10 |
| **Retention** | 30 days | 1 year | 2 years | unlimited |
| **DMARC + DNS monitoring** | ✓ | ✓ | ✓ | ✓ |
| **Managed DMARC + auto-drive** (CNAME, DEC-058) | – | ✓ | ✓ | ✓ |
| **Real-time alerts** | – | ✓ | ✓ | ✓ |
| **Blacklist monitoring** | – | ✓ | ✓ | ✓ |
| **Sender inventory** | – | ✓ | ✓ | ✓ |
| **Email HTML analysis** | – | ✓ | ✓ | ✓ |
| **API + webhooks** | – | – | ✓ | ✓ |
| **White-label PDF reports** | – | – | – | ✓ |
| **AI Insights** (when toggled on) | reach out (manual) | ✓ + 50 on-demand/mo | ✓ + 200 on-demand/mo | ✓ + 500 on-demand/mo |

**AI delta (constant across cadences):** `+$4` (Personal) · `+$10` (Pro) · `+$20` (Business).
AI is not discounted on annual — it's a metered cost we incur monthly, so the surcharge is the same per month either way. The annual savings story stays clean: **Save $12 / $48 / $120 per year**, regardless of AI.

### Self-Hosted — always free (open source, AGPL-3.0)

- Unlimited everything (the software is free; AGPL keeps it that way).
- Self-hosters supply their own Anthropic API key for AI features.
- Run on your own infrastructure (Docker image, single binary via FrankenPHP).
- Community support (GitHub issues).
- This is a marketing feature, not a limitation — builds trust, drives adoption.

### Enterprise — "Need more? Contact us"

- Custom domain/seat/report limits.
- SLA, dedicated support, custom integrations.
- AI included.
- Single line below the four cards on the pricing page; no card-style display.

### Internal-only plans

`SubscriptionPlan::Unlimited` remains as a **staff grant** (set via `app:team:set-plan`) for partners, internal teams, and edge support cases. Not exposed in any marketing or self-serve UI; not purchasable via Stripe.

---

## Naming rationale

| Tier | Reads as | Why this name |
|---|---|---|
| **Personal** | "for my own stuff" | Owner has 1–5 domains: personal site, side projects, a freelance domain. |
| **Pro** | "for professionals" | Universal SaaS power-user signal. Includes the freelancer-with-many-domains persona without invoking team/seats. |
| **Business** | "your business depends on this" | Industry-standard upper tier name (Cloudflare, Linear, Notion). Signals stakes, not seats. |

Deliberately avoided: **Studio / Team** (too org-specific), **Starter** (implies transient — many solo users stay forever), **Hobby** (clashes with "your domain sends real email").

See **DEC-052** for the full naming decision.

---

## Pricing-page UX

### Layout

Four cards (Free / Personal / Pro / Business) in a 4-column grid. Pro carries a "Best value" badge that never moves between toggle states. Enterprise gets a single line beneath the grid: *"Need more? Talk to us →"*.

### Two toggles, both above the grid

```
┌───────────────────────────────────────────────┐
│   Billing:   ( Monthly )  [ Annual −2 months ]│  ← pill, annual default + green badge
│                                                │
│   ☐  Add AI Insights                          │  ← checkbox with subtle sparkle icon
└───────────────────────────────────────────────┘
```

- **Billing cadence:** Annual is the default (drives conversion). Monthly is one tap away. Annual badge says "−2 months" (truer than "−17%") and pulses gently for ~1s on first page load.
- **AI Insights:** off by default. When `ANTHROPIC_API_KEY` is set, every paid card grows the AI line item and price flips to the AI variant; when the key isn't set, the toggle is hidden entirely. AI is not available on Free in any case — Free users interested in AI can email enterprise/sales.
- **Toggle state persists in `localStorage`** so a user who chose Monthly on visit 1 isn't reset to Annual on visit 2.

### Card price display (Personal example)

Annual / no AI (default landing state):
```
Personal
$4.99 /mo       ← BIG, the hero number (per-month equivalent when billed annually)
~~$5.99~~       ← strikethrough monthly rate, smaller, gray
Billed annually at $59.88
Save $12/year   ← green chip
[ Get Personal → ]
```

Monthly / no AI:
```
Personal
$5.99 /mo       ← BIG
Billed monthly · Cancel anytime
[ Get Personal → ]
```

Annual / +AI:
```
Personal + AI
$8.99 /mo
~~$9.99~~
Billed annually at $107.88
✨ AI Insights · 50 on-demand explanations/mo
[ Get Personal → ]
```

**Design rules:**
- The big focal number is always the **monthly-equivalent price** ($/mo). The yearly total goes to fine print. Users compare $/mo, not $/yr.
- Same baseline, same font size for the hero number across both toggle states — eye stays anchored on one position.
- Strikethrough only appears in Annual mode (showing the better-than-monthly deal).
- Price flip animates with a 200ms scale-down/scale-up, not a hard swap.
- AI Insights line item slides into the feature list when enabled; don't reflow the whole card.

### Mobile

Toggles stack vertically above cards; cards become single-column. Same data, same logic.

---

## What AI Insights does

When AI Insights is enabled on a plan, **the following run automatically**:

1. **Weekly AI digest email** (Monday ~9am team-local) — Sonnet generates a plain-English summary: pass-rate trend, top failing sender, anomalies worth attention, recommended next actions. One call per team per week.
2. **Anomaly explanations** — when failure rate spikes (>2σ from rolling baseline) or a new sender appears, AI inline-explains it on the dashboard. Triggered, not polled.
3. **Remediation guidance** — when DNS checks find a problem, AI writes step-by-step fix instructions specific to the user's DNS provider / sender configuration (detected from records).
4. **Smart sender labeling** — Haiku auto-labels new sender IPs ("this is Mailgun infrastructure") so the inventory doesn't look like noise.

**Rate-limited (the cost lever):**

5. **"Explain this" button on every report/alert** — on-demand Sonnet call. Monthly quotas:
   - Personal: 50 explanations/mo
   - Pro: 200 explanations/mo
   - Business: 500 explanations/mo
   - Counter visible in dashboard ("47 of 200 used this month").

**Deliberately NOT done:** auto-summarize every parsed report. ~95% of DMARC reports are routine "everything passed for known senders" — zero user value, linear cost. AI summarizes *patterns and exceptions*.

### AI cost rationale (back-of-envelope)

Per-feature unit cost (Anthropic public pricing; Sonnet 4.6 unless noted):

| Feature | Model | Frequency | Approx tokens (in/out) | Cost/call |
|---|---|---|---|---|
| Weekly digest | Sonnet | 1/week/team | 8k / 2k | $0.054 |
| Anomaly explanation | Sonnet | triggered | 5k / 1k | $0.030 |
| On-demand "explain this" | Sonnet | quota-capped | 5k / 1k | $0.030 |
| Remediation guidance | Sonnet | on DNS failure | 3k / 1.5k | $0.032 |
| Smart sender labeling | Haiku | per new IP | 1k / 0.1k | $0.0015 |

Per-team monthly cost ceiling (full quota use):

| Tier | Digest | Anomalies | On-demand cap | Remediation | Labels | **Total** |
|---|---|---|---|---|---|---|
| Personal | $0.22 | ~$0.45 | $1.50 (50×) | ~$0.10 | ~$0.10 | **~$2.40** |
| Pro | $0.22 | ~$1.50 | $6.00 (200×) | ~$0.30 | ~$0.30 | **~$8.30** |
| Business | $0.22 | ~$4.50 | $15.00 (500×) | ~$0.60 | ~$0.80 | **~$21.10** |

Margin at full quota use: Personal 1.7×, Pro ~1.2×, Business ~1.0× (skinny on purpose — power-tier users tolerate it). If Business margin bleeds in practice, tighten the on-demand quota or raise to $74.99/+$25 — don't launch at thinner.

### Future optionality

If Anthropic API costs drop significantly, fold AI into all paid tiers as a value bump → *"We just made AI Insights free for all paid plans!"* = great retention email + press cycle.

---

## Domain extras (deferred to Phase 2)

Per-domain add-ons (Stripe quantity-based subscription items, prorated) are intentionally **not** in the launch model. Rationale: extras add real Stripe complexity (per-tier price IDs, manage-extras UI, upgrade-nudge logic) and the launch matrix already covers the 95% case. Add when users actually push against the tier caps and the data tells us the right price/cap structure.

Sketch for when we add them (don't build yet):

| Tier | Per-domain extra | Cap (extras only) |
|---|---|---|
| Personal | +$2/mo per domain | +5 (i.e., max 10 total) |
| Pro | +$1.50/mo per domain | +15 (i.e., max 35 total) |
| Business | +$1/mo per domain | unlimited |

Tier-decreasing price is the nudge — at the boundary, upgrading to the next tier is always the better deal. Annual extras follow the same 10/12 math.

See **DEC-056** for the deferral decision.

---

## Stripe SKU layout

12 base price IDs (3 paid tiers × 2 AI variants × 2 cadences), all under three Products in Stripe. Plus the Free tier (no Stripe price) and Unlimited (no Stripe price, staff-grant only).

```
Stripe Products & Prices:

Product: "Sendvery Personal"
  Price: $5.99/mo  (recurring monthly)  → STRIPE_PRICE_PERSONAL_MONTHLY
  Price: $59.88/yr (recurring yearly)   → STRIPE_PRICE_PERSONAL_ANNUAL
  Price: $9.99/mo  (with AI, monthly)   → STRIPE_PRICE_PERSONAL_AI_MONTHLY
  Price: $107.88/yr (with AI, yearly)   → STRIPE_PRICE_PERSONAL_AI_ANNUAL

Product: "Sendvery Pro"
  Price: $23.99/mo                      → STRIPE_PRICE_PRO_MONTHLY
  Price: $239.88/yr                     → STRIPE_PRICE_PRO_ANNUAL
  Price: $33.99/mo                      → STRIPE_PRICE_PRO_AI_MONTHLY
  Price: $359.88/yr                     → STRIPE_PRICE_PRO_AI_ANNUAL

Product: "Sendvery Business"
  Price: $59.99/mo                      → STRIPE_PRICE_BUSINESS_MONTHLY
  Price: $599.88/yr                     → STRIPE_PRICE_BUSINESS_ANNUAL
  Price: $79.99/mo                      → STRIPE_PRICE_BUSINESS_AI_MONTHLY
  Price: $839.88/yr                     → STRIPE_PRICE_BUSINESS_AI_ANNUAL
```

Stripe metadata on every subscription:
- `team_id` (UUID)
- `plan` (enum value, e.g. `personal_ai`)
- `interval` (`monthly` | `annual`)

Tax: Stripe Tax stays off until we cross the CZ VAT threshold or expand. Until then, sticker prices are inclusive — no breakout on receipts.

Plan limits and AI quotas are enforced in **application logic** (`PlanLimits` + `PlanEnforcement` + AI gating decorator), not in Stripe.

---

## Internal model

`App\Value\SubscriptionPlan` (expanded; see DEC-053):

```php
enum SubscriptionPlan: string
{
    case Free = 'free';
    case Personal = 'personal';
    case PersonalAi = 'personal_ai';
    case Pro = 'pro';
    case ProAi = 'pro_ai';
    case Business = 'business';
    case BusinessAi = 'business_ai';
    case Unlimited = 'unlimited'; // staff grant only
}
```

Helper methods on the enum:
- `hasAi(): bool` — true for `*Ai` variants and `Unlimited`.
- `baseTier(): self` — `PersonalAi → Personal`, etc. Useful for "show me the base price" comparisons.
- `withAi(): self` — `Personal → PersonalAi`. Throws if no AI variant exists (Free, Unlimited).
- `withoutAi(): self` — inverse.
- `tierGroup(): string` — returns `'free' | 'personal' | 'pro' | 'business' | 'unlimited'` for UI grouping.

`App\Value\BillingInterval` (new):

```php
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
}
```

`Team` entity gains `billingInterval: ?BillingInterval` (nullable, null for Free/Unlimited).

---

## Smart upgrade nudges (implemented in app)

- **Personal user nearing 5 domains** → banner: "Upgrade to Pro for 20 domains + AI on-demand + API access."
- **Personal user without AI views a report detail page** → blurred AI preview teaser: "Want this explained in plain English? Add AI Insights for $4/mo."
- **Pro user nearing 20 domains** → banner: "Upgrade to Business for 50 domains, 10 seats, and white-label reports."
- **Pro user without AI** → same blurred AI teaser as Personal, but with +$10/mo pricing.
- **Free user hitting the 1-domain or 100-report cap** → upgrade CTA: "Unlock 5 domains and 1,000 reports with Personal for $5.99/mo (or $4.99/mo annual)."
- **Any AI on-demand quota at 80%** → in-dashboard nudge: "You've used 160 of 200 AI explanations this month. Upgrade to Business for 500/mo."
- **Business plan with high overage attempts (e.g., bouncing off 50 domains repeatedly)** → "Looks like you're outgrowing Business. Let's talk about Enterprise."

---

## Competitive positioning

| | Sendvery Personal | Sendvery Personal + AI | dmarcian Basic | EasyDMARC Starter | PowerDMARC Basic |
|---|---|---|---|---|---|
| **Price** | **$4.99–$5.99/mo** | **$8.99–$9.99/mo** | $19.99/mo | $35.99/mo | $8/mo |
| **Domains** | 5 | 5 | 2 | 2 | 2 |
| **AI analysis** | No | **Yes** | No | No | No |
| **Self-hosted** | Free forever | Free forever | No | No | No |

Marketing hooks:
- *"DMARC monitoring from $5.99/mo — or $4.99/mo with annual billing."*
- *"The only DMARC tool with AI insights starting at $8.99/mo."*
- *"Free forever if you self-host."*

---

## Revenue model considerations

**Why subscription works:**
- Ongoing monitoring = recurring value.
- AI analysis cost is per-use (Anthropic API).
- Blacklist checks require ongoing DNS queries to RBLs.
- Data retention has storage costs.
- IMAP polling + central inbox = ongoing compute.

**Break-even thinking:**
- At $5–10/mo per Personal user, ~100–200 paying users for meaningful coffee-money revenue.
- At $20–34/mo per Pro user, ~50–100 users move the needle.
- At $50–80/mo per Business user, fewer but harder to acquire — and stickiest.

## Annual savings story (the marketing headline)

> **"Save up to 2 months/year — every year — with annual billing."**

- Personal annual: **$4.99/mo** ($59.88/yr — save $12/yr)
- Pro annual: **$19.99/mo** ($239.88/yr — save $48/yr)
- Business annual: **$49.99/mo** ($599.88/yr — save $120/yr)

Round-dollar savings read better than percentages in copy.

---

## What you pay for (hosted tiers)

The software is free (AGPL). Hosted tiers sell:
- Managed infrastructure (no Docker/server to maintain).
- Automatic updates.
- Our hosted IMAP receiving address (no IMAP setup needed).
- Email support / priority support on Pro+.
- Data retention & backups.
- AI inference cost coverage (when AI Insights is on).

---

## Go-to-Market: Fake Door → Closed Beta → Launch

The original 4-phase plan still stands. The pricing model above slots into **Phase 3 (Public Launch)** — that's when self-serve checkout flips on. The Stripe-dashboard setup is `docs/14-stripe-setup.md`; the build status is `docs/13-pricing-implementation-plan.md`.

### Phases (summary)

- **Phase 0 — Fake-door landing page** (current state): SEO indexing, lead capture via `/request-access`, real DNS checker tools.
- **Phase 1 — Personal use**: Jan dogfoods the product on his own domains. No auth, no billing.
- **Phase 2 — Closed beta**: invited users from email list, free, magic-link auth.
- **Phase 3 — Public launch**: Stripe live, all four tiers purchasable (Free without checkout), AI toggle visible but AI variants gated behind "Coming soon" until DEC-057 stubs are replaced with real AI.
- **Phase 4 — AI & extras**: real AI implementation replaces stubs; domain extras get built; Enterprise gets a sales motion.

---

*See `docs/07-decisions-log.md` (DEC-050, DEC-052..DEC-057) for the decisions behind this model, and `docs/13-pricing-implementation-plan.md` for the step-by-step build plan.*
