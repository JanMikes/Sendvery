# Fake-Door Stripe — Switching Real Checkout Back On

**Last updated:** 2026-05-14
**Related:** DEC-050 (decision), `05-monetization.md` (pricing model), `13-stripe-billing.md` is the original build prompt.

---

## Why this exists

Sendvery shipped Stripe integration in Stage 13 (subscriptions, checkout sessions, webhooks, customer portal, plan enforcement). All of it works and is covered by integration tests.

But we are not ready to take payments yet — we want to qualify beta users one by one, validate which plan tier they actually need, and avoid the refund / "can I cancel?" overhead that comes with charging strangers before the product has spent time in the wild.

So as of 2026-05-14, **the Stripe code is intact but the user-facing CTAs route into a contact form instead of into checkout**. This document is the runbook for flipping the CTAs back when we're ready.

---

## What was changed (the fake door)

Every change is a CTA-level swap. No Stripe code was deleted, gated, or commented out.

### 1. `templates/components/PricingTable.html.twig`
- Added a "private beta — request access" banner at the top.
- Personal and Team plan buttons now link to `path('request_beta_access', {plan: 'personal'|'team'})`.
- Free Hosted plan button now links to `path('beta_signup')` ("Join the wait list") because there's no self-serve registration yet either.
- Added a `VAT included where applicable` footnote under each paid sticker price.

### 2. `templates/dashboard/billing.html.twig`
- Removed the "Manage subscription" / "Upgrade Your Plan" Stripe-driven sections.
- Replaced with a "Paid plans are in private beta" card that has two `request_beta_access` CTAs (Personal and Team, with `source: 'dashboard-billing'`).
- Note: the billing **page itself** still works — `BillingController` and `GetBillingOverview` still report current plan / domain count / member count.
- Same VAT footnote applied.

### 3. `templates/dashboard/domain_add.html.twig`
- The "Domain limit reached" banner's "Upgrade plan" button now goes directly to `request_beta_access` with the next-tier plan pre-selected.

### 4. New, additive (no replacement)
- `src/Entity/BetaAccessRequest.php` — persists every form submission.
- `src/Message/RequestBetaAccess.php` + `src/MessageHandler/RequestBetaAccessHandler.php` — CQRS command.
- `src/Events/BetaAccessRequested.php` + `src/MessageHandler/SendBetaAccessNotification.php` — sends two emails (notification to `BETA_REQUESTS_EMAIL`, acknowledgement to requester) via Symfony Mailer.
- `src/Controller/RequestBetaAccessController.php` — `/request-access` route.
- `templates/request_access/form.html.twig`, `templates/emails/beta_access_notification.html.twig`, `templates/emails/beta_access_acknowledgement.html.twig`.
- `migrations/Version20260514000000.php` — `beta_access_request` table.
- New env var: `BETA_REQUESTS_EMAIL` (default `jan.mikes@sendvery.com`).
- Mailer global From: changed to `robot@sendvery.com`.

### 5. What was NOT touched
- `src/Services/Stripe/SubscriptionManager.php` — still creates checkout sessions, customer portal URLs, applies subscription updates.
- `src/Services/Stripe/PlanEnforcement.php`, `PlanLimits.php` — still enforces domain/member caps based on the team's persisted `SubscriptionPlan`.
- `src/Controller/Dashboard/UpgradePlanController.php` (`/app/settings/billing/upgrade/{plan}`), `ManageSubscriptionController.php`, `BillingSuccessController.php`, `BillingCancelController.php` — all still routable.
- `src/Controller/Webhook/StripeWebhookController.php` — still parses `customer.subscription.*` events and dispatches `UpgradeTeamPlan` / `DowngradeTeamPlan` commands.
- Routes `dashboard_billing_upgrade`, `dashboard_billing_manage`, `dashboard_billing_success`, `dashboard_billing_cancel`, `stripe_webhook` — all still exist.
- All Stripe-related test suites — still pass.

---

## How to switch back

Estimated effort: ~30 minutes plus Stripe dashboard setup.

### Step 1 — Stripe dashboard
Create the products and prices listed in `docs/05-monetization.md` (Personal $5.99/mo, Team $49.99/mo, plus the annual SKUs). Record the price IDs.

### Step 2 — Configure secrets
- `STRIPE_SECRET_KEY` — live key, set via GitHub Environment secret `production` (replaces the placeholder in `~/www/spare.srv/deployment/sendvery/docker-compose.yml`).
- `STRIPE_WEBHOOK_SECRET` — from the Stripe dashboard's webhook endpoint settings (add `/webhook/stripe` as the URL).
- `STRIPE_PUBLISHABLE_KEY` — only needed when we add Stripe Elements / client-side card collection (we currently use Checkout which doesn't need it).
- Update `App\Services\Stripe\StripePriceResolver` with the new price IDs (or move them to env vars if you'd rather).

### Step 3 — Flip the CTAs back
Three template edits. Mirror images of the fake-door changes:

```diff
# templates/components/PricingTable.html.twig
- <a href="{{ path('request_beta_access', {plan: 'personal'}) }}" class="btn btn-primary btn-sm w-full">Request beta access</a>
+ <a href="{{ path('dashboard_billing_upgrade', {plan: 'personal'}) }}" class="btn btn-primary btn-sm w-full">Get started</a>
```

Same swap for `plan: 'team'` and in `templates/dashboard/billing.html.twig`. Remove the "Paid plans are in private beta" banner and bring back the original "Upgrade Your Plan" copy (or just leave the new card and only swap the button targets — both work).

For `templates/dashboard/domain_add.html.twig`, point the "Upgrade plan" CTA back at `dashboard_billing`.

### Step 4 — Update copy
- Remove the `> **Current state (May 2026)...**` banner from `docs/05-monetization.md`.
- Remove the "private beta — request access below" banner at the top of `PricingTable.html.twig`.
- Decide whether to keep the VAT footnote (recommend: yes, even after Stripe goes live, until Stripe Tax is enabled).

### Step 5 — Decide what to do with the beta request form
Options:
- **Keep it live** as an Enterprise contact channel (rename CTA to "Talk to sales" — many SaaS products have both).
- **Decommission**: delete the route, mark the migration's `down()` as run, drop the `beta_access_request` table. (Keep the entity around for at least one release in case someone still has the URL bookmarked — return a 404 with a "we're live, sign up here" message before deletion.)

### Step 6 — Update fixtures
Update the test expectations in `tests/Integration/Controller/BillingPagesTest.php` (look for `Paid plans are in private beta` / `Request beta access` strings) back to `Upgrade Your Plan` / `Upgrade to Personal` etc.

### Step 7 — Webhook smoke test
Use Stripe CLI: `stripe listen --forward-to localhost/webhook/stripe` then `stripe trigger checkout.session.completed`. Confirm `UpgradeTeamPlan` is dispatched and the team's plan flips.

---

## Inventory of files that depend on the fake door

If you grep for `request_beta_access` you'll find every fake-door touchpoint. As of 2026-05-14:

- `templates/components/PricingTable.html.twig`
- `templates/dashboard/billing.html.twig`
- `templates/dashboard/domain_add.html.twig`
- `tests/Integration/Controller/BillingPagesTest.php`

That's it. Three templates and one test file are the entire fake-door surface area.
