# Fake-Door → Live Stripe — Cutover Runbook

**Last updated:** 2026-05-22
**Related:** `docs/05-monetization.md` (canonical pricing), `docs/13-pricing-implementation-plan.md` (build plan), `docs/07-decisions-log.md` (DEC-050 fake-door rationale, DEC-052..DEC-057 pricing model).

---

## Why this exists

Sendvery shipped Stripe integration in Stage 13 (subscriptions, checkout sessions, webhooks, customer portal, plan enforcement). All of it works and is covered by integration tests.

But we are not ready to take payments yet — we want to qualify beta users one by one, validate which plan tier they actually need, and avoid the refund / "can I cancel?" overhead that comes with charging strangers before the product has spent time in the wild.

So as of 2026-05-14, **the Stripe code is intact but the user-facing CTAs route into a contact form instead of into checkout**. This document is the runbook for flipping the CTAs back when we're ready — under the **new pricing model** finalized 2026-05-22 (DEC-052..DEC-057), which the original Stripe wiring does NOT yet reflect.

> **Important:** the old fake-door state (DEC-050, May 2026) used a 2-tier model (Personal $5.99, Team $49.99) with a single AI add-on. The new pricing model has **4 tiers × 2 AI variants × 2 cadences = 12 Stripe price IDs**, plus AI deliberately *not yet purchasable* (see DEC-057). Steps below assume the implementation plan in `docs/13-pricing-implementation-plan.md` has been executed first.

---

## What was changed (the fake door — for reference)

Every change is a CTA-level swap. No Stripe code was deleted, gated, or commented out.

### 1. `templates/components/PricingTable.html.twig`
- Added a "private beta — request access" banner at the top.
- Personal and Team plan buttons now link to `path('request_beta_access', {plan: 'personal'|'team'})`.
- Free Hosted plan button now links to `path('beta_signup')` ("Join the wait list") because there's no self-serve registration yet either.
- Added a `VAT included where applicable` footnote under each paid sticker price.

### 2. `templates/dashboard/billing.html.twig`
- Removed the "Manage subscription" / "Upgrade Your Plan" Stripe-driven sections.
- Replaced with a "Paid plans are in private beta" card that has two `request_beta_access` CTAs.
- Note: the billing **page itself** still works — `BillingController` and `GetBillingOverview` still report current plan / domain count / member count.

### 3. `templates/dashboard/domain_add.html.twig`
- The "Domain limit reached" banner's "Upgrade plan" button now goes directly to `request_beta_access` with the next-tier plan pre-selected.

### 4. New, additive (no replacement)
- `src/Entity/BetaAccessRequest.php` — persists every form submission.
- `src/Message/RequestBetaAccess.php` + `src/MessageHandler/RequestBetaAccessHandler.php` — CQRS command.
- `src/Events/BetaAccessRequested.php` + `src/MessageHandler/SendBetaAccessNotification.php` — sends two emails via Symfony Mailer.
- `src/Controller/RequestBetaAccessController.php` — `/request-access` route.
- Templates + migration + env var `BETA_REQUESTS_EMAIL`.

### 5. What was NOT touched (still live, will need updates per DEC-053/054)
- `src/Services/Stripe/SubscriptionManager.php` — needs `BillingInterval` parameter on `createCheckoutSession()`.
- `src/Services/Stripe/PlanEnforcement.php`, `PlanLimits.php` — needs new tier limits + reports/AI quota tracking.
- `src/Services/Stripe/StripePriceResolver.php` — needs `(plan, interval)` lookup.
- `src/Controller/Dashboard/UpgradePlanController.php` — needs new `purchasablePlans` list + interval param.
- `src/Controller/Webhook/StripeWebhookController.php` — needs to handle `customer.subscription.updated` for AI-toggle / cadence changes.
- Routes `dashboard_billing_upgrade`, `dashboard_billing_manage`, `dashboard_billing_success`, `dashboard_billing_cancel`, `stripe_webhook` — still exist.
- All Stripe-related test suites — still pass against old model; need to be rewritten against new tiers as part of the cutover.

---

## How to switch back (under the new model)

Estimated effort: **~2–4 days of focused work** (NOT 30 minutes — the original estimate was for the old 2-tier model). The bulk of the work is the new pricing implementation; see `docs/13-pricing-implementation-plan.md` for the detailed plan. Once that's done, the cutover steps below are short.

### Step 0 — Prerequisites (assume done before this runbook starts)

The implementation plan in `docs/13-pricing-implementation-plan.md` should be complete:
- `SubscriptionPlan` enum expanded with 3 paid + 3 AI + Free + Unlimited.
- `BillingInterval` enum added.
- `Team.billing_interval` column migrated.
- `PlanLimits` expanded with new tier values, reports/mo cap, retention, AI quota.
- `StripePriceResolver` takes `(plan, interval)`.
- `SubscriptionManager::createCheckoutSession()` takes `(team, plan, interval)`.
- `UpgradePlanController` accepts `?interval=monthly|annual&ai=true|false`.
- Webhook handles `customer.subscription.updated`.
- AI stub infrastructure in place (`App\Services\Ai`).
- Pricing-page Stimulus controller + new card markup deployed.
- Test suite green against the new model.

### Step 1 — Create Stripe products and prices (test mode first)

In the Stripe dashboard, create three Products: **"Sendvery Personal"**, **"Sendvery Pro"**, **"Sendvery Business"**.

Add the 12 prices listed in `docs/05-monetization.md` § "Stripe SKU layout":

| Product | Price | Billing | Stripe Lookup Key (recommended) |
|---|---|---|---|
| Sendvery Personal | $5.99 | monthly | `sendvery_personal_monthly` |
| Sendvery Personal | $59.88 | yearly | `sendvery_personal_annual` |
| Sendvery Personal | $9.99 | monthly | `sendvery_personal_ai_monthly` |
| Sendvery Personal | $107.88 | yearly | `sendvery_personal_ai_annual` |
| Sendvery Pro | $23.99 | monthly | `sendvery_pro_monthly` |
| Sendvery Pro | $239.88 | yearly | `sendvery_pro_annual` |
| Sendvery Pro | $33.99 | monthly | `sendvery_pro_ai_monthly` |
| Sendvery Pro | $359.88 | yearly | `sendvery_pro_ai_annual` |
| Sendvery Business | $59.99 | monthly | `sendvery_business_monthly` |
| Sendvery Business | $599.88 | yearly | `sendvery_business_annual` |
| Sendvery Business | $79.99 | monthly | `sendvery_business_ai_monthly` |
| Sendvery Business | $839.88 | yearly | `sendvery_business_ai_annual` |

Tip: use Stripe **Lookup Keys** instead of raw price IDs in env vars — they're stable across test/live mode swaps and resilient to recreating prices. `StripePriceResolver` should resolve via lookup key, falling back to env var.

### Step 2 — Configure secrets / env vars

Add to production `.env` (or GitHub Environment secrets):

```bash
# Existing
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PUBLISHABLE_KEY=pk_live_...

# New — 12 price IDs (or lookup keys)
STRIPE_PRICE_PERSONAL_MONTHLY=price_...
STRIPE_PRICE_PERSONAL_ANNUAL=price_...
STRIPE_PRICE_PERSONAL_AI_MONTHLY=price_...
STRIPE_PRICE_PERSONAL_AI_ANNUAL=price_...
STRIPE_PRICE_PRO_MONTHLY=price_...
STRIPE_PRICE_PRO_ANNUAL=price_...
STRIPE_PRICE_PRO_AI_MONTHLY=price_...
STRIPE_PRICE_PRO_AI_ANNUAL=price_...
STRIPE_PRICE_BUSINESS_MONTHLY=price_...
STRIPE_PRICE_BUSINESS_ANNUAL=price_...
STRIPE_PRICE_BUSINESS_AI_MONTHLY=price_...
STRIPE_PRICE_BUSINESS_AI_ANNUAL=price_...

# New — feature flags
SENDVERY_AI_PURCHASABLE=false   # DEC-057: AI variants gated until real impl ships
```

Register the live webhook endpoint in Stripe dashboard: `https://sendvery.com/webhook/stripe`. Subscribe to events: `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_failed`.

### Step 3 — Flip the CTAs

Three template edits. Mirror images of the original fake-door changes, but pointed at the new checkout route that accepts interval + AI parameters.

```diff
# templates/components/PricingTable.html.twig (after rewrite per implementation plan)
- <a href="{{ path('request_beta_access', {plan: 'personal'}) }}" class="btn btn-primary btn-sm w-full">Request beta access</a>
+ <a data-pricing-target="cta"
+    data-base-plan="personal"
+    href="{{ path('dashboard_billing_upgrade', {plan: 'personal', interval: 'annual'}) }}"
+    class="btn btn-primary btn-sm w-full">Get Personal</a>
```

The Stimulus `pricing_controller` rewrites the `href` on toggle change to reflect the user's chosen `(plan, interval, ai)` combination. CTAs without JS still work — they default to the annual / no-AI variant.

Same swap for Pro and Business. In `templates/dashboard/billing.html.twig`, restore the "Upgrade Your Plan" copy (or repurpose the new card; both work).

For `templates/dashboard/domain_add.html.twig`, point the "Upgrade plan" CTA back at the next-tier upgrade URL (e.g., Personal → Pro at `dashboard_billing_upgrade?plan=pro&interval=annual`).

### Step 4 — AI toggle posture (per DEC-057)

When `SENDVERY_AI_PURCHASABLE=false`:
- AI toggle is visible on the pricing page.
- When AI is toggled on, paid-card CTAs change from "Get Pro" to "Notify me when AI ships →" and open the existing `request_beta_access` form with `interestType=ai`.
- The Free card always shows the AI-curious invitation when AI is toggled on, regardless of the flag.

When `SENDVERY_AI_PURCHASABLE=true` (future cutover, after real `AnthropicAiInsightsService` ships):
- Paid AI cards become real checkout CTAs.
- `StripePriceResolver` stops rejecting `*Ai` plans.

### Step 5 — Update doc banners

- Remove the `> **Current state (May 2026)...**` banner at the top of `docs/05-monetization.md`.
- Remove the "private beta — request access below" banner at the top of `PricingTable.html.twig`.
- Keep the "VAT included where applicable" footnote until Stripe Tax is enabled (still relevant).

### Step 6 — Decide what to do with the beta request form

Recommendation: **keep it live**, repurpose:
- Rename CTA to "Talk to sales" for Enterprise-curious visitors.
- Add `interestType` enum to `BetaAccessRequest` (`personal`, `pro`, `business`, `enterprise`, `ai_curious`, `self_hosted_support`).
- Drop `/request-access` only when sales motion has a real CRM destination.

### Step 7 — Update fixtures and integration tests

Update `tests/Integration/Controller/BillingPagesTest.php` and `tests/Integration/Controller/Stage14PagesTest.php` to expect the new copy:
- Replace "Paid plans are in private beta" / "Request beta access" assertions with "Get Personal" / "Get Pro" / "Get Business".
- Add tests for the AI toggle states (AI off vs. on, free vs. paid).
- Add tests for billing interval toggle behavior.
- Add a test that `SENDVERY_AI_PURCHASABLE=false` routes AI CTAs to `request_beta_access` instead of checkout.

### Step 8 — Webhook smoke test (Stripe CLI)

```bash
stripe listen --forward-to localhost/webhook/stripe
# In another shell:
stripe trigger checkout.session.completed
stripe trigger customer.subscription.updated  # AI-toggle / cadence switch
stripe trigger customer.subscription.deleted
stripe trigger invoice.payment_failed         # downgrade-to-Free flow
```

Verify: team's `plan` flips to the new value, `billing_interval` updates, `stripe_subscription_id` saved, `UpgradeTeamPlan` / `DowngradeTeamPlan` commands dispatched.

### Step 9 — Email existing beta-access requests (one-shot launch announcement)

Pull all `BetaAccessRequest` rows where `created_at >= '2026-05-14'` and email them: *"Sendvery is now live. Use this link to claim your account: [magic-link]. You'll find your requested plan ([requested_plan]) ready to subscribe."*

Consider including a coupon code via Stripe Promotion Codes for first-month or 20% off — these are warm leads that took the time to reach out.

### Step 10 — Update marketing copy

Sweep these locations for old prices ($5.99 Team @ $49.99) and update to the new model:
- `README.md`
- `templates/marketing/*` (hero, features, knowledge base CTAs)
- `templates/learn/*` (knowledge-base CTAs at end of each page)
- Open-source page (`templates/about/open_source.html.twig`)
- SEO meta descriptions

A `grep -r '5.99\|49.99' templates/ README.md docs/` sweep catches most. The implementation plan includes a "marketing copy update" checklist.

---

## Inventory of files that depend on the fake door (current state)

Until the implementation plan is executed, these are the only files holding the fake door in place. After the cutover, they all need touched:

- `templates/components/PricingTable.html.twig` — full rewrite for new 4-card layout + toggles
- `templates/dashboard/billing.html.twig` — show new plan options + AI toggle
- `templates/dashboard/domain_add.html.twig` — update upgrade-target plan
- `tests/Integration/Controller/BillingPagesTest.php` — rewrite copy assertions
- `tests/Integration/Controller/Stage14PagesTest.php` — same
- `tests/Integration/Controller/RequestBetaAccessTest.php` — extend with `interestType` cases
- `src/Controller/Dashboard/UpgradePlanController.php` — accept interval param, broaden `purchasablePlans`

A `grep -rn 'request_beta_access\|RequestBetaAccess' src/ templates/ tests/` finds the current full surface area.

---

## Rollback plan

If something blows up post-cutover:

1. Set `SENDVERY_AI_PURCHASABLE=false` and revert pricing-page CTAs to `request_beta_access` — same templates, just swap the `href` targets back. Stripe customers already subscribed are unaffected; new conversions stop.
2. If Stripe billing is generally broken, point pricing CTAs back at `request_beta_access` for ALL plans (not just AI). Existing subscriptions continue to bill through Stripe normally — only new conversions are blocked.
3. Keep webhooks listening throughout — never block webhook processing, even during rollback.
4. Refund any customer that asks via Stripe dashboard (manual). Set a Sentry alert on `Stripe\Exception\*` to catch issues early.
