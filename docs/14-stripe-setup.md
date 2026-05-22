# Stripe Products & Prices — Setup Runbook

**Last updated:** 2026-05-22
**Related:** `docs/05-monetization.md` (pricing rationale), `docs/07-decisions-log.md` (DEC-052..057), `docs/13-pricing-implementation-plan.md` (build status).

This doc is the source of truth for what to create in Stripe — three products, twelve prices, one webhook endpoint, and the Customer Portal configuration. Follow it top-to-bottom in **test mode** first, smoke-test via the Stripe CLI, then mirror in **live mode** when ready to take real charges.

---

## Prerequisites

- Stripe Dashboard access at https://dashboard.stripe.com.
- Confirm the mode selector (top-right) is set to **Test mode** before creating anything. The orange banner is your friend.
- Create products + prices in **test mode** first. When test-mode end-to-end works, repeat the same steps in live mode.

---

## Tax & currency

- **Currency:** USD only. Don't add EUR/GBP/CZK prices — single currency keeps the matrix small and the math simple.
- **Stripe Tax:** OFF at launch. Jan is OSVČ in CZ, below the VAT threshold, so prices include VAT where applicable and there's no breakout on receipts. Toggle Stripe Tax on later when we cross the threshold — no code changes required (Stripe Tax is a dashboard setting).
- **VAT footnote:** the pricing-page UI shows "VAT included where applicable" — keep this line until Stripe Tax goes live.

---

## Customer Portal

Configure the Stripe Customer Portal at https://dashboard.stripe.com/test/settings/billing/portal before going live — `SubscriptionManager::createCustomerPortalSession` opens this URL for users managing their subscription.

**Enable:**
- ✓ Update payment method
- ✓ Cancel subscriptions — **cancel at period end, no refund** (default). See [[refund-and-cancellation-policy]].
- ✓ View billing history / invoices

**Disable:**
- ✗ Switch plans via portal — our app handles plan changes through `dashboard_billing_upgrade` → `SubscriptionManager::updateSubscription`, which knows about AI variants and our metadata schema. Letting the portal do it would create state we can't reconcile.
- ✗ Update billing email — keep this gated through our app to maintain DB sync.

**Branding:**
- Business name: Sendvery
- Logo: upload from `assets/images/logo.png` (or whichever is current)
- Brand color: oklch primary from `assets/styles/app.css` — pick the hex equivalent for Stripe (it doesn't accept oklch directly).

---

## Three products

| Product | Description (Stripe-visible) | Stripe Metadata |
|---|---|---|
| **Sendvery Personal** | DMARC monitoring + real-time alerts for up to 5 domains. | `plan_group: personal` · `domains: 5` · `seats: 1` |
| **Sendvery Pro** | Multi-domain monitoring + API + AI add-on, up to 20 domains. | `plan_group: pro` · `domains: 20` · `seats: 3` |
| **Sendvery Business** | Top tier — 50 domains, 10 seats, white-label PDF reports. | `plan_group: business` · `domains: 50` · `seats: 10` |

The **Free** tier has no Stripe product (free is free; no checkout). The **Unlimited** tier is internal-only (staff grant via `bin/console sendvery:team:set-plan`) and has no Stripe presence either.

Product metadata is for dashboard organization — the **runtime app reads metadata from the subscription/checkout session, not from the price**, so what we set here is dashboard-side ergonomics. The Stripe-visible name and description appear on customer invoices and receipts.

---

## Twelve prices

Each product gets 4 recurring prices: monthly base, annual base, monthly + AI, annual + AI.

**Math invariants (DEC-053, DEC-054):**
- Annual amount = **exactly 10 × monthly amount** (i.e., "2 months free").
- AI delta is **constant across cadences** — `+$4` on Personal, `+$10` on Pro, `+$20` on Business. AI is not discounted on annual because the AI cost is metered monthly.
- All amounts in dollars and cents, no fractional pennies.

### Sendvery Personal — 4 prices

| Price | Amount | Interval | Stripe Lookup Key | App env var |
|---|---|---|---|---|
| Personal — Monthly | **$5.99** | month | `sendvery_personal_monthly` | `STRIPE_PRICE_PERSONAL_MONTHLY` |
| Personal — Annual | **$59.88** | year | `sendvery_personal_annual` | `STRIPE_PRICE_PERSONAL_ANNUAL` |
| Personal + AI — Monthly | **$9.99** | month | `sendvery_personal_ai_monthly` | `STRIPE_PRICE_PERSONAL_AI_MONTHLY` |
| Personal + AI — Annual | **$107.88** | year | `sendvery_personal_ai_annual` | `STRIPE_PRICE_PERSONAL_AI_ANNUAL` |

### Sendvery Pro — 4 prices

| Price | Amount | Interval | Stripe Lookup Key | App env var |
|---|---|---|---|---|
| Pro — Monthly | **$23.99** | month | `sendvery_pro_monthly` | `STRIPE_PRICE_PRO_MONTHLY` |
| Pro — Annual | **$239.88** | year | `sendvery_pro_annual` | `STRIPE_PRICE_PRO_ANNUAL` |
| Pro + AI — Monthly | **$33.99** | month | `sendvery_pro_ai_monthly` | `STRIPE_PRICE_PRO_AI_MONTHLY` |
| Pro + AI — Annual | **$359.88** | year | `sendvery_pro_ai_annual` | `STRIPE_PRICE_PRO_AI_ANNUAL` |

### Sendvery Business — 4 prices

| Price | Amount | Interval | Stripe Lookup Key | App env var |
|---|---|---|---|---|
| Business — Monthly | **$59.99** | month | `sendvery_business_monthly` | `STRIPE_PRICE_BUSINESS_MONTHLY` |
| Business — Annual | **$599.88** | year | `sendvery_business_annual` | `STRIPE_PRICE_BUSINESS_ANNUAL` |
| Business + AI — Monthly | **$79.99** | month | `sendvery_business_ai_monthly` | `STRIPE_PRICE_BUSINESS_AI_MONTHLY` |
| Business + AI — Annual | **$839.88** | year | `sendvery_business_ai_annual` | `STRIPE_PRICE_BUSINESS_AI_ANNUAL` |

### Why lookup keys?

Lookup keys are stable, human-readable identifiers that survive price re-creation. Today `StripePriceResolver` reads `price_xxx` IDs from env vars; if you ever lose the env or need to recreate prices, you can recover the right ID from the dashboard by searching the lookup key. Worth setting even though we don't currently look up by key at runtime — they cost nothing to add and save a lot of stress later.

---

## Step-by-step in the dashboard

For each of the three products:

1. **Products** (left nav) → **Add product**.
2. Set the Stripe-visible name (e.g., "Sendvery Personal").
3. Add the description from the table above.
4. **Pricing model:** Recurring.
5. Enter the first price (e.g., $5.99). Set:
   - Currency: USD
   - Billing period: Monthly
   - Expand **Advanced** → set the lookup key (e.g., `sendvery_personal_monthly`).
6. **Save product**. Stripe creates the first Price.
7. Open the saved product → **Add another price**. Repeat for the remaining 3 (Annual, AI-Monthly, AI-Annual) cadence/AI combinations of that product. Each gets its own lookup key.
8. Copy the resulting Price ID (`price_xxx`) into the corresponding env var listed above.

When all twelve prices exist, you'll have 12 distinct `price_xxx` IDs to populate the `STRIPE_PRICE_*` env vars.

---

## Webhook endpoint

1. **Developers** → **Webhooks** → **Add endpoint**.
2. URL: `https://sendvery.com/webhook/stripe`
3. **Events to listen to** (exactly these four):
   - `checkout.session.completed`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.payment_failed`
4. After creating, click into the endpoint and reveal the **Signing secret**. Copy into `STRIPE_WEBHOOK_SECRET` (production env).

The app rejects unsigned/badly-signed payloads at `StripeWebhookController::__invoke` — verify the signing secret matches what the dashboard generated for this specific endpoint (test-mode and live-mode endpoints have different secrets).

---

## Smoke test (Stripe CLI, test mode)

After test-mode products + prices + webhook exist, validate end-to-end without a real card:

```bash
stripe login  # one-time, opens browser auth

# Terminal 1 — forward webhooks into local dev
stripe listen --forward-to localhost:8080/webhook/stripe

# Terminal 2 — fire events
stripe trigger checkout.session.completed
stripe trigger customer.subscription.updated
stripe trigger customer.subscription.deleted
stripe trigger invoice.payment_failed
```

Verify in the app DB:
- `team.plan`, `team.billing_interval`, `team.stripe_subscription_id` update on `checkout.session.completed`.
- `customer.subscription.updated` flips plan/interval based on subscription metadata.
- `customer.subscription.deleted` flips the team back to `free`.
- `invoice.payment_failed` is logged (no DB mutation — log-only at launch).

Then walk a real test-card subscription start-to-finish:

1. Log in to the app.
2. Navigate to `/pricing` → click Get Pro (Annual).
3. Stripe Checkout opens. Use card `4242 4242 4242 4242`, any future expiry, any CVC.
4. Confirm redirect back to `/app/settings/billing/success`.
5. Check `/app/settings/billing` shows the new plan.
6. Open the Stripe Customer Portal link, cancel the subscription. Confirm the team flips back to `free` after the `customer.subscription.deleted` event fires (immediate if Stripe's test mode honors "cancel at period end" as instant cancellation, otherwise after the period).

---

## Cutover to live mode

When test-mode is validated end-to-end:

1. Switch Stripe Dashboard to **Live mode**.
2. **Recreate** all 3 products + 12 prices (live mode has its own catalog separate from test). Same lookup keys, same amounts, same metadata.
3. **Recreate** the webhook endpoint pointing at the production URL — capture the new signing secret.
4. Set the production env vars:
   - `STRIPE_SECRET_KEY=sk_live_...`
   - `STRIPE_PUBLISHABLE_KEY=pk_live_...`
   - `STRIPE_WEBHOOK_SECRET=whsec_...` (live-mode endpoint secret)
   - All 12 `STRIPE_PRICE_*` env vars (live-mode `price_xxx` IDs)
   - `ANTHROPIC_API_KEY=sk-ant-...` if AI variants should be purchasable. Leave unset to hide the AI toggle and refuse AI checkout.
5. Deploy.
6. Walk one real subscription end-to-end on Jan's personal card. Confirm:
   - Stripe Customer Portal opens and works.
   - The webhook posts hit production and the team's plan reflects the subscription.
   - The Sentry breadcrumb for `stripe.webhook` shows the right event types.

---

## Rollback

**If the cutover blows up:**

- **AI specifically broken:** unset `ANTHROPIC_API_KEY` in prod env, restart. The pricing-page AI toggle disappears immediately and `UpgradePlanController` redirects AI variant requests to billing with an explanatory flash. Existing AI subscriptions continue billing normally — they're a Stripe-side fact, unaffected by the env flag.
- **Stripe broadly broken:** revert the deploy. There is no env-flag fallback now that the fake-door is gone — pricing CTAs unconditionally point to Stripe checkout, which is the right default but means a reverted-code rollback is the path.
- **Webhook stuck:** in the dashboard, retry failed events from the endpoint detail page. Stripe holds them for ~3 days. Once the underlying bug is fixed and deployed, click "Resend" on each failed event.
- **A single customer over/undercharged:** issue a manual refund or credit via the Stripe Dashboard. There is no self-serve refund flow ([[refund-and-cancellation-policy]]) — refunds are case-by-case and human-mediated.

---

## Future cleanup

When the new pricing has run cleanly for a quarter:

- Delete this doc (it's an operational runbook, no longer needed once everything is set up).
- Or keep it as historical record of the launch state.
- Move the pricing rationale + the canonical matrix in `docs/05-monetization.md` to a customer-facing knowledge-base article so prospects can read the same source of truth.

---

## Cross-references

- `docs/05-monetization.md` — canonical pricing matrix + AI rationale.
- `docs/07-decisions-log.md` DEC-052..057 — the pricing-model decisions this doc operationalizes.
- `docs/13-pricing-implementation-plan.md` — what code shipped to support these prices.
- `src/Services/Stripe/StripePriceResolver.php` — runtime mapping from (plan, interval) → env-var Price ID.
- `src/Services/Stripe/SubscriptionManager.php` — Checkout session creation, subscription updates, webhook routing.
- `src/Controller/Webhook/StripeWebhookController.php` — webhook signature validation.
