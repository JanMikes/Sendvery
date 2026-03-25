# Stage 11: DNS Monitoring & Alerts

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, CQRS, domain events, Scheduler.
2. `docs/04-data-model-protocols.md` — DnsCheckResult and Alert entity schemas.
3. `docs/03-features-roadmap.md` — Phase 1 scope: DNS change monitoring, basic alerting (new unknown sender, spike in failures, policy recommendation, DNS record changed).

## What Already Exists (Stages 1-10 completed)

- Full Symfony 8 project with all infrastructure
- Phase 0A complete (marketing site, DNS tools, beta signup, Knowledge Base)
- Phase 0B complete (DMARC parsing, email ingestion, personal dashboard)
- Authentication (magic link), onboarding, team-scoped dashboard
- DNS checker services (SpfChecker, DkimChecker, DmarcChecker, MxChecker) from Stage 5
- All tests passing

## What to Build

Scheduled DNS monitoring (detect record changes) and an alerting system that notifies users of important events.

### 1. DnsCheckResult Entity

**`src/Entity/DnsCheckResult.php`** — `final class`:
- `id` (UUID v7, readonly)
- `monitoredDomain` (ManyToOne → MonitoredDomain)
- `type` (DnsCheckType enum: `spf`, `dkim`, `dmarc`, `mx`)
- `checkedAt` (DateTimeImmutable, readonly)
- `rawRecord` (nullable string — the actual DNS record value)
- `isValid` (bool)
- `issues` (json — array of issue objects)
- `details` (json — parsed record details, structure varies by type)
- `previousRawRecord` (nullable string — for change detection)
- `hasChanged` (bool — did record change since last check?)
- Implements EntityWithEvents, records `DnsCheckCompleted` event (with `hasChanged` flag)

**`src/Value/DnsCheckType.php`:**
```php
enum DnsCheckType: string
{
    case Spf = 'spf';
    case Dkim = 'dkim';
    case Dmarc = 'dmarc';
    case Mx = 'mx';
}
```

### 2. Alert Entity

**`src/Entity/Alert.php`** — `final class`:
- `id` (UUID v7, readonly)
- `team` (ManyToOne → Team)
- `monitoredDomain` (ManyToOne → MonitoredDomain, nullable)
- `type` (AlertType enum)
- `severity` (AlertSeverity enum)
- `title` (string)
- `message` (text)
- `data` (json — structured context data)
- `isRead` (bool, default false)
- `createdAt` (DateTimeImmutable, readonly)

**`src/Value/AlertType.php`:**
```php
enum AlertType: string
{
    case NewUnknownSender = 'new_unknown_sender';
    case FailureSpike = 'failure_spike';
    case PolicyRecommendation = 'policy_recommendation';
    case DnsRecordChanged = 'dns_record_changed';
    case DnsRecordInvalid = 'dns_record_invalid';
    case DnsRecordMissing = 'dns_record_missing';
    case MailboxConnectionError = 'mailbox_connection_error';
}
```

**`src/Value/AlertSeverity.php`:**
```php
enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
```

### 3. DNS Monitoring Service

**`src/Services/Dns/DnsMonitor.php`** — `readonly final class`:
- Input: MonitoredDomain
- Runs SPF, DKIM, DMARC, MX checks (reuses existing checker services from Stage 5)
- Compares results with last DnsCheckResult for each type
- Creates new DnsCheckResult entries
- Detects changes: record value different, record missing/added, validity changed
- Returns `DnsMonitoringResult` value object with per-type results and change flags

### 4. CQRS for DNS Monitoring

**Command:** `src/Message/CheckDomainDns.php`
- `domainId` (UuidInterface)

**Handler:** `src/MessageHandler/CheckDomainDnsHandler.php`
- Loads MonitoredDomain
- Calls DnsMonitor service
- Persists DnsCheckResult entries
- Events are recorded on entities

### 5. DNS Check Scheduler

**`src/Command/CheckAllDomainsDnsCommand.php`:**
- `php bin/console sendvery:dns:check-all`
- Iterates all MonitoredDomains
- Dispatches `CheckDomainDns` command for each via Messenger (async)
- Run daily via Symfony Scheduler or system cron

**`src/Scheduler/DnsCheckProvider.php`:**
- Schedules daily DNS checks for all domains
- Runs at a configurable time (default: 3:00 AM)

### 6. Alerting Engine

**`src/Services/AlertEngine.php`** — `readonly final class`:
- Central service that evaluates conditions and creates alerts
- Called from event handlers (not directly from commands)

**Alert triggers (via domain event handlers):**

**`src/MessageHandler/AlertOnDnsChange.php`:**
- Listens to `DnsCheckCompleted` event
- If `hasChanged` is true → creates `dns_record_changed` alert (warning)
- If record was valid and now invalid → creates `dns_record_invalid` alert (critical)
- If record was present and now missing → creates `dns_record_missing` alert (critical)

**`src/MessageHandler/AlertOnNewSender.php`:**
- Listens to `DmarcReportProcessed` event
- Queries for source IPs in the report that haven't been seen before for this domain
- If new unknown senders found → creates `new_unknown_sender` alert (warning)

**`src/MessageHandler/AlertOnFailureSpike.php`:**
- Listens to `DmarcReportProcessed` event
- Compares current report's fail rate with the domain's average fail rate
- If fail rate increased significantly (e.g., >20 percentage points above average) → creates `failure_spike` alert (critical)

**`src/MessageHandler/RecommendPolicyUpgrade.php`:**
- Listens to `DmarcReportProcessed` event
- If domain has p=none and pass rate is consistently >95% for 30+ days → creates `policy_recommendation` alert (info) suggesting upgrade to quarantine
- If domain has p=quarantine and pass rate is consistently >99% for 60+ days → suggest upgrade to reject

### 7. Alert Notification Email

**`src/MessageHandler/SendAlertEmailNotification.php`:**
- Listens to `AlertCreated` event
- For critical alerts: sends email immediately
- For warning alerts: batches (or sends immediately in Phase 1, batch later)
- Template: `templates/emails/alert_notification.html.twig`
- Subject includes severity and domain: "[Critical] DNS record changed for example.com"

### 8. Dashboard Alert Pages

**`src/Controller/Dashboard/ListAlertsController.php`:**
- Route: `/app/alerts`
- Lists alerts for the team, newest first
- Filter by: severity, type, domain, read/unread
- Mark as read (Turbo Frame)

**`src/Controller/Dashboard/ShowAlertDetailController.php`:**
- Route: `/app/alerts/{id}`
- Shows alert detail with context data
- "Mark as read" button
- Link to related domain

**Update dashboard overview** (from Stage 9):
- Show unread alert count in stat card
- Show recent critical alerts in overview
- Add alert bell icon with unread count in navigation

### 9. DNS History Page

**`src/Controller/Dashboard/DomainDnsHistoryController.php`:**
- Route: `/app/domains/{id}/dns-history`
- Shows timeline of DNS check results for a domain
- Visual: timeline with change markers
- For each check: show what changed (old value → new value), validity status

**New query:** `src/Query/GetDomainDnsHistory.php`
- Returns DnsCheckResults for a domain, ordered by date
- Includes change detection metadata

### 10. Database Migration

Create migration for: `dns_check_result`, `alert` tables.

### 11. Tests

**Unit tests:**
- DnsMonitor (mock DNS checkers, verify change detection logic)
- AlertEngine (alert creation logic)
- AlertOnDnsChange (various change scenarios: changed, invalid, missing)
- AlertOnNewSender (new IP detection)
- AlertOnFailureSpike (spike calculation logic)
- RecommendPolicyUpgrade (threshold logic for none→quarantine→reject)
- All enums, value objects, Results DTOs

**Integration tests:**
- CheckDomainDnsHandler: runs check, creates DnsCheckResult, detects changes
- Full alert flow: DNS change → DnsCheckCompleted event → AlertOnDnsChange handler → Alert created → email sent
- New sender alert: report processed → new IP → alert created
- Failure spike alert: report with high fail rate → alert created
- Policy recommendation: consistent good pass rate → recommendation alert

**Functional tests:**
- Alerts list page renders with alerts
- Alert detail page
- Mark alert as read
- DNS history page shows timeline
- Dashboard overview shows alert count
- `sendvery:dns:check-all` command runs without errors

## Verification Checklist

- [ ] DNS checks run on schedule and detect changes
- [ ] Alerts are created for: DNS changes, new senders, failure spikes, policy recommendations
- [ ] Critical alert emails are sent
- [ ] Alerts page shows all alerts with filters
- [ ] Dashboard shows unread alert count
- [ ] DNS history page shows check timeline with change markers
- [ ] All tests pass, 100% coverage

## What Comes Next

Stage 12 adds the non-AI weekly digest email, IMAP credential encryption upgrade (paragonie/halite), beta user invitation management, and UX polish (error states, empty states, loading indicators).
