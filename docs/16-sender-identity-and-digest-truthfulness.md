# DEC-059 — Sender Identity & Digest Truthfulness

**Status:** Approved, in implementation
**Date:** 2026-07-27
**Trigger:** Production weekly digest for team `myspeedpuzzling` (2026-07-27) told the
owner to investigate seven IP addresses and "fix misconfigured sending sources".
Every one of those IPs was benign, and Sendvery had **already computed** the
evidence proving it — into a table the digest does not read.

---

## 1. The incident

The shipped digest said:

> Your team sent 57 messages across 5 domains … overall DMARC pass rate of 97.9%.
> […] Three of your domains sent no mail this week, which is worth a quick check.
> […] Review the 2 new senders detected on sendvery.com to confirm they are authorized.
> […] Look into the roughly 4% of messages on sendvery.com that did not pass
> authentication to identify and fix any misconfigured sending sources before the
> rate climbs further.

And listed, under **"New senders discovered"**, a wall of raw IPs.

### 1.1 What those IPs actually were

Reverse DNS — *already stored by Sendvery in `known_sender`* — identifies all of them:

| IP(s) | PTR hostname | Reality |
|---|---|---|
| `77.75.76.89`, `77.75.78.89` | `mxb.seznam.cz` | The team's **own outbound relay** |
| 9× `2a02:598:…` | `mxb-{1,2,3}-{hex}.seznam.cz` | Same relay, rotating IPv6 pool |
| `52.212.19.177` | `eu.cloud-sec-av.com` | Recipient-side security gateway (EU) |
| `15.222.110.90` | `ca.cloud-sec-av.com` | Same product (CA) |
| `35.174.145.124` | `us.cloud-sec-av.com` | Same product (US) |
| `3.132.108.44`, `34.210.15.192` | `ipw-outbound.inkyphishfence.com` | INKY Phish Fence |
| `40.93.13.60` | `…outbound.protection.outlook.com` | Microsoft 365 forwarding |

Every Seznam IP passes at 100%. Every failure is a **forwarder** — an appliance that
received legitimate mail, scanned it, and re-injected it. SPF necessarily breaks (the
gateway is not in the sender's SPF); DKIM breaks only if the body is rewritten.

The auth results prove it:

- `52.212.19.177` → DKIM **pass**, SPF fail — the textbook *clean forward* signature.
- `15.222.110.90` → both fail — same gateway, but it modified the body (link rewriting).
- `77.75.78.89` carries `dkim_domain = comcastmailservice.net` — a Comcast-signed
  message relayed onward.
- All failures are **1–2 messages, scattered across continents**. Spoofing campaigns are
  high-volume; forwarding is exactly this long tail.

**There is zero spoofing in the dataset.** The advice to "fix misconfigured sending
sources" was unactionable — nothing is misconfigured.

The three "zero message" domains were added **2026-07-25, two days before the digest**,
and have `first_report_at IS NULL` — they have never received a report. Their DMARC
records are valid and correctly point at `reports@sendvery.com`.

### 1.2 Root cause

```
dmarc_record:  168 rows │ resolved_org:  0 │ resolved_hostname:  0
known_sender:   22 rows │ organization: 17 │ hostname:          22
```

`SenderDiscovery` performs reverse DNS + `OrganizationMapper` lookup at ingest and
writes to `known_sender`. But **five read queries** select
`COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip)` from `dmarc_record`,
where those columns are **never written by any production code path** — only by
`SeedDemoDataCommand`. The COALESCE therefore always degrades to a raw IP.

This is not a digest bug. It affects `GetTopSendersForDomain`,
`GetReportSenderGroups`, `GetTopFailingSenderForTeam`, `GetReportDetail`, and
`WeeklyDigestGenerator::getNewSenders`. **The entire dashboard renders raw IPs.**

Compounding it: working forwarder detection **already exists** in
`ReportFactsBuilder:107` (`DKIM ≥ 80% ∧ SPF ≤ 30%`), and `SenderLabelPrompt` exists to
AI-label unknown IPs — but both are siloed inside the per-report AI insight path and
invisible to the digest, the alerts, and the dashboard.

---

## 2. Confirmed defects

All verified against production (`lily.srv.thedevs.cz`, DB `sendvery`) on 2026-07-27.

| # | Defect | Evidence |
|---|---|---|
| **D1** | Enrichment computed then discarded; 5 queries read never-written columns | `0 / 168` rows populated |
| **D2** | Headline pass rate is an **unweighted mean across domains**, not message-weighted | Shipped **97.9%**; truth **96.5%** |
| **D3** | Pass-rate deltas fabricated against a phantom 0% baseline | `+100.0%` for a domain added 2 days earlier |
| **D4** | "No data" rendered as `0.0%` in red, with a green `+0.0%` delta | 3 domains, `first_report_at IS NULL` |
| **D5** | Sender identity is the **IP**, so a rotating pool is infinite "new senders" | 13 `new_unknown_sender` alerts/30d, 11 on one day |
| **D6** | New-sender check is **per-domain**, not per-team | IP trusted on `sendvery.com` since Jul 3 flagged "new" on a sibling domain |
| **D7** | `known_sender.first_seen_at` uses ingest time, not the report period | `77.75.78.89` shows Jul 26; actually sending since **Jul 3** (23 days off) |
| **D8** | Late-arriving reports fall between digest windows and silently mutate baselines | Windowing on `date_range_end` while reports arrive days later |
| **D9** | First-ever report alerts **every** IP as new | Empty NOT IN subquery on report #1 |
| **D10** | AI is fed the wrong numbers and no "awaiting first report" signal | Model faithfully narrated D2/D4 |
| **D11** | `gethostbyaddr()` called inline in a message handler, no timeout, no global cache | Worker stall risk on multi-IP reports |
| **D12** | Existing spoofing heuristic misclassifies *modifying* forwarders as spoofing | `dkim=0 ∧ spf=0 ∧ !authorized` matches `ca.cloud-sec-av.com` |
| **D13** | No "Re-check DNS" control on the domain **health** page | User published DNS, had no way to force a check |
| **D14** | `ReverifyDomainController` has **no rate limit** and runs DNS checks **synchronously** in the request | Unauthenticated-cost abuse vector: every POST = live DNS lookups |

---

## 3. Decision

### 3.1 Promote sender identity to a global, cached, classified entity

Introduce **`sender_identity`** — an IP-keyed table of *objective network facts*, shared
across every team and domain. It is a cache, not user data.

This deliberately **does not** merge with `known_sender`, which stays as the
*per-domain, user-owned* record (`isAuthorized`, `label`, `notes`, volumes,
first/last seen). Clean split:

| | `sender_identity` (new) | `known_sender` (existing) |
|---|---|---|
| Key | `source_ip` (global) | `(monitored_domain_id, source_ip)` |
| Owns | hostname, org, registrable domain, role | authorization, label, notes, volume |
| Source | rDNS + registries + AI fallback | ingest aggregation + user action |
| Nature | Refreshable cache | User data — never auto-deleted |

### 3.2 Identity = registrable domain of the PTR, not the IP

`OrganizationMapper` supplies only the **display name**. Identity itself derives from the
registrable domain of the PTR hostname.

This matters: `cloud-sec-av.com` and `inkyphishfence.com` are **not** in the 60-entry
hardcoded `PATTERNS` list, and that list will never be complete. PTR-derived identity
collapses 15 Seznam rows → one "Seznam" and 3 gateway rows → one `cloud-sec-av.com`
**with no new mappings required**.

### 3.3 Classify the role

```php
enum SenderRole: string
{
    case OwnRelay  = 'own_relay';   // authorized / in SPF / sustained 100% pass
    case Esp       = 'esp';         // recognised email service provider
    case Forwarder = 'forwarder';   // recipient-side gateway or mailing list
    case Unknown   = 'unknown';     // unresolved — needs review
    case Suspicious = 'suspicious'; // fails everything, not explainable as a forward
}
```

Classification order (first match wins, deterministic — no AI in the hot path):

1. **OwnRelay** — matching `known_sender.is_authorized`, or in the domain's SPF set.
2. **Forwarder** — PTR matches `ForwarderRegistry` (`*.protection.outlook.com`,
   `inkyphishfence`, `cloud-sec-av`, `mimecast`, `proofpoint`, `pphosted`, `barracuda`,
   `messagelabs`, …), **or** the clean-forward signature (DKIM ≥ 80% ∧ SPF ≤ 30%).
3. **Esp** — `OrganizationMapper` hit.
4. **Suspicious** — both auth methods fail, no forwarder signal, volume above threshold.
5. **Unknown** — everything else.

Rule 2's PTR branch is what closes **D12**: the clean-forward signature alone cannot
catch a *modifying* forwarder like `ca.cloud-sec-av.com`, which fails both checks.

### 3.4 Resolution is cached, bounded, and off the hot path

`SenderIdentityResolver` looks up `sender_identity` first; on miss it performs rDNS with
a **hard timeout**, persists the result (including negative results, with a
`resolved_at` + retry backoff), and returns. AI labelling via the existing
`SenderLabelPrompt` remains an opt-in enrichment for `Unknown` rows only — never
blocking ingest. Closes **D11**.

### 3.5 Digest tells the truth

- Pass rate is **message-weighted**: `totalPassed / totalMessages` (**D2**).
- `passRate` and `passRateDelta` become **nullable**; "no data" renders `—`, never a red
  `0.0%` (**D3**, **D4**).
- A delta is emitted **only** when the prior window actually had messages (**D3**).
- Domains with `first_report_at IS NULL` are labelled *"Waiting for first report —
  usually 24–72h after setup"*, not "sent no mail" (**D4**, **D10**).
- New senders are grouped by identity, **team-scoped**, and annotated with role and
  message count, so a forwarder never looks like an attacker (**D5**, **D6**).
- `WeeklyDigestFacts` gains the weighted rate, per-domain `hasData` /
  `isAwaitingFirstReport`, and **role counts** (**D10**).

> **Prompt-injection guard preserved.** `WeeklyDigestFacts` deliberately carries counts,
> not raw sender names. Roles are a closed enum and counts are integers, so this fix adds
> **no** attacker-influenceable free text to the prompt. Domain names and DNS labels stay
> sanitized exactly as today.

### 3.6 Alerts stop crying wolf

`AlertOnNewSender` groups by identity, scopes "seen before" to the **team**, suppresses
the storm on a domain's first-ever report (**D9**), and does not raise a warning for
`OwnRelay` or `Forwarder` roles — those become digest line items, not alerts.

### 3.7 Timestamps reflect the report period

`SenderDiscovery` stops using `clock->now()` for `first_seen_at`/`last_seen_at`. Reports
arrive out of order, so: `first_seen_at = LEAST(existing, report.date_range_end)`,
`last_seen_at = GREATEST(existing, report.date_range_end)`. Closes **D7** and makes
`sendvery:reports:reprocess` idempotent.

### 3.8 User-triggered DNS re-check, rate limited

A user who has just published a DNS record must be able to force a check instead of
waiting for the 03:00 cron. `ReverifyDomainController` already exists — and its own
comment claims *"Re-check now buttons live on the domain overview, health and DNS
history pages"* — but `templates/dashboard/domain_health.html.twig` **has no such
button**. The claim is stale; the health page was never wired up (**D13**).

Two changes:

1. **Add the control** to the health page (and audit overview / detail / DNS-history so
   all four agree). Same POST to `dashboard_domain_reverify`, which already redirects
   back to the referring page.
2. **Rate limit it** (**D14**). Today the controller invokes `CheckDomainDnsHandler`
   *synchronously* with no throttle, so each POST performs live SPF/DKIM/DMARC/MX
   lookups inside the web request — a cheap way for a logged-in user to make Sendvery
   hammer third-party DNS.

Limit: **1 re-check per domain per 3 minutes**, keyed on the *domain id* (not the client
IP — the cost is per-domain and the limit must hold across a team's members and any
multi-tab clicking). Follow the established `contact_form` pattern in
`config/packages/framework.php` (autowires as `RateLimiterFactory $domainRecheckLimiter`),
including the `when@test` filesystem `cache.rate_limiter` pool so the limit is testable.

When the bucket is empty: do **not** hard-error. Render the button disabled with the
remaining cooldown ("Re-check available in 2m") and, on a POST that is nonetheless
blocked, add a neutral flash and redirect — an exhausted limit is normal impatience,
not an error state.

The check stays synchronous so the user sees a fresh result on redirect (this is why the
throttle is required rather than optional). Queueing it would decouple the click from the
result and force a polling UI — a bigger change than this fix warrants.

### 3.9 Deferred — D8 (late-arriving reports)

Windowing on `date_range_end` while reports arrive days later means a report can land in
an already-sent digest's window and never be reported, and can retroactively mutate the
prior-period baseline (this is what produced the meaningless `-0.1%` "dip").

Fixing it properly means either windowing on receipt time (and relabelling the period as
"reports received") or a per-team watermark. Both change the meaning of every digest
period, so this is **explicitly out of scope here** and tracked separately. The other
fixes are correct regardless of which windowing wins.

---

## 4. Work packages

**WP1 — Sender identity core** *(foundation; everything else depends on it)*
`SenderRole` enum, `SenderIdentity` entity + repository, `RegistrableDomainExtractor`,
`ForwarderRegistry`, `SenderIdentityResolver` (cache + timeout + classification),
migration, unit tests.

**WP2 — Ingest wiring + backfill**
`SenderDiscovery` uses the resolver, writes `dmarc_record.resolved_hostname/_org`, fixes
`first_seen_at`/`last_seen_at` (D7). New `sendvery:senders:backfill-identities` command
for the 168 existing rows.

**WP3 — Digest correctness + presentation**
`WeeklyDigestGenerator`, `WeeklyDigestData`, `WeeklyDigestDomainData`, the email
template, `WeeklyDigestFacts`/`WeeklyDigestDomainFact`, and `WeeklyDigestPrompt`
(D2, D3, D4, D5, D6, D10).

**WP4 — Alert noise reduction**
`AlertOnNewSender` (D5, D6, D9).

**WP5 — Dashboard read queries**
The five `COALESCE(resolved_*)` queries join `sender_identity` (D1).

**WP6 — DNS re-check button + rate limit** *(independent of WP1; can run immediately)*
Health-page button, cooldown UI, `domain_recheck` rate limiter, controller throttle,
stale-comment fix (D13, D14).

**Constraints for every package:** PHP 8.5 `strict_types`, `final readonly` classes
(cs-fixer order: `final readonly`, not `readonly final`), objects over arrays, no
`flush()` in handlers, 100% coverage, tests describe business behaviour (not ticket
numbers), never assert Tailwind classes, and tests must never make real external
requests — rDNS must be behind an injectable, faked interface.
