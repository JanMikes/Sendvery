# Stage 8: Email Ingestion (IMAP/POP3)

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, CQRS, Messenger configuration.
2. `docs/04-data-model-protocols.md` — MailboxConnection entity schema, ingestion architecture.
3. `docs/10-libraries-and-tools.md` — IMAP/POP3 library comparison table, recommendations (Webklex, barbushin/php-imap, Horde).
4. `docs/02-architecture.md` — Crons section (poll every 5-15 min), Messenger/Scheduler setup.

## What Already Exists (Stages 1-7 completed)

- Full Symfony 8 project with Docker, Tailwind + daisyUI
- Core infrastructure + identity layer
- Phase 0A complete (marketing site, DNS tools, beta signup, Knowledge Base)
- DMARC report parsing: DmarcXmlParser, ReportAttachmentExtractor, ProcessDmarcReport command/handler
- MonitoredDomain, DmarcReport, DmarcRecord entities
- CLI import command for manual testing
- All tests passing

## What to Build

The automated email ingestion pipeline. Connect to IMAP/POP3 mailboxes, find DMARC report emails, extract attachments, and feed them into the parsing pipeline.

### 1. Choose and Install IMAP/POP3 Library

Based on `docs/10-libraries-and-tools.md`, install one of:
- `webklex/php-imap` (recommended for IMAP — pure PHP, no ext-imap needed)
- If POP3 is needed immediately, also install ext-imap in Dockerfile via PECL, or use Horde

For this stage, start with **Webklex/php-imap** for IMAP support. POP3 can be added as a protocol adapter later.

```bash
composer require webklex/php-imap
```

### 2. MailboxConnection Entity

**`src/Entity/MailboxConnection.php`** — `final class`:
- `id` (UUID v7, readonly)
- `team` (ManyToOne → Team)
- `monitoredDomain` (ManyToOne → MonitoredDomain, nullable — can be set after first report identifies domain)
- `type` (MailboxType enum: `imap_user`, `imap_hosted`, `pop3_user`)
- `host` (string)
- `port` (int)
- `encryptedUsername` (string — encrypted at rest)
- `encryptedPassword` (string — encrypted at rest)
- `encryption` (MailboxEncryption enum: `ssl`, `tls`, `starttls`, `none`)
- `lastPolledAt` (nullable DateTimeImmutable)
- `lastError` (nullable string)
- `isActive` (bool, default true)
- `createdAt` (DateTimeImmutable, readonly)
- Implements EntityWithEvents, records `MailboxConnectionCreated` event

### 3. Value Objects & Enums

**`src/Value/MailboxType.php`:**
```php
enum MailboxType: string
{
    case ImapUser = 'imap_user';
    case ImapHosted = 'imap_hosted';
    case Pop3User = 'pop3_user';
}
```

**`src/Value/MailboxEncryption.php`:**
```php
enum MailboxEncryption: string
{
    case Ssl = 'ssl';
    case Tls = 'tls';
    case StartTls = 'starttls';
    case None = 'none';
}
```

### 4. Credential Encryption Service

**`src/Services/CredentialEncryptor.php`** — `readonly final class`:
- Uses `sodium_crypto_secretbox()` (PHP's built-in libsodium) for symmetric encryption
- Encryption key from `ENCRYPTION_KEY` env var (32 bytes, base64-encoded)
- `encrypt(string $plaintext): string` — returns base64(nonce + ciphertext)
- `decrypt(string $encrypted): string` — extracts nonce, decrypts
- Each encryption generates a random nonce (24 bytes)

Note: For Phase 1 (Stage 12) we'll upgrade to paragonie/halite. For now, native sodium is fine for personal use.

### 5. Mail Client Service

**`src/Services/Mail/MailClient.php`** — interface:
```php
interface MailClient
{
    /** @return iterable<MailMessage> */
    public function fetchDmarcReports(MailboxConnection $connection): iterable;
    public function markAsProcessed(MailboxConnection $connection, MailMessage $message): void;
    public function testConnection(MailboxConnection $connection): ConnectionTestResult;
}
```

**`src/Services/Mail/ImapMailClient.php`** — `readonly final class` implementing MailClient:
- Uses Webklex/php-imap to connect
- Searches for DMARC report emails (subject contains "DMARC", "Report" or sender matches known reporters like `noreply-dmarc-support@google.com`)
- Extracts attachments (.zip, .gz, .xml)
- Moves processed emails to a "Processed" folder (or marks as read)
- Handles connection errors gracefully
- Decrypts credentials via CredentialEncryptor before connecting

**`src/Value/MailMessage.php`** — readonly final class:
- `messageId` (string)
- `subject` (string)
- `from` (string)
- `date` (DateTimeImmutable)
- `attachments` (array of `MailAttachment`)

**`src/Value/MailAttachment.php`** — readonly final class:
- `filename` (string)
- `content` (string — binary content)
- `mimeType` (string)

**`src/Value/ConnectionTestResult.php`** — readonly final class:
- `success` (bool)
- `error` (?string)
- `mailboxCount` (int — number of messages found)

### 6. CQRS for Mailbox Management

**Command:** `src/Message/ConnectMailbox.php`
- `connectionId`, `teamId`, `domainId` (nullable), `type`, `host`, `port`, `username`, `password`, `encryption`

**Handler:** `src/MessageHandler/ConnectMailboxHandler.php`
- Encrypts credentials via CredentialEncryptor
- Creates MailboxConnection entity
- Tests connection (via MailClient::testConnection)
- If test fails, still saves but sets `lastError`

**Command:** `src/Message/PollMailbox.php`
- `connectionId` (UuidInterface)

**Handler:** `src/MessageHandler/PollMailboxHandler.php`
- Loads MailboxConnection
- Calls MailClient::fetchDmarcReports()
- For each attachment: uses ReportAttachmentExtractor → gets XML → dispatches ProcessDmarcReport command
- Updates `lastPolledAt`
- If error: sets `lastError`, keeps `isActive`
- Handles: connection timeout, auth failure, no messages, malformed attachments

### 7. Polling Scheduler

**`src/Scheduler/MailboxPollingProvider.php`:**
- Symfony Scheduler schedule provider
- Generates a `PollMailbox` message for every active MailboxConnection
- Frequency: every 15 minutes (configurable via env var)
- Only polls connections where `isActive = true`

**Alternative:** A Symfony Console command triggered by cron:
**`src/Command/PollMailboxesCommand.php`:**
- `php bin/console sendvery:mailbox:poll`
- Iterates all active MailboxConnection entities
- Dispatches `PollMailbox` command for each via Messenger
- Option: `--connection=UUID` to poll a specific one

### 8. CLI Commands for Development

**`src/Command/TestMailboxConnectionCommand.php`:**
- `php bin/console sendvery:mailbox:test <host> <port> <username> <password>`
- Tests IMAP connection, reports success/failure and message count
- Useful for debugging during development

### 9. Domain Events

- `src/Events/MailboxConnectionCreated.php` — `connectionId`, `teamId`
- `src/Events/MailboxPollCompleted.php` — `connectionId`, `reportsFound` (int), `errors` (int)

### 10. Repository

**`src/Repository/MailboxConnectionRepository.php`:**
- `get(UuidInterface $id): MailboxConnection`
- `findActiveConnections(): array` — all active connections for polling
- `findByTeam(UuidInterface $teamId): array`

### 11. Database Migration

Create migration for `mailbox_connection` table.

### 12. Tests

**Unit tests:**
- `CredentialEncryptor` — encrypt/decrypt roundtrip, different nonces each time
- `ReportAttachmentExtractor` (if not already tested in Stage 7)
- `MailMessage` and `MailAttachment` value objects
- `PollMailboxHandler` logic — mock MailClient, verify processing flow

**Integration tests:**
- `ConnectMailboxHandler` — creates entity with encrypted credentials, test connection called
- `PollMailboxHandler` — mock MailClient returns messages, verify ProcessDmarcReport dispatched for each attachment
- Full pipeline: poll → extract → parse → store (end-to-end with mocked IMAP)
- Error handling: connection failure sets lastError, invalid attachment skipped

**Functional tests:**
- `sendvery:mailbox:poll` command runs without errors (with no active connections)
- `sendvery:mailbox:test` command output format

**Note on IMAP mocking:** Create a `FakeMailClient` implementing `MailClient` for tests. Don't hit real IMAP servers in tests.

## Verification Checklist

- [ ] Can create a MailboxConnection with encrypted credentials
- [ ] Credentials are encrypted at rest (verify in DB — not plaintext)
- [ ] Connection test command works against a real IMAP server (manual test)
- [ ] Polling fetches DMARC report emails and extracts attachments
- [ ] Extracted attachments are fed into the parsing pipeline
- [ ] Processed emails are marked/moved so they aren't re-processed
- [ ] Polling scheduler/command dispatches poll for all active connections
- [ ] Error handling: bad connection → lastError set, bad attachment → skipped, processing continues
- [ ] All tests pass with 100% coverage
- [ ] Infection passes

## What Comes Next

Stage 9 builds the personal dashboard — a web UI to view the parsed DMARC data. With ingestion and parsing working, we now have data to display. The dashboard uses the queries from Stage 7 (GetDomainOverview, GetDomainReports, GetReportDetail) to render charts and tables.
