# Stage 7: DMARC Report Parsing

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, CQRS, entities, value objects.
2. `docs/04-data-model-protocols.md` — **READ FULLY.** DMARC aggregate report XML structure (RFC 7489), complete database schema for MonitoredDomain, DmarcReport, DmarcRecord entities, field descriptions.
3. `docs/10-libraries-and-tools.md` — DMARC XML parsing section (liuch/dmarc-srg as reference, or write own).

## What Already Exists (Stages 1-6 completed)

- Full Symfony 8 project with Docker, Tailwind + daisyUI
- Core infrastructure (EntityWithEvents, IdentityProvider, DomainEventsSubscriber)
- Identity layer (Team, User, TeamMembership, TeamFilter)
- Complete Phase 0A: marketing site, 8 interactive DNS tool pages, beta signup, Knowledge Base
- All tests passing, 100% coverage

## What to Build

The core product: parsing DMARC aggregate report XML files and storing the structured data. This is Phase 0B — building the personal-use tool that solves Jan's own problem.

### 1. Value Objects

All in `src/Value/`, all `readonly final class`:

**`src/Value/DmarcPolicy.php`** — enum (already exists from Stage 5, verify or create):
```php
enum DmarcPolicy: string
{
    case None = 'none';
    case Quarantine = 'quarantine';
    case Reject = 'reject';
}
```

**`src/Value/DmarcAlignment.php`** — enum:
```php
enum DmarcAlignment: string
{
    case Relaxed = 'r';
    case Strict = 's';
}
```

**`src/Value/AuthResult.php`** — enum:
```php
enum AuthResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case SoftFail = 'softfail';
    case Neutral = 'neutral';
    case None = 'none';
    case TempError = 'temperror';
    case PermError = 'permerror';
}
```

**`src/Value/Disposition.php`** — enum:
```php
enum Disposition: string
{
    case None = 'none';
    case Quarantine = 'quarantine';
    case Reject = 'reject';
}
```

### 2. Entities

Follow the schema from `docs/04-data-model-protocols.md` exactly.

**`src/Entity/MonitoredDomain.php`:**
- `id` (UUID v7, readonly)
- `team` (ManyToOne → Team)
- `domain` (string, e.g., "example.com")
- `dmarcPolicy` (DmarcPolicy enum, nullable — cached current policy from latest check)
- `isVerified` (bool, default false)
- `createdAt` (DateTimeImmutable, readonly)
- Implements EntityWithEvents, records `DomainAdded` event
- Unique constraint on (team, domain)

**`src/Entity/DmarcReport.php`:**
- `id` (UUID v7, readonly)
- `monitoredDomain` (ManyToOne → MonitoredDomain)
- `reporterOrg` (string — e.g., "google.com")
- `reporterEmail` (string)
- `externalReportId` (string — the report_id from XML)
- `dateRangeBegin` (DateTimeImmutable)
- `dateRangeEnd` (DateTimeImmutable)
- `policyDomain` (string)
- `policyAdkim` (DmarcAlignment)
- `policyAspf` (DmarcAlignment)
- `policyP` (DmarcPolicy)
- `policySp` (nullable DmarcPolicy)
- `policyPct` (int)
- `rawXml` (text — original compressed XML stored as base64)
- `processedAt` (DateTimeImmutable, readonly)
- Unique constraint on (monitoredDomain, externalReportId) — prevent duplicate imports

**`src/Entity/DmarcRecord.php`:**
- `id` (UUID v7, readonly)
- `dmarcReport` (ManyToOne → DmarcReport)
- `sourceIp` (string)
- `count` (int)
- `disposition` (Disposition enum)
- `dkimResult` (AuthResult enum)
- `spfResult` (AuthResult enum)
- `headerFrom` (string)
- `dkimDomain` (nullable string — signing domain)
- `dkimSelector` (nullable string)
- `spfDomain` (nullable string)
- `resolvedHostname` (nullable string — reverse DNS, populated later)
- `resolvedOrg` (nullable string — e.g., "Google", "Mailchimp", populated later)

### 3. Domain Events

- `src/Events/DomainAdded.php` — `domainId`, `teamId`
- `src/Events/DmarcReportProcessed.php` — `reportId`, `domainId`, `reporterOrg`, `totalRecords`, `passCount`, `failCount`

### 4. DMARC XML Parser Service

**`src/Services/Dmarc/DmarcXmlParser.php`** — `readonly final class`:
- Input: XML string (the raw DMARC aggregate report)
- Parses using PHP's `SimpleXML` or `DOMDocument`
- Returns `ParsedDmarcReport` value object (not an entity — just structured data)
- Validates against expected structure (handles missing optional fields gracefully)
- Throws `InvalidDmarcReportXml` exception for malformed XML

**`src/Value/ParsedDmarcReport.php`** — readonly final class:
- `reportMetadata` (org, email, reportId, dateRange)
- `publishedPolicy` (domain, adkim, aspf, p, sp, pct)
- `records[]` (array of parsed record data)

### 5. Report Attachment Extractor

**`src/Services/Dmarc/ReportAttachmentExtractor.php`** — `readonly final class`:
- DMARC reports arrive as email attachments in `.zip` or `.gz` format
- Input: binary file content + filename
- Detects format from extension/magic bytes
- Extracts the XML file(s) from the archive
- Returns array of XML strings (a zip may contain multiple XML files)
- Handles edge cases: nested archives, multiple XML files, invalid archives

### 6. CQRS for Report Processing

**Command:** `src/Message/ProcessDmarcReport.php`
- `reportId` (UuidInterface)
- `domainId` (UuidInterface)
- `xmlContent` (string)
- `reporterOrg` (string — for event)

**Handler:** `src/MessageHandler/ProcessDmarcReportHandler.php`
- Uses DmarcXmlParser to parse XML
- Creates DmarcReport entity from parsed data
- Creates DmarcRecord entities for each record in the report
- Compresses and stores rawXml
- Checks for duplicate (by externalReportId + domain) — skip if already exists
- Persists everything
- DmarcReport records `DmarcReportProcessed` event

### 7. Repositories

**`src/Repository/MonitoredDomainRepository.php`:**
- `get(UuidInterface $id): MonitoredDomain`
- `findByDomain(string $domain, UuidInterface $teamId): ?MonitoredDomain`

**`src/Repository/DmarcReportRepository.php`:**
- `get(UuidInterface $id): DmarcReport`
- `existsByExternalId(string $externalReportId, UuidInterface $domainId): bool`

### 8. Queries

**`src/Query/GetDomainOverview.php`:**
- For a team: returns all domains with report count, latest report date, pass rate
- Raw SQL via DBAL Connection
- Returns `DomainOverviewResult[]`

**`src/Query/GetDomainReports.php`:**
- For a domain: returns paginated reports list
- Returns `DomainReportListResult[]` (reportId, reporterOrg, dateRange, recordCount, passRate)

**`src/Query/GetReportDetail.php`:**
- For a report: returns full report with all records
- Returns `ReportDetailResult` with nested `ReportRecordResult[]`

### 9. CLI Summary Command

**`src/Command/DmarcSummaryCommand.php`** — Symfony Console command:
- `php bin/console sendvery:dmarc:summary`
- Options: `--days=7` (default), `--domain=example.com` (optional)
- Output: "Last 7 days: X reports, Y total messages, Z pass, W fail (XX% pass rate), top senders: ..."
- Useful for personal use before the dashboard exists

### 10. CLI Import Command (for testing)

**`src/Command/ImportDmarcReportCommand.php`:**
- `php bin/console sendvery:dmarc:import <file>`
- Accepts path to .xml, .zip, or .gz file
- Uses ReportAttachmentExtractor + ProcessDmarcReport command
- Useful for manually importing report files during development

### 11. Database Migration

Create migration for: `monitored_domain`, `dmarc_report`, `dmarc_record` tables with all columns, indexes, and constraints.

### 12. Tests

**Unit tests:**
- `DmarcXmlParser` — test with sample DMARC XML (create test fixtures for Google, Yahoo, Microsoft report formats)
- `ReportAttachmentExtractor` — test zip extraction, gzip extraction, invalid archive handling
- `DomainHealthScorer` (if not already tested)
- All value objects and enums
- All Results DTOs (`fromDatabaseRow()` methods)

**Integration tests:**
- `ProcessDmarcReportHandler` — full flow: parse XML → create report + records → persist → event recorded
- Duplicate report detection (same externalReportId + domain = skip)
- `GetDomainOverview` query with real data
- `GetDomainReports` query with pagination
- `GetReportDetail` query returns all records
- `MonitoredDomainRepository` and `DmarcReportRepository`

**Functional tests:**
- `sendvery:dmarc:import` command with sample XML file
- `sendvery:dmarc:summary` command output format

**Test fixtures:**
- Create 2-3 sample DMARC XML files in `tests/Fixtures/` (realistic format from Google, Yahoo)
- Create sample .zip and .gz archives containing those XML files

## Verification Checklist

- [ ] Can import a real DMARC report XML via CLI command
- [ ] Parser handles Google, Yahoo, Microsoft report formats
- [ ] Zip and gzip extraction works
- [ ] Duplicate reports are skipped (not re-imported)
- [ ] CLI summary shows correct statistics
- [ ] All queries return correct aggregated data
- [ ] DmarcReportProcessed event fires after successful import
- [ ] All tests pass with 100% coverage
- [ ] Infection passes

## What Comes Next

Stage 8 adds email ingestion — IMAP/POP3 client that connects to mailboxes, downloads DMARC report attachments, and feeds them into the parsing pipeline built here. The parsing infrastructure from this stage is the foundation that ingestion builds on.
