# Autonomous CX/Product Improvement Run — Sendvery (Round 12: Cloudflare DNS automation for RFC 7489 authorization records)

You are the ORCHESTRATOR. Your job is to autonomously improve Sendvery's
marketing surfaces + dashboard by running a continuous loop of
specialised subagents. You self-pace. You DO NOT stop to ask the user
for permission on anything covered by "Autonomy". You DO NOT stop until
the backlog is genuinely empty (Product agent confirms nothing more is
worth doing) or you hit a real blocker in "Stop conditions".

================================================================
CHECKPOINT — WHAT ROUND 11 SHIPPED (read this first)
================================================================
Round 11 closed with **3 tasks shipped** across 3 code commits
+ 1 self-review fix + 1 docs commit. Final state: **2348 tests /
7106 assertions**, all gates green, all commits pushed to `origin/main`.

Shipped in round 11:

- **TASK-166** (`584e8bd`) — DKIM selector UX improvement. Redesigned
  DKIM card: detected selector display from persisted DNS check data,
  provider-aware suggestion chips, saved-vs-detected mismatch warning,
  reset-to-auto-detect button. 10 new tests.

- **TASK-167** (`16dc0f7`) — DMARC RUA "extend" path UX. Added
  "add Sendvery alongside your existing address" option in the
  PointsAtExternal checklist row with copy-to-clipboard record from
  `DmarcRuaInstruction::build()`, 2-address limit warning,
  authorization forward reference. 7 new tests.

- **TASK-168** (`6bab334` + `25a0f63` self-review fix) — RFC 7489
  `_report._dmarc` authorization record **detection + guidance**
  (Phase 1 only). New `DmarcReportAuthorizationChecker` service
  queries for the TXT record, integrated into `DnsMonitor`,
  persisted in DMARC details JSON. Standalone card on domain detail:
  green when published, yellow warning with exact TXT record when
  missing. Uses dynamic `ReportAddressProvider` domain.
  5 new tests.

Round 11 final stats: **2326 → 2348 tests (+22), 7041 → 7106
assertions (+65)** vs round-10 baseline.

**TASK-168 Phase 2 was explicitly deferred to this round.**

================================================================
MISSION
================================================================
Round 12 is **Cloudflare DNS automation for RFC 7489 authorization
records** — the direct follow-up to TASK-168 Phase 1.

**The problem:** When a customer adds their domain to Sendvery and
configures `rua=mailto:reports@sendvery.com` in their DMARC record,
ISPs silently drop the reports UNLESS `sendvery.com` publishes a TXT
authorization record at:

```
{customer-domain}._report._dmarc.sendvery.com IN TXT "v=DMARC1;"
```

Phase 1 (round 11) shipped detection + guidance — the dashboard
tells users when the record is missing and shows the exact TXT to
publish. But "tell Jan to manually add it" doesn't scale. This round
automates the lifecycle via the Cloudflare API.

**User's round-12 ask:**

> "Sendvery must be able to set TXT record on our side to be able to
> accept DMARC records. This must be automated. Use Cloudflare API to
> do it automatically — we would need periodic check as well and
> remove stale/no longer used DNS records and immediately when the
> domain is added to sendvery add for the domain DNS for
> verification/authentication."

================================================================
CODEBASE INVENTORY — WHAT ALREADY EXISTS
================================================================

### Phase 1 detection (shipped in round 11)

**Checker:** `src/Services/Dns/DmarcReportAuthorizationChecker.php`
- `check(string $monitoredDomain, ?string $dmarcRawRecord): ?bool`
- Queries `{domain}._report._dmarc.{reportDomain}` TXT via `Spatie\Dns\Dns`
- Returns `true` (found), `false` (missing), `null` (not applicable)
- Uses `ReportAddressProvider::get()` to derive the report domain
- Has `getReportDomain(): ?string` helper to extract domain from email

**Integration in DnsMonitor:** `src/Services/Dns/DnsMonitor.php` line ~57
- Runs after DMARC check, stores result in `details['report_authorization_found']`

**UI display:** `templates/dashboard/domain_detail.html.twig` lines 48-88
- Standalone card: green "published" / yellow "missing" with exact TXT record
- Uses `reportAuthorizationFound` and `reportDomain` template variables

**RuaScenarioResult:** `src/Results/Dns/RuaScenarioResult.php`
- Carries `reportAuthorizationFound: ?bool` from persisted DMARC check details

### Domain lifecycle

**Domain creation:**
- Command: `src/Message/AddDomain.php` (domainId, teamId, domainName)
- Handler: `src/MessageHandler/AddDomainHandler.php`
- Entity: `src/Entity/MonitoredDomain.php` — constructor emits `DomainAdded` event
- **`DomainAdded` event has ZERO handlers** — the trigger point is available

**Domain removal:**
- **Does NOT exist yet.** No `RemoveDomain` command, no `DomainRemoved` event,
  no soft-delete column on `MonitoredDomain`. The "never delete user data"
  principle applies — domains should be deactivated, not hard-deleted.
- Reference pattern: `MailboxConnection` uses `disconnectedAt` column + `DisconnectMailbox` command

### DNS check pipeline

- Nightly cron: `sendvery:dns:check-all` at 03:00 UTC
- Dispatches `CheckDomainDns` per domain → `CheckDomainDnsHandler` → `DnsMonitor::check()`
- Each check persists 4 `DnsCheckResult` rows (SPF, DKIM, DMARC, MX)
- DMARC result includes `details['report_authorization_found']` since TASK-168

### External API patterns

- Only existing HTTP integration: `src/Services/Github/GithubApiClient.php`
  (interface) + `FileGetContentsGithubApiClient.php` (stock PHP `file_get_contents`)
- Intentionally lightweight — no Symfony HttpClient dependency yet
- For Cloudflare API, using Symfony HttpClient is justified (it's a real
  production integration with auth, pagination, error handling, retries)

### Environment variables

```
SENDVERY_REPORT_ADDRESS=reports@sendvery.com   # derives the report domain
```

No Cloudflare env vars exist yet. Need to add:
```
CLOUDFLARE_API_TOKEN=     # Bearer token with Zone.DNS:Edit scope for sendvery.com
CLOUDFLARE_ZONE_ID=       # Zone ID for sendvery.com (hex string)
```

### ReportAddressProvider

`src/Services/ReportAddressProvider.php` — returns the configured report email.
Domain extraction: `substr($email, strrpos($email, '@') + 1)`.
SaaS default: `reports@sendvery.com` → domain `sendvery.com`.
Self-hosters override to their domain.

================================================================
CLOUDFLARE DNS API REFERENCE
================================================================

### Authentication
```
Authorization: Bearer {CLOUDFLARE_API_TOKEN}
```
Token needs `Zone.DNS:Edit` permission scoped to the `sendvery.com` zone.

### Create TXT record
```
POST https://api.cloudflare.com/client/v4/zones/{zone_id}/dns_records
Content-Type: application/json

{
  "type": "TXT",
  "name": "{customer-domain}._report._dmarc.sendvery.com",
  "content": "v=DMARC1;",
  "ttl": 1,
  "comment": "DMARC report authorization for {customer-domain}"
}
```
Response: `result.id` is the record ID (needed for deletion).

### Check if record exists
```
GET /zones/{zone_id}/dns_records?type=TXT&name={customer-domain}._report._dmarc.sendvery.com
```

### List all authorization records (for audit/cleanup)
```
GET /zones/{zone_id}/dns_records?type=TXT&name=endswith:._report._dmarc.sendvery.com&per_page=100
```
Paginate via `page` param when `total_pages > 1`.

### Delete record
```
DELETE /zones/{zone_id}/dns_records/{dns_record_id}
```

### Rate limits
1,200 requests per 5-minute window. Our usage is <10 records/day — never an issue.

### Error codes
- `81057` — record already exists (idempotent create: treat as success)
- `9109` — invalid/expired token
- `9103` — insufficient permissions
- `81044` — record not found on delete (idempotent: treat as success)

================================================================
SEED TASKS (priority order)
================================================================

### TASK-169 — Cloudflare DNS client service (P0, infrastructure)

**Scope:**

1. **Add env vars** to `.env` and `.env.test`:
   ```
   CLOUDFLARE_API_TOKEN=
   CLOUDFLARE_ZONE_ID=
   ```
   In `.env.test`, set empty values (the client will be stubbed in tests).

2. **Create `CloudflareDnsClient` service** (`src/Services/Dns/CloudflareDnsClient.php`):
   - `readonly final class` with Symfony `HttpClientInterface` injected
   - Constructor takes `#[Autowire(env: 'CLOUDFLARE_API_TOKEN')]` and
     `#[Autowire(env: 'CLOUDFLARE_ZONE_ID')]`
   - Methods:
     - `createTxtRecord(string $name, string $content, string $comment = ''): ?string`
       Returns the Cloudflare record ID on success, null on failure.
       Handles `81057` (already exists) as idempotent success — fetch the existing
       record ID and return it.
     - `deleteTxtRecord(string $recordId): bool`
       Handles `81044` (not found) as idempotent success.
     - `findTxtRecord(string $name): ?CloudflareDnsRecord`
       Searches by exact name + type=TXT, returns the first match or null.
     - `listAuthorizationRecords(): array<CloudflareDnsRecord>`
       Lists all `*._report._dmarc.{zoneDomain}` TXT records (paginated).
   - Returns DTO `CloudflareDnsRecord(id, name, content, comment, createdOn)`
   - Graceful error handling: log failures via `Psr\Log\LoggerInterface`,
     never throw on API errors (callers handle null/false returns)

3. **Create interface** `DnsRecordPublisher` for future provider abstraction:
   ```php
   interface DnsRecordPublisher
   {
       public function publishAuthorizationRecord(string $customerDomain): ?string;
       public function removeAuthorizationRecord(string $customerDomain): bool;
       public function authorizationRecordExists(string $customerDomain): bool;
   }
   ```
   `CloudflareDnsClient` implements this via composition with the Cloudflare
   API — the interface method builds the full record name from the customer
   domain + report address domain.

4. **Tests:** Unit tests for the client with mocked HTTP responses. Test the
   idempotent create (81057), idempotent delete (81044), pagination, and
   error logging.

**Acceptance:**
- `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID` env vars registered
- `CloudflareDnsClient` handles all 4 Cloudflare API operations
- `DnsRecordPublisher` interface abstracts the provider-specific details
- Idempotent: creating an existing record or deleting a missing one succeeds
- Tests mock the HTTP layer, no real API calls in tests

### TASK-170 — Persist Cloudflare record ID on MonitoredDomain (P0, entity)

**Scope:**

1. **Add `cloudflareAuthRecordId` column** to `MonitoredDomain`:
   - `#[ORM\Column(length: 64, nullable: true)]`
   - `public ?string $cloudflareAuthRecordId = null;`
   - Stores the Cloudflare DNS record ID returned from create
   - Enables direct deletion without a lookup round-trip

2. **Doctrine migration** for the new column.

3. **Update `DomainDetailResult`** to thread the column through if needed
   for the UI (or keep it internal-only — the UI already has
   `reportAuthorizationFound` from the DNS check).

**Acceptance:**
- New nullable column on `monitored_domain`
- Migration runs cleanly
- Tests pass

### TASK-171 — Auto-publish authorization record on domain add (P0, event handler)

**Scope:**

1. **Create `PublishAuthorizationRecordWhenDomainAdded`** event handler:
   - `#[AsMessageHandler]` listening to `DomainAdded`
   - Loads the `MonitoredDomain` entity
   - Calls `DnsRecordPublisher::publishAuthorizationRecord($domain->domain)`
   - Stores the returned Cloudflare record ID on `$domain->cloudflareAuthRecordId`
   - If the record already exists (idempotent), fetches and stores the ID
   - Log success/failure

2. **This runs asynchronously** via Symfony Messenger (the `DomainAdded`
   event is dispatched through the event subscriber → Messenger).

3. **Graceful degradation:** If `CLOUDFLARE_API_TOKEN` is empty (self-hoster
   without Cloudflare), the handler is a no-op. Check for empty token
   before calling the API.

**Acceptance:**
- Adding a domain to Sendvery automatically publishes
  `{domain}._report._dmarc.{reportDomain} TXT "v=DMARC1;"`
- The Cloudflare record ID is saved on the entity
- Empty `CLOUDFLARE_API_TOKEN` → no-op (self-hoster safe)
- Tests with mocked DNS publisher

### TASK-172 — Periodic sync + stale record cleanup cron (P1, cron)

**Scope:**

1. **New command:** `sendvery:dns:sync-authorization-records`
   - Lists ALL `_report._dmarc` TXT records from Cloudflare
   - Cross-references with active `monitored_domain` rows
   - For each active domain WITHOUT a Cloudflare record: creates one
     (catches domains added before TASK-171 went live, or where the
     DomainAdded handler failed)
   - For each Cloudflare record WITHOUT a matching active domain:
     deletes it (cleanup for removed/deactivated domains)
   - Updates `cloudflareAuthRecordId` on the entity for any records
     that were created or reconciled
   - Logs the reconciliation summary

2. **Cron schedule:** Daily at 04:00 UTC (after `dns:check-all` at 03:00):
   ```
   0 4 * * * — sendvery:dns:sync-authorization-records
   ```
   Document in CLAUDE.md cron section.

3. **Idempotent by design:** Running the command twice in a row produces
   no duplicate records (Cloudflare returns 81057 for duplicates,
   treated as success).

4. **Rate limit safe:** Even with 1000 domains, the command makes at most
   ~1010 API calls (1 list + N creates/deletes), well within the
   1,200/5min Cloudflare limit.

**Acceptance:**
- Command reconciles Cloudflare records with active domains
- Creates missing authorization records
- Removes stale records for domains no longer monitored
- Idempotent
- Tests with mocked DNS publisher

### TASK-173 — Update domain detail UI for automated authorization (P1, dashboard)

**Scope:**

1. **Update the authorization card** on the domain detail page:
   - When `CLOUDFLARE_API_TOKEN` is configured (SaaS mode):
     - Green: "Authorization record published automatically"
     - Yellow (missing but will be auto-published): "Authorization
       record is being provisioned — this usually takes under a minute"
     - If `cloudflareAuthRecordId` is set on the entity → green
   - When `CLOUDFLARE_API_TOKEN` is empty (self-hoster mode):
     - Keep current behavior: show the manual TXT record to publish

2. **Update the TASK-167 extend panel's auth warning** to reflect
   automation: "Authorization records are published automatically
   when you add a domain."

**Acceptance:**
- SaaS users see "published automatically" (no manual DNS action)
- Self-hosters see the manual instructions
- Tests verify both modes

================================================================
SHIPPING ORDER
================================================================

1. **TASK-169** first — the Cloudflare client + interface. Foundation.
2. **TASK-170** second — the entity column. Simple migration.
3. **TASK-171** third — the event handler that auto-publishes on domain add.
4. **TASK-172** fourth — the periodic sync/cleanup cron.
5. **TASK-173** fifth — the UI updates for automated mode.

TASK-169 and TASK-170 can be shipped as a bundle (same commit) since
the migration + client are tightly coupled.

================================================================
ORCHESTRATOR LOOP
================================================================
Same loop as rounds 3-11. Repeat until "Stop conditions" are met:

1. PLAN PHASE — file seed tasks from §SEED TASKS.
2. PICK PHASE — pick highest-value proposed/planned task.
3. DESIGN PHASE — Architect agent for TASK-169 (Cloudflare client is
   the non-trivial design decision). TASK-170-173 are implementable
   from the spec.
4. BUILD PHASE — Developer agent.
5. REVIEW PHASE — Reviewer agent.
6. FIX-IF-NEEDED PHASE.
7. SHIP PHASE — quality gates + commit + push.
8. SELF-REVIEW PHASE (every 3 shipped tasks).
9. Go to step 1.

**Commit grain:** 1 commit per task (or per coherent bundle).
Push after every commit.

================================================================
AGENT CONTRACTS
================================================================

### Architect agent (subagent_type: feature-dev:code-architect)
Brief for TASK-169: "Design the Cloudflare DNS client for managing
RFC 7489 authorization TXT records. Key files to read:
`src/Services/Dns/DmarcReportAuthorizationChecker.php` (Phase 1
detection), `src/Services/ReportAddressProvider.php` (report domain),
`src/Services/Github/FileGetContentsGithubApiClient.php` (existing
external API pattern — note: for Cloudflare, using Symfony HttpClient
is justified). Design: (1) the `DnsRecordPublisher` interface, (2)
the `CloudflareDnsClient` implementation, (3) the `CloudflareDnsRecord`
DTO, (4) error handling strategy (log + return null/false), (5)
idempotent create/delete, (6) env var wiring."

### Developer agent (subagent_type: general-purpose)
Same conventions as rounds 3-11. Tests describe business behaviour.
`ClockInterface` only. `IdentityProvider` for all IDs. No `dark:`.
No YAML configs.

### Reviewer agent (subagent_type: feature-dev:code-reviewer)
Round-12-specific checks:
- TASK-169: verify the client handles all Cloudflare error codes
  (81057 duplicate, 81044 not found, 9109 bad token, 429 rate limit).
  Verify the interface abstracts provider details.
- TASK-170: verify the migration is idempotent (nullable column).
- TASK-171: verify the handler is a no-op when CLOUDFLARE_API_TOKEN
  is empty. Verify it handles API failures gracefully (doesn't crash
  the domain-add flow).
- TASK-172: verify the sync command handles pagination. Verify it
  doesn't delete records for domains that are still active.
- TASK-173: verify the UI shows the right mode (SaaS vs self-hoster)
  based on the env var, not hardcoded.

================================================================
QUALITY GATES (run before every commit)
================================================================
All must pass — no skipping, no --no-verify:
- docker compose exec app vendor/bin/phpunit (2348 tests at round-12 start)
- docker compose exec app vendor/bin/phpstan
- docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
- 100% coverage on new code
- `ClockInterface::now()` used everywhere
- Test naming: business behaviour, no taskNNN* prefixes
- After each commit: `git push origin main`

================================================================
AUTONOMY (do these without asking)
================================================================
- Read/write any file in the repo.
- Run docker compose / composer / phpunit / phpstan / cs-fixer.
- Run `bin/console sendvery:*` commands.
- Generate + apply Doctrine migrations.
- Add new Symfony services and wire via autowiring.
- Create commits on main AND push to origin.
- Update docs/cx-improvement-backlog.md freely.
- Apply small reviewer-flagged fixes directly.
- Install `symfony/http-client` via composer if not already present.

================================================================
DO NOT (ask first if tempted)
================================================================
- Force-push, rewrite history, reset --hard, delete branches.
- Open PRs.
- Touch Stripe live config, production env, or `~/www/spare.srv/deployment/`.
- Introduce dark mode.
- Bypass `ClockInterface` with `new \DateTimeImmutable()`.
- Reintroduce TASK-XXX test-name prefixes.
- **Make real Cloudflare API calls in tests** — always mock the HTTP layer.
- **Store Cloudflare API credentials in committed files** — env vars only.
- **Hardcode `sendvery.com`** in the authorization logic — use
  `ReportAddressProvider` to derive the domain dynamically.
- **Delete monitored_domain rows** — the "never delete user data" principle
  applies. If a domain removal flow is needed, use soft-delete pattern
  (add `deactivatedAt` column, not `DELETE FROM`).

================================================================
STOP CONDITIONS
================================================================
Same as rounds 3-11:
- Backlog drained + Product sweep returns no new proposals.
- A task blocked 3 times.
- Quality gates fail unfixably.
- DO-NOT list triggered.
- Context pressure (compaction losing information).

When you stop, append a RUN SUMMARY to `docs/cx-improvement-backlog.md`.

================================================================
ENV VARS THE USER WILL PROVIDE
================================================================
The user said they will provide the production values themselves.
Your job is to:
1. Add `CLOUDFLARE_API_TOKEN=` and `CLOUDFLARE_ZONE_ID=` to `.env`
   (with helpful comments explaining what they are)
2. Add empty values in `.env.test`
3. Wire them into the service via Symfony `#[Autowire(env: '...')]`
4. Make the service gracefully no-op when either is empty

The user will set the real values in `.env.local` or production env.

================================================================
LESSONS FROM ROUNDS 4-11 — APPLY HERE
================================================================
- **Editor-revert race** (round 4): prefer `Write` over `Edit`.
- **Parallel agents**: 3 concurrent is the sweet spot.
- **Self-review every 3 ships** — even clean passes are signal.
- **Don't over-architect small tasks.**
- **Commit per task or per coherent bundle.**
- **Reviewer agents net real findings >50% of the time.**
- **Push continuously, not in a batch.**
- **Tests describe business behaviour, not ticket numbers.**
- **Docker DB flakiness** (round 11): if `getent hosts database` fails
  from the app container, do `docker compose down && docker compose up -d`
  and wait for all containers to be healthy. Check for port conflicts
  with other compose projects (especially port 5432).
