# Data Model & Protocols

**Last updated:** 2026-03-24

## DMARC Aggregate Report Structure (RFC 7489)

A DMARC aggregate report XML contains:

```
<feedback>
  <report_metadata>
    <org_name>          — Who sent the report (Google, Yahoo, etc.)
    <email>             — Contact email of reporter
    <report_id>         — Unique ID
    <date_range>
      <begin>           — Unix timestamp
      <end>             — Unix timestamp
  </report_metadata>

  <policy_published>
    <domain>            — Your domain
    <adkim>             — DKIM alignment (r=relaxed, s=strict)
    <aspf>              — SPF alignment (r=relaxed, s=strict)
    <p>                 — Policy (none, quarantine, reject)
    <sp>                — Subdomain policy
    <pct>               — Percentage
  </policy_published>

  <record>              — One per unique source_ip + result combo
    <row>
      <source_ip>       — IP that sent mail as your domain
      <count>           — Number of messages
      <policy_evaluated>
        <disposition>   — What happened (none, quarantine, reject)
        <dkim>          — pass/fail
        <spf>           — pass/fail
    </row>
    <identifiers>
      <header_from>     — The From: domain
    </identifiers>
    <auth_results>
      <dkim>
        <domain>        — Signing domain
        <result>        — pass/fail/etc.
        <selector>      — DKIM selector
      <spf>
        <domain>        — SPF domain
        <result>        — pass/fail/etc.
  </record>
</feedback>
```

## SPF (RFC 7208)

SPF is a TXT record at the domain root. Key things to validate:
- Syntax correctness
- DNS lookup count (max 10 lookups — `include:`, `a:`, `mx:`, `redirect=` each count)
- `+all` is dangerous (allows everything)
- Common patterns: `v=spf1 include:_spf.google.com include:sendgrid.net ~all`

## DKIM (RFC 6376)

DKIM public keys are TXT records at `<selector>._domainkey.<domain>`. Key things to validate:
- Key exists and is parseable
- Key length (RSA 1024 is minimum, 2048 recommended)
- Key type (RSA vs Ed25519)
- Proper DNS propagation

## DMARC (RFC 7489)

DMARC policy is a TXT record at `_dmarc.<domain>`. Key things to validate:
- `p=` policy exists
- `rua=` reporting address configured
- `ruf=` forensic reporting (optional)
- Alignment settings (adkim, aspf)
- Percentage (pct)
- Subdomain policy (sp)

## Database Schema (Doctrine Entities — PostgreSQL)

**Core principle: everything belongs to a Team.** (DEC-016)

```
┌─────────────────────────────────────────────────────────┐
│                    IDENTITY & ACCESS                     │
│                                                         │
│  User ──────┐                                           │
│  - email    │ M:N                                       │
│  - password ├────── TeamMembership ──────┐              │
│  - locale   │      - role (owner,       │              │
│             │        admin, member,     │              │
│             │        viewer)            │              │
│             │                           ▼              │
│             │                        Team              │
│             │                        - name            │
│             │                        - slug            │
│             │                        - stripeCustomerId│
│             │                        - plan            │
│             └────────────────────────────┘              │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│                    CORE DOMAIN MODEL                     │
│              (all entities have team_id FK)              │
│                                                         │
│  MonitoredDomain                                        │
│  - team_id (FK)                                         │
│  - domain (e.g. "example.com")                         │
│  - dmarcPolicy (cached current policy)                  │
│  - isVerified                                           │
│  - createdAt                                            │
│       │                                                 │
│       │ 1:N                                             │
│       ▼                                                 │
│  DmarcReport                                            │
│  - monitoredDomain_id (FK)                             │
│  - reporterOrg (e.g. "google.com")                     │
│  - reporterEmail                                        │
│  - externalReportId                                     │
│  - dateRangeBegin                                       │
│  - dateRangeEnd                                         │
│  - policyDomain                                         │
│  - policyAdkim                                          │
│  - policyAspf                                           │
│  - policyP                                              │
│  - policySp                                             │
│  - policyPct                                            │
│  - rawXml (original XML, compressed)                    │
│  - processedAt                                          │
│       │                                                 │
│       │ 1:N                                             │
│       ▼                                                 │
│  DmarcRecord                                            │
│  - dmarcReport_id (FK)                                  │
│  - sourceIp                                             │
│  - count                                                │
│  - disposition (none/quarantine/reject)                  │
│  - dkimResult (pass/fail)                               │
│  - spfResult (pass/fail)                                │
│  - headerFrom                                           │
│  - dkimDomain                                           │
│  - dkimSelector                                         │
│  - spfDomain                                            │
│  - resolvedHostname (reverse DNS, cached)               │
│  - resolvedOrg (e.g. "Google", "Mailchimp")             │
│                                                         │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│                    INGESTION                             │
│                                                         │
│  MailboxConnection                                      │
│  - team_id (FK)                                         │
│  - type (imap_user | imap_hosted)                       │
│  - host                                                 │
│  - port                                                 │
│  - encryptedUsername                                     │
│  - encryptedPassword                                    │
│  - encryption (ssl/tls/starttls)                        │
│  - lastPolledAt                                         │
│  - lastError                                            │
│  - isActive                                             │
│                                                         │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│                    DNS MONITORING                         │
│                                                         │
│  DnsCheckResult                                         │
│  - monitoredDomain_id (FK)                             │
│  - type (spf/dkim/dmarc/bimi/mta_sts)                 │
│  - checkedAt                                            │
│  - rawRecord                                            │
│  - isValid                                              │
│  - issues (JSON array of problems found)                │
│  - details (JSON object with parsed record)             │
│                                                         │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│                    NOTIFICATIONS & AI                     │
│                                                         │
│  Alert                                                  │
│  - team_id (FK)                                         │
│  - monitoredDomain_id (FK, nullable)                   │
│  - type (new_sender/spike/policy_recommendation/...)    │
│  - severity (info/warning/critical)                     │
│  - title                                                │
│  - message                                              │
│  - data (JSON)                                          │
│  - isRead                                               │
│  - createdAt                                            │
│                                                         │
│  AiAnalysis (later phase)                               │
│  - team_id (FK)                                         │
│  - monitoredDomain_id (FK, nullable)                   │
│  - type (weekly_digest/on_demand/anomaly)               │
│  - prompt (what was sent to Claude)                     │
│  - response (what Claude returned)                      │
│  - model (haiku/sonnet/opus)                            │
│  - tokensUsed                                           │
│  - createdAt                                            │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

### Team-scoping strategy (Symfony implementation)

Every Doctrine query must be scoped to the current user's team. Options:
1. **Doctrine filter (global)** — auto-applies `WHERE team_id = ?` to all queries
2. **Repository methods** — every findBy method requires team parameter
3. **API Platform extension** — custom QueryCollectionExtension that injects team filter

Recommendation: Doctrine filter for safety (can't accidentally leak cross-team data) + API Platform extension for API endpoints.

### Authorization model (Symfony Security)

| Role | Can do |
|------|--------|
| **owner** | Everything + delete team + billing |
| **admin** | Manage domains, mailboxes, users, settings |
| **member** | View everything, manage own alert preferences |
| **viewer** | Read-only access to dashboards and reports |

Implemented via Symfony Security Voters, one per entity type.
