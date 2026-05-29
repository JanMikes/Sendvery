#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# Provision Sendvery's Stripe catalog: 3 products + 12 prices.
#
# Prices are keyed by STABLE LOOKUP KEYS (sendvery_<tier>[_ai]_<monthly|annual>)
# so the very same script — and the very same app code — works identically
# against the sandbox and the live catalog. The mode is decided purely by the
# secret key prefix (sk_test_… → sandbox, sk_live_… → live).
#
# IDEMPOTENT: re-running reuses the existing product (matched first via an
# existing price's lookup key, then via product metadata) and skips any price
# whose lookup key already exists. Safe to run repeatedly.
#
# Usage:
#   STRIPE_SECRET_KEY=sk_test_... scripts/provision-stripe.sh
#   # or, with the key already in .env.local, just:
#   scripts/provision-stripe.sh
#
# Pricing math (DEC-053/054): annual = 10 × monthly ("2 months free");
# the AI delta is flat per tier and NOT discounted on annual.
# ---------------------------------------------------------------------------

API="https://api.stripe.com/v1"

# --- resolve the secret key -------------------------------------------------
if [[ -z "${STRIPE_SECRET_KEY:-}" && -f .env.local ]]; then
  STRIPE_SECRET_KEY="$(grep -E '^STRIPE_SECRET_KEY=' .env.local | head -1 | cut -d= -f2- || true)"
fi
if [[ -z "${STRIPE_SECRET_KEY:-}" ]]; then
  echo "ERROR: STRIPE_SECRET_KEY is not set and was not found in .env.local" >&2
  exit 1
fi

MODE="LIVE"
[[ "$STRIPE_SECRET_KEY" == sk_test_* ]] && MODE="TEST / sandbox"
echo "==> Provisioning Stripe catalog in ${MODE} mode"
if [[ "$MODE" == "LIVE" ]]; then
  read -r -p "    This is a LIVE key — real money. Type 'yes' to continue: " ok
  [[ "$ok" == "yes" ]] || { echo "Aborted."; exit 1; }
fi

api() { # method path [extra curl args...]
  local method="$1" path="$2"; shift 2
  curl -sS -X "$method" "${API}${path}" -u "${STRIPE_SECRET_KEY}:" "$@"
}

# Fail fast on a Stripe error payload; otherwise echo the chosen field.
# $1 = raw json, $2 = python expression over `d` returning a string.
extract() {
  printf '%s' "$1" | python3 -c '
import sys, json
d = json.load(sys.stdin)
if isinstance(d, dict) and d.get("error"):
    sys.stderr.write("STRIPE ERROR: " + str(d["error"].get("message")) + "\n")
    sys.exit(3)
expr = sys.argv[1]
print(eval(expr) or "")
' "$2"
}

# Tier table: group|name|description|domains|seats|monthly|annual|ai_monthly|ai_annual  (amounts in cents)
TIERS=(
"personal|Sendvery Personal|DMARC monitoring + real-time alerts for up to 5 domains.|5|1|599|5988|999|10788"
"pro|Sendvery Pro|Multi-domain monitoring + API + AI add-on, up to 20 domains.|20|3|2399|23988|3399|35988"
"business|Sendvery Business|Top tier — 50 domains, 10 seats, white-label PDF reports.|50|10|5999|59988|7999|83988"
)

ensure_price() { # product_id lookup_key interval(month|year) amount_cents plan_metadata
  local product="$1" key="$2" interval="$3" amount="$4" plan="$5" resp id
  resp="$(api GET "/prices?lookup_keys[]=${key}&active=true&limit=1")"
  id="$(extract "$resp" 'd.get("data",[{}])[0].get("id","") if d.get("data") else ""')"
  if [[ -n "$id" ]]; then
    printf '    = price %-32s exists  (%s)\n' "$key" "$id"
    return
  fi
  local imeta="monthly"; [[ "$interval" == "year" ]] && imeta="annual"
  resp="$(api POST "/prices" \
    -d "product=${product}" \
    -d "currency=usd" \
    -d "unit_amount=${amount}" \
    -d "recurring[interval]=${interval}" \
    -d "lookup_key=${key}" \
    -d "transfer_lookup_key=true" \
    -d "metadata[plan]=${plan}" \
    -d "metadata[interval]=${imeta}")"
  id="$(extract "$resp" 'd["id"]')"
  printf '    + created price %-24s (%s)  $%d.%02d/%s\n' "$key" "$id" "$((amount / 100))" "$((amount % 100))" "$imeta"
}

for row in "${TIERS[@]}"; do
  IFS='|' read -r group name desc domains seats m_amt a_amt aim_amt aia_amt <<<"$row"
  echo ""
  echo "==> ${name}"

  # Find the product: first via an existing price (strongly consistent), then
  # via product metadata search, otherwise create it.
  product=""
  resp="$(api GET "/prices?lookup_keys[]=sendvery_${group}_monthly&active=true&limit=1")"
  product="$(extract "$resp" 'd.get("data",[{}])[0].get("product","") if d.get("data") else ""')"

  if [[ -z "$product" ]]; then
    resp="$(api GET "/products/search" --data-urlencode "query=active:'true' AND metadata['plan_group']:'${group}'")"
    product="$(extract "$resp" 'd.get("data",[{}])[0].get("id","") if d.get("data") else ""')"
  fi

  if [[ -z "$product" ]]; then
    resp="$(api POST "/products" \
      -d "name=${name}" \
      --data-urlencode "description=${desc}" \
      -d "metadata[plan_group]=${group}" \
      -d "metadata[domains]=${domains}" \
      -d "metadata[seats]=${seats}")"
    product="$(extract "$resp" 'd["id"]')"
    echo "    + created product (${product})"
  else
    echo "    = product exists (${product})"
  fi

  ensure_price "$product" "sendvery_${group}_monthly"    "month" "$m_amt"   "${group}"
  ensure_price "$product" "sendvery_${group}_annual"     "year"  "$a_amt"   "${group}"
  ensure_price "$product" "sendvery_${group}_ai_monthly" "month" "$aim_amt" "${group}_ai"
  ensure_price "$product" "sendvery_${group}_ai_annual"  "year"  "$aia_amt" "${group}_ai"
done

echo ""
echo "==> Done. Next steps:"
echo "    1. Customer Portal:  configure cancel-at-period-end + payment method + invoices"
echo "       https://dashboard.stripe.com/test/settings/billing/portal"
echo "    2. Webhook endpoint: POST {DEFAULT_URI}/webhook/stripe — events:"
echo "       checkout.session.completed, customer.subscription.updated,"
echo "       customer.subscription.deleted, invoice.payment_failed"
echo "       Copy the signing secret into STRIPE_WEBHOOK_SECRET."
echo "    3. Local testing:    stripe listen --forward-to localhost:PORT/webhook/stripe"
