# Stage 12: Weekly Digest & Beta Polish

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions.
2. `docs/03-features-roadmap.md` — Phase 1 scope: basic email digest (non-AI, weekly summary stats), credential encryption, invite beta users, feedback mechanism.
3. `docs/10-libraries-and-tools.md` — paragonie/halite for credential encryption.
4. `docs/11-startup-essentials.md` — Churn prevention: "the weekly digest IS the product."

## What Already Exists (Stages 1-11 completed)

- Full Symfony 8 project, all infrastructure
- Phase 0A complete (marketing site, DNS tools, beta signup, Knowledge Base)
- Phase 0B complete (DMARC parsing, email ingestion, personal dashboard)
- Auth (magic link), onboarding, team-scoped dashboard
- DNS monitoring (scheduled checks, change detection)
- Alerting system (DNS changes, new senders, failure spikes, policy recommendations)
- All tests passing

## What to Build

The final Phase 1 pieces: weekly digest email (the recurring touchpoint that keeps users engaged), credential encryption upgrade, beta invitation system, and overall UX polish.

### 1. Weekly Digest Email

**The weekly digest IS the product** — for many users, especially non-technical ones, the weekly email is their primary interaction with Sendvery. It must be clear, useful, and beautifully formatted.

**`src/Services/Digest/WeeklyDigestGenerator.php`** — `readonly final class`:
- Input: Team
- Aggregates the past 7 days of data:
  - Per domain: total messages, pass rate, pass rate change vs previous week
  - New senders detected (with count)
  - DNS changes detected
  - Active alerts (unresolved)
  - Overall health score trend (if available)
- Returns `WeeklyDigestData` value object

**`src/Value/WeeklyDigestData.php`** — readonly final class:
- `teamName` (string)
- `periodStart`, `periodEnd` (DateTimeImmutable)
- `domains[]` — each: domainName, totalMessages, passRate, passRateDelta, newSenders[], alerts[]
- `summary` — overall stats: totalDomains, totalMessages, averagePassRate, alertsCount

**`src/Message/SendWeeklyDigest.php`:**
- `teamId` (UuidInterface)

**`src/MessageHandler/SendWeeklyDigestHandler.php`:**
- Generates digest data
- Renders email template
- Sends to all team members with `Member` role or higher

**`templates/emails/weekly_digest.html.twig`:**
- Branded HTML email
- Subject: "Sendvery Weekly Report — [Team Name] — [Date Range]"
- Sections:
  1. Summary banner (total messages, overall pass rate, trend arrow)
  2. Per-domain breakdown table (domain, messages, pass rate, trend, issues)
  3. Alerts this week (if any, with severity icons)
  4. New senders discovered (if any)
  5. DNS changes detected (if any)
  6. "View full dashboard" CTA button
- Plain text alternative
- Unsubscribe link (per-user email preferences)

**Scheduler:**
- `src/Command/SendAllWeeklyDigestsCommand.php`: `php bin/console sendvery:digest:send-all`
- Iterates all active teams, dispatches `SendWeeklyDigest` for each
- Scheduled: every Monday at 9:00 AM (configurable)

### 2. Credential Encryption Upgrade (Halite)

Install `paragonie/halite`:
```bash
composer require paragonie/halite
```

**Update `src/Services/CredentialEncryptor.php`:**
- Replace native sodium_* calls with Halite's symmetric encryption
- Use `HiddenString` for sensitive data
- Use `KeyFactory::importEncryptionKey()` from env var
- Backward compatible: can still decrypt old sodium_crypto_secretbox data during migration
- Create a CLI command to re-encrypt existing credentials: `php bin/console sendvery:credentials:migrate`

### 3. Beta Invitation System

**`src/Entity/BetaInvitation.php`:**
- `id` (UUID v7)
- `email` (string)
- `invitedBy` (ManyToOne → User, nullable — null for system invites)
- `invitationToken` (string, unique)
- `status` (InvitationStatus enum: `pending`, `accepted`, `expired`)
- `sentAt` (DateTimeImmutable)
- `acceptedAt` (nullable DateTimeImmutable)
- `expiresAt` (DateTimeImmutable — 7 days)

**`src/Value/InvitationStatus.php`:**
```php
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
}
```

**`src/Controller/Admin/InviteBetaUserController.php`:**
- Route: `/app/admin/invite`
- Form: email address(es), batch invite
- Sends invitation email with magic link
- Only accessible by team owners (for now — later admin panel)

**`src/Controller/Auth/AcceptInvitationController.php`:**
- Route: `/invite/{token}`
- Validates invitation token (not expired, not used)
- If user exists: log in, mark invitation accepted
- If user doesn't exist: create user, log in, redirect to onboarding

**Invitation email template:** `templates/emails/beta_invitation.html.twig`

### 4. User Email Preferences

**Add to User entity:**
- `emailDigestEnabled` (bool, default true)
- `emailAlertsEnabled` (bool, default true)

**`src/Controller/Dashboard/UserPreferencesController.php`:**
- Route: `/app/settings/preferences`
- Toggle email digest and alert notifications
- Save preferences

**Update digest and alert email senders** to check user preferences before sending.

### 5. Feedback Mechanism

**`src/Entity/UserFeedback.php`:**
- `id` (UUID v7)
- `user` (ManyToOne → User)
- `team` (ManyToOne → Team)
- `type` (FeedbackType enum: `bug`, `feature_request`, `general`)
- `message` (text)
- `page` (string — URL where feedback was submitted)
- `createdAt` (DateTimeImmutable)

**In-app feedback widget:**
- Floating "Feedback" button in dashboard (bottom-right)
- Opens modal with: type selector (Bug / Feature Request / General), message textarea
- Submits via Turbo Frame
- Shows "Thank you!" confirmation

**`src/Controller/Dashboard/SubmitFeedbackController.php`:**
- Route: `/app/feedback`
- Handles form submission
- Sends notification email to admin (Jan) when feedback is received

### 6. UX Polish

**Empty states** — for every page that can have no data:
- Dashboard overview: "No reports yet. Add a domain and connect a mailbox to get started." + onboarding CTA
- Domain list: "No domains yet. Add your first domain." + add button
- Reports list: "No reports received yet. Reports usually arrive within 24-48 hours."
- Alerts: "No alerts. Everything looks good!" (with green checkmark)
- Mailboxes: "No mailbox connections. Connect one to start receiving reports."

**Error states:**
- Mailbox connection failed → clear error message with troubleshooting tips
- DNS check timeout → "DNS check timed out. We'll try again on the next scheduled check."
- Invalid DMARC XML → "This report could not be parsed. The original file has been preserved."

**Loading indicators:**
- Turbo Frame loading → spinner or skeleton screen
- DNS checker tool → "Checking DNS records..." with animated dots
- Chart loading → skeleton placeholder

**Toast notifications (daisyUI alerts):**
- Success: "Domain added successfully", "Mailbox connected"
- Error: "Connection failed: ..."
- Info: "Checking DNS records..."

**Breadcrumbs:**
- Every dashboard page has breadcrumbs: Dashboard > Domains > example.com > DNS History

### 7. Database Migration

Create migration for: `beta_invitation`, `user_feedback` tables, add columns to `user` table.

### 8. Tests

**Unit tests:**
- WeeklyDigestGenerator (data aggregation logic, edge cases)
- CredentialEncryptor with Halite (encrypt/decrypt, backward compat)
- BetaInvitation entity (creation, expiration, acceptance)
- All new enums and value objects

**Integration tests:**
- Full digest flow: generate data → render email → send to team members
- Digest respects email preferences (disabled = no email)
- Beta invitation flow: invite → email sent → accept → user created/logged in
- Feedback submission creates entity and sends admin notification
- Credential migration command re-encrypts correctly

**Functional tests:**
- `sendvery:digest:send-all` command
- Invitation pages (accept valid/expired/used token)
- User preferences page
- Feedback widget submission
- Empty states render correctly on all pages

## Verification Checklist

- [ ] Weekly digest email generates and sends with correct data
- [ ] Digest email is well-formatted (HTML + plain text)
- [ ] Credential encryption uses Halite, backward compatible
- [ ] Beta invitations work end-to-end
- [ ] User preferences control email delivery
- [ ] Feedback widget works from any dashboard page
- [ ] Empty states show helpful messages on all pages
- [ ] Error states display clearly with recovery guidance
- [ ] Loading states visible during async operations
- [ ] All tests pass, 100% coverage
- [ ] **Phase 1 is complete** — ready for closed beta users

## What Comes Next

**Phase 1 is complete after this stage.** Beta users can be invited, everything is secured, digest emails keep them engaged.

Stage 13 begins Phase 2: Stripe billing integration. The app transitions from free beta to a paid product with subscription plans and usage enforcement.
