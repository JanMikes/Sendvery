# Stage 13: Stripe Billing

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, CQRS.
2. `docs/05-monetization.md` — **READ FULLY.** Pricing tiers (Self-hosted free, Free 1 domain, Personal $5.99/5 domains, Team $49.99/50 domains/10 members), AI add-on, go-to-market strategy.
3. `docs/03-features-roadmap.md` — Phase 2 scope: Stripe integration, plan enforcement, public registration.
4. `docs/10-libraries-and-tools.md` — Stripe integration options.

## What Already Exists (Stages 1-12 completed)

- Full Symfony 8 project, all infrastructure
- Phase 0A complete (marketing, DNS tools, beta signup, Knowledge Base)
- Phase 0B complete (DMARC parsing, ingestion, dashboard)
- Phase 1 complete (auth, onboarding, DNS monitoring, alerts, digest, beta invitations)
- Team entity has `stripeCustomerId` and `plan` fields (ready for Stripe)
- All tests passing

## What to Build

Stripe integration for subscriptions. Users can upgrade from the free tier to paid plans. Domain limits are enforced based on the plan.

### 1. Install Stripe SDK

```bash
composer require stripe/stripe-php
```

### 2. Stripe Configuration

**`config/packages/stripe.php`** (or configure via services):
- `STRIPE_SECRET_KEY` env var
- `STRIPE_WEBHOOK_SECRET` env var
- `STRIPE_PUBLISHABLE_KEY` env var (for Checkout.js)

**`src/Services/Stripe/StripeClientFactory.php`** — creates configured Stripe client from env var.

### 3. Plan Definition

**`src/Value/SubscriptionPlan.php`:**
```php
enum SubscriptionPlan: string
{
    case Free = 'free';           // 1 domain, basic features
    case Personal = 'personal';    // 5 domains, $5.99/mo
    case Team = 'team';            // 50 domains, 10 members, $49.99/mo
}
```

**`src/Services/Stripe/PlanLimits.php`** — readonly final class:
- Maps each plan to its limits: max domains, max team members, features enabled
- `getMaxDomains(SubscriptionPlan $plan): int`
- `getMaxTeamMembers(SubscriptionPlan $plan): int`
- `hasFeature(SubscriptionPlan $plan, string $feature): bool`
- Feature flags: `dns_monitoring`, `alerts`, `digest`, `api_access`, etc.

### 4. Stripe Subscription Service

**`src/Services/Stripe/SubscriptionManager.php`** — `readonly final class`:
- `createCheckoutSession(Team $team, SubscriptionPlan $plan): string` — creates Stripe Checkout session, returns URL
- `createCustomerPortalSession(Team $team): string` — creates Stripe Customer Portal URL (for managing subscription)
- `handleWebhook(string $payload, string $signature): void` — processes Stripe webhook events
- `getCurrentPlan(Team $team): SubscriptionPlan` — reads from Team entity
- `syncSubscriptionStatus(Team $team): void` — fetches latest from Stripe, updates local

### 5. Stripe Webhook Handler

**`src/Controller/Webhook/StripeWebhookController.php`:**
- Route: `/webhook/stripe` (public, no auth)
- POST only
- Verifies webhook signature
- Delegates to SubscriptionManager::handleWebhook()

**Events to handle:**
- `checkout.session.completed` — user completed checkout → update Team plan, set stripeCustomerId
- `customer.subscription.updated` — plan changed → update Team plan
- `customer.subscription.deleted` — subscription cancelled → downgrade to Free
- `invoice.payment_failed` — payment failed → set warning flag, send email
- `invoice.paid` — payment succeeded → clear any warning flags

### 6. CQRS for Billing

**Command:** `src/Message/UpgradeTeamPlan.php` — `teamId`, `plan`, `stripeSubscriptionId`
**Handler:** Updates Team entity with new plan.

**Command:** `src/Message/DowngradeTeamPlan.php` — `teamId`
**Handler:** Sets Team plan to Free, handles data over-limit gracefully (don't delete domains — just prevent adding new ones).

### 7. Plan Enforcement

**`src/Services/PlanEnforcement.php`** — `readonly final class`:
- `canAddDomain(Team $team): bool` — checks current domain count vs plan limit
- `canAddTeamMember(Team $team): bool` — checks current member count vs plan limit
- `canAccessFeature(Team $team, string $feature): bool`

**Update existing controllers:**
- `AddDomainController` — check `canAddDomain()` before allowing
- `AddMailboxController` — linked to domain, same check
- Show upgrade prompts when limits are reached

### 8. Billing Dashboard Pages

**`src/Controller/Dashboard/BillingController.php`:**
- Route: `/app/settings/billing`
- Shows: current plan, usage (X of Y domains, Z of W team members), next billing date
- "Upgrade" button → redirects to Stripe Checkout
- "Manage subscription" button → redirects to Stripe Customer Portal
- Plan comparison table

**`src/Controller/Dashboard/UpgradePlanController.php`:**
- Route: `/app/settings/billing/upgrade/{plan}`
- Creates Stripe Checkout session for the selected plan
- Redirects user to Stripe-hosted checkout page

**`src/Controller/Dashboard/BillingSuccessController.php`:**
- Route: `/app/settings/billing/success`
- Stripe redirects here after successful checkout
- Shows "Welcome to [Plan]!" with updated limits
- Syncs subscription status from Stripe

**`src/Controller/Dashboard/BillingCancelController.php`:**
- Route: `/app/settings/billing/cancel`
- Stripe redirects here if user cancels checkout
- Shows "No changes made" with option to try again

### 9. Update Pricing Page

Update the marketing pricing page (from Stage 4):
- Remove "coming soon" badge
- Add "Get started" buttons that link to `/login` → checkout flow
- Show feature comparison matrix
- Annual/monthly toggle (if offering annual discount)

### 10. Upgrade Prompts

When a user hits a plan limit, show an upgrade prompt:
- "You've reached your domain limit (1/1). Upgrade to Personal for up to 5 domains."
- Inline in the relevant page (not a popup)
- Clear, non-aggressive — helpful suggestion, not a wall

### 11. Database Migration

- Add `stripe_subscription_id` column to `team` table
- Add `plan_warning_at` column to `team` table (for payment failure tracking)

### 12. Tests

**Unit tests:**
- PlanLimits (all plan limits correct, feature flags)
- PlanEnforcement (canAddDomain, canAddTeamMember for each plan/state)
- SubscriptionPlan enum values
- Webhook event handling (mock Stripe payload verification)

**Integration tests:**
- UpgradeTeamPlan handler updates plan correctly
- DowngradeTeamPlan handler resets to free
- Plan enforcement prevents adding domains over limit
- Webhook handler processes checkout.session.completed correctly
- Webhook handler processes subscription.deleted correctly

**Functional tests:**
- Billing page renders with current plan info
- Upgrade button creates checkout session (mock Stripe)
- Webhook endpoint accepts valid Stripe payload
- Webhook endpoint rejects invalid signature
- Domain add fails when at plan limit (with upgrade prompt)
- Pricing page shows live prices without "coming soon"

## Verification Checklist

- [ ] Stripe Checkout flow works end-to-end (test mode)
- [ ] Webhook processes subscription events correctly
- [ ] Team plan updates after successful checkout
- [ ] Domain limits are enforced based on plan
- [ ] Upgrade prompts show when limits are reached
- [ ] Customer Portal works for subscription management
- [ ] Billing page shows correct plan, usage, and dates
- [ ] Pricing page is live (no "coming soon")
- [ ] Payment failure handling works
- [ ] All tests pass, 100% coverage on new code

## What Comes Next

Stage 14 is the final stage: advanced features (sender inventory, blacklist monitoring, domain health score, PDF reports) and launch preparation (public GitHub repo, Docker Hub image, deployment docs). After Stage 14, Phase 2 is complete and Sendvery is ready for public launch.
