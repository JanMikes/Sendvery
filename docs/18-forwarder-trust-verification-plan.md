# 18 — Forwarder trust & verification plan (DEC-060)

**Status:** ready to execute · **Written:** 2026-07-27 · **Audience:** a fresh Claude Code instance

Read `CLAUDE.md` first. Then read `docs/16-sender-identity-and-digest-truthfulness.md` — this
document is its direct successor and reuses its vocabulary (`SenderRole`, `SenderIdentity`,
`SenderIdentityResolver`, `ResolvedSender`, `SenderAuthSignals`).

---

## 1. The problem in one sentence

DEC-059 made Sendvery stop calling forwarders attackers; this document makes Sendvery *prove* a
forward is genuine, so that "forwarder" is a status a sender **earns** rather than one it can
**assert**.

### 1.1 Why that distinction is load-bearing

`SenderRole::Forwarder` suppresses alerts (`warrantsAlert() === false`). Any signal that can flip a
sender into that role is therefore a trust bypass if an attacker can write it themselves. The
original `ForwarderRegistry` matched on the PTR hostname, and **PTR is written by whoever controls
the reverse zone of an IP block** — i.e. anyone who rents a VPS. Setting PTR to `mx1.mimecast.com`
costs nothing and bought silence.

That specific hole is closed by the forward-confirmation work described in §2. This document covers
what remains.

### 1.2 The evidence ladder

Every rule below is placed on a ladder, strongest first. The design rule is: **a lower tier may
never override a higher one, and tiers D and below may never grant trust on their own.**

| Tier | Evidence | Forgeable by the sender? | Status |
|---|---|---|---|
| **A** | DKIM signature from the header_from domain survives the hop | **No** — cryptographic | partially used |
| **B** | Receiver attested the override in the report (`<reason>`) | **No** — the receiver wrote it | **captured, unused** |
| **C** | Cross-receiver correlation; SRS envelope rewriting | Very hard | not built |
| **D** | PTR + forward confirmation; ASN | Hard (needs two zones / BGP) | FCrDNS in flight |
| **E** | Volume and topology shape | Trivially | used as a threshold only |

---

## 2. Assumed baseline (in flight — verify before starting)

Uncommitted-but-green work already exists for the first two items of the ladder's plumbing. **Check
`git log` and `git status` first**; if the following is not present, it landed under a different
name and you should reconcile before proceeding.

- `ReverseDnsResolver::forwardAddresses(string $hostname): array` alongside `resolve()`.
- `SenderIdentity::$forwardConfirmed` — **three-valued** (`true` / `false` / `null`). `null` means
  "never asked" and grants nothing; such rows get exactly one re-resolution via `isDueForRetry()`.
- `SenderRoleClassifier` requires a *forward-confirmed* PTR for the hostname branch of the
  Forwarder rule.
- `PolicyOverrideReason`, `PolicyOverrideReasonType`, `App\Doctrine\PolicyOverrideReasonsType`,
  `dmarc_record.policy_override_reasons`, and `DmarcXmlParser` reading `<policy_evaluated><reason>`.

**Verify the baseline is green before writing any code:**

```bash
docker compose exec app vendor/bin/phpunit
docker compose exec app vendor/bin/phpstan
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff
```

---

## 3. Production evidence (real data, 2026-07-27)

All 17 cached identities pass FCrDNS, so tightening the PTR rule costs **zero** false negatives:

```
11 × *.seznam.cz            → esp        FCrDNS PASS
 2 × *.cloud-sec-av.com     → forwarder  FCrDNS PASS   (eu/ca/us = 3 IPs)
 2 × ipw-outbound.inkyphishfence.com → forwarder  FCrDNS PASS
 1 × …outbound.protection.outlook.com → forwarder  FCrDNS PASS
```

The domain publishes `p=quarantine`, and the forwarded mail is being **quarantined right now**:

| identity | dkim | spf | disposition | msgs | reporter |
|---|---|---|---|---|---|
| `cloud-sec-av.com` | **pass** | fail | **none** (delivered) | 1 | Enterprise Outlook |
| `cloud-sec-av.com` | fail | fail | **quarantine** | 3 | Enterprise Outlook |
| `inkyphishfence.com` | fail | fail | **quarantine** | 2 | Enterprise Outlook |
| `outlook.com` | fail | fail | **quarantine** | 1 | Enterprise Outlook |

Two things follow, and both are requirements below:

1. **DKIM survival is what saved the delivered message.** Same gateway product, same receiver — the
   only difference is whether the body was rewritten. Tier A must be a first-class rule (WP-B).
2. **Six legitimate messages are in spam folders.** No advice Sendvery gives can "fix" this, and
   pretending otherwise is what DEC-059 was written about. The product must say so (WP-E).

Also measured: **0 of 81** production reports carry a `<reason>` element today. Tier B is therefore
*forward-looking* — it pays off as domains reach enforcement and receivers start overriding. Do not
justify WP-A with retroactive numbers; there are none.

---

## 4. Work packages

Ordered by value ÷ effort. WP-A and WP-E are the ones worth doing even if nothing else is.

### WP-A — Consume the receiver-attested evidence (Tier B)

**Why:** `dmarc_record.policy_override_reasons` is written at ingest and **read by nothing**. It is
the only unforgeable forwarding signal we can obtain without a network call, and it is already free.

RFC 7489 §6.7 defines six override types. Three are direct statements that the receiver believed
the mail was forwarded — `forwarded`, `trusted_forwarder`, `mailing_list` — and a fourth,
`local_policy`, carries the ARC verdict in its free-text comment (Gmail writes `arc=pass`).

**Build:**

1. A value object — suggested `ForwardingAttestation` in `src/Value/` — that answers
   "did a receiver attest this was a forward?" from a `list<PolicyOverrideReason>`. Keep the
   comment parsing narrow: match `arc=pass` case-insensitively as a whole token, and treat
   *everything else* in that free text as untrusted. The comment is receiver-written, but it is
   still free text that ends up influencing a trust decision, so do not pattern-match loosely.
2. Extend `SenderAuthSignals` with the attestation (nullable / defaulted, so every existing caller
   keeps compiling and PTR-only classification still works).
3. Add a rule to `SenderRoleClassifier` **above** the PTR branch, below `OwnRelay`. Receiver
   attestation outranks PTR because the receiver cannot be impersonated by the sender.
4. Aggregate the reasons per source IP wherever `SenderAuthSignals` is currently built —
   `AlertOnNewSender::sendersInReport()` is the reference implementation; follow it exactly.

**Do not:** write the attestation onto `sender_identity`. It is per-report, per-receiver evidence,
not a global fact about the host. This is the same rule that keeps `OwnRelay` out of that table.

**Tests:** each of the three forwarding reason types classifies as `Forwarder`; `local_policy` +
`arc=pass` does too; `local_policy` with any other comment does **not**; an attested forward that
would otherwise score `Suspicious` (dkim 0 / spf 0 / high volume) comes back `Forwarder`; a record
with no reasons behaves exactly as today.

---

### WP-B — Make DKIM survival a first-class rule, and detect SRS (Tiers A + C)

**Why:** today the only Tier-A-ish rule is the `DKIM ≥ 80% ∧ SPF ≤ 30%` heuristic inherited from
`ReportFactsBuilder`. That is a *statistical* shape, not the cryptographic fact underneath it. A
DKIM signature that validates against the header_from domain is proof the message is authentic and
unmodified — a spoofer cannot produce one. It deserves to be stated as its own rule with its own
name, so the reason a sender was trusted is legible in the code and in the UI.

**Build:**

1. In `SenderRoleClassifier`, express "DKIM aligned and passing" as an explicit rule ahead of the
   percentage heuristic. Keep the heuristic as the fallback for the aggregate case — DMARC
   aggregate reports give you counts, not per-message verdicts, so both are needed.
2. **SRS detection.** Forwarders rewrite the envelope sender so SPF passes for *them*:
   `SRS0=`/`SRS1=` (Sender Rewriting Scheme), `prvs=` (BATV), `bounces+`/`bounce-` prefixes. We
   already store `dmarc_record.spf_domain`. When SPF **passes** but the SPF domain is not aligned
   with the header_from *and* looks like a rewritten envelope, that is a forward with a rewritten
   return path.
3. Put the pattern list in its own small registry class next to `ForwarderRegistry`, matching that
   file's style (documented constants, exact/boundary matching, no regex soup).

**Tests:** an SRS-shaped non-aligned SPF pass classifies as `Forwarder`; a non-aligned SPF pass that
is *not* SRS-shaped does not (that is a plain alignment failure); alignment comparison is
case-insensitive and honours the organisational-domain rule already used elsewhere.

---

### WP-C — Cross-receiver correlation (Tier C)

**Why:** the single strongest signal available from data we already hold, and the one commercial
platforms describe: *the same message stream passing from one IP and failing from another is
forwarding.* If DKIM domain `d=example.com` passes from IP A in Yahoo's report and fails from IP B
in Microsoft's report for the same period, B is relaying A's mail.

**Build:**

1. A query in `src/Query/` (DBAL, per the CQRS split) that, for a domain and window, finds source
   IPs whose `dkim_domain` also appears passing from a *different* IP in the same window.
2. Feed the result in as an additional signal — same shape as WP-A, an extra field on
   `SenderAuthSignals`.
3. **Not on the hot path.** This is a correlation across the whole window; compute it in the
   existing per-report aggregation step or in a scheduled command, never per-record.

**Watch out:** this is the one rule that could be gamed by a spoofer who *also* sends legitimate
mail. Treat it as corroboration (Tier C) — enough to downgrade `Suspicious` → `Unknown`, not enough
to grant `Forwarder` on its own. Encode that explicitly rather than leaving it to rule ordering.

---

### WP-D — ASN as a second identity axis (Tier D)

**Why:** ASN is derived from BGP, not from a zone the sender controls, so it cannot be forged the
way PTR can. It also identifies hosts that have no PTR at all. `AS8075 Microsoft` is not something
a VPS renter can claim.

**Build:**

1. `AsnResolver` interface + `SystemAsnResolver` + `FakeAsnResolver` in `src/Services/Dns/`,
   following the **exact** `ReverseDnsResolver` / `FakeReverseDnsResolver` pattern, wired in
   `config/services.php` with the fake aliased under `when@test`.
2. Use **Team Cymru's DNS interface** — `origin.asn.cymru.com` / `origin6.asn.cymru.com` for the
   IP→ASN mapping and `AS<n>.asn.cymru.com` for the org name. It is free, needs no API key, and is
   a plain DNS TXT lookup, so it reuses the bounded-lookup discipline already in place. Do **not**
   add a paid GeoIP dependency for this.
3. Store `asn` (int, nullable) and `asn_organization` (string, nullable) on `sender_identity`;
   additive migration; index `asn`.
4. Use it as corroboration: PTR forward-confirmed **and** ASN agreeing is a strong forwarder
   signal; ASN alone identifies but does not excuse.
5. Extend `sendvery:senders:backfill-identities` to fill ASN for existing rows.

**Budget:** reuse `SenderIdentityResolver`'s per-batch lookup cap. Two lookups per IP instead of
one — re-check that the cap still bounds a pathological report acceptably, and adjust with a comment
explaining the arithmetic if not.

---

### WP-E — Tell the truth about mail that is forwarded *and* quarantined

**Why:** six real messages are in spam folders right now, and there is nothing the domain owner can
do about most of them. This is the exact failure mode DEC-059 was written to end: advice that
sounds actionable, is not, and erodes trust. Getting the classification right and then printing
"fix your misconfigured sending sources" underneath it would waste the whole effort.

**Build:**

1. Where a sender is classified `Forwarder` **and** its messages were quarantined or rejected,
   say so plainly: the mail was forwarded by a gateway that modified it, SPF cannot survive that
   by design, and DKIM did not survive because the body changed.
2. Give the two real options rather than a fake one: accept it as a known consequence of
   forwarding, or (where the receiver honours ARC) nothing needs doing at the sender at all.
3. Never count these under a "misconfigured sources" heading, and never let them push a domain's
   health grade down as if they were a setup error. Cross-check `DomainHealthScorer` and the digest
   copy for this.
4. Follow the existing rule in `CLAUDE.md`: **"Unknown Is Not Failure"** — and its corollary here,
   *explained is not broken*.

**Tests:** assert the *semantics* (which copy key / which severity), never Tailwind classes.

---

### WP-F — dnswl.org corroboration (optional, do last)

Free, categorised DNS whitelist of legitimate senders with trust levels, standardised as an
authentication method in RFC 8904. Same `Fake*` pattern, same bounded-lookup discipline. Strictly
Tier D corroboration: it may raise confidence, never grant `Forwarder` alone. Skip if WP-A–E have
not shipped.

---

## 5. Non-negotiable constraints

- PHP 8.5, `declare(strict_types=1)`, `final readonly class` (**cs-fixer order: `final readonly`,
  not `readonly final`**), objects over arrays, constructor promotion, public readonly properties.
- **No `flush()` in message handlers** — the bus middleware owns the transaction.
- New entity IDs via `IdentityProvider::nextIdentity()`, never `Uuid::uuid7()` directly.
- **Tests must never make a real external request.** Every new network dependency goes behind an
  interface with a `Fake*` implementation aliased under `when@test` in `config/services.php`.
- 100% coverage. Test *names and assertion messages describe business behaviour*, not ticket
  numbers (docblocks may cite DEC-060).
- Comments document **why**, not what.
- Migrations additive only, docblock referencing the DEC, timestamp after the latest existing one.
- Read-side queries use DBAL + raw SQL and return `Results/` DTOs, never entities.

## 6. Definition of done

1. All three tools green: `phpunit`, `phpstan`, `php-cs-fixer --dry-run`.
2. New/changed source files at 100% line coverage.
3. `docs/07-decisions-log.md` gains a **DEC-060** entry above the `*Add new decisions above this
   line*` marker, in the established house style, summarising the evidence ladder and the rule that
   lower tiers never grant trust alone.
4. Commit per work package, conventional-commit subject, body explaining *why*.
5. If a schema change ships, note that production needs
   `bin/console sendvery:senders:backfill-identities` re-run to populate the new columns.
