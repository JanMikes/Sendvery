# Stage 10: Authentication & Onboarding

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, authentication section (magic link, no passwords, DEC-035).
2. `docs/04-data-model-protocols.md` — User entity (no password field), Team, TeamMembership, authorization model.
3. `docs/02-architecture.md` — Security architecture, OAuth2 for Gmail/Microsoft (DEC-034).
4. `docs/03-features-roadmap.md` — Phase 1 scope: authentication, onboarding flow, two ingestion methods.

## What Already Exists (Stages 1-9 completed)

- Full Symfony 8 project with Docker, all infrastructure
- Phase 0A complete (marketing site, DNS tools, beta signup, Knowledge Base)
- Phase 0B complete (DMARC parsing, email ingestion, personal dashboard)
- User entity exists (from Stage 3) but no auth flow yet
- Dashboard is currently unsecured
- All tests passing

## What to Build

Magic link authentication and the onboarding flow. After this stage, the dashboard is secured and new users can register, create a team, add domains, and connect mailboxes through a guided wizard.

### 1. Magic Link Authentication

**How it works (no passwords):**
1. User enters email at `/login`
2. System generates a time-limited token, stores it, emails a login link
3. User clicks link → `/login/verify/{token}`
4. Token is validated → user is logged in with a long-lived session
5. If user doesn't exist → create account automatically (registration = first login)

**Entity:** `src/Entity/MagicLinkToken.php`:
- `id` (UUID v7)
- `user` (ManyToOne → User, nullable — null if user doesn't exist yet)
- `email` (string — the email they entered)
- `token` (string, unique — 64-char random hex)
- `expiresAt` (DateTimeImmutable — 15 minutes from creation)
- `usedAt` (nullable DateTimeImmutable — set when consumed)
- `createdAt` (DateTimeImmutable)

**`src/Services/MagicLinkAuthenticator.php`** — custom Symfony Security authenticator:
- Implements `AuthenticatorInterface` (Symfony Security)
- Supports the `/login/verify/{token}` route
- Validates token: exists, not expired, not used
- If user exists for that email → log in
- If user doesn't exist → create User, create default Team, create TeamMembership (owner), log in
- Marks token as used
- Sets long-lived session (configurable, default 30 days)

### 2. Login Controllers

**`src/Controller/Auth/LoginController.php`:**
- Route: `/login`
- GET: renders login form (just email input)
- POST: validates email, creates MagicLinkToken, sends login email, shows "Check your email" page
- Rate limit: max 5 login attempts per email per hour

**`src/Controller/Auth/VerifyMagicLinkController.php`:**
- Route: `/login/verify/{token}`
- Consumed by the MagicLinkAuthenticator
- On success: redirect to dashboard or onboarding (if new user)
- On failure (expired/invalid): show error with option to request new link

**`src/Controller/Auth/LogoutController.php`:**
- Route: `/logout`
- Standard Symfony logout handler

### 3. Login Email

**`templates/emails/magic_link.html.twig`:**
- Subject: "Sign in to Sendvery"
- Clean, branded design
- "Click to sign in" button with the token link
- "This link expires in 15 minutes"
- Plain text alternative

### 4. Security Configuration

Update `config/packages/security.php`:
- Custom authenticator for magic link
- Firewall: `main` with the custom authenticator
- Access control:
  - `/app/*` requires `ROLE_USER`
  - `/login*` is public
  - `/tools/*` is public
  - `/learn/*` is public
  - `/beta/*` is public
  - Everything else is public (landing page)
- Remember me: enabled with long-lived token (30 days)
- Session configuration: long-lived sessions

### 5. Onboarding Flow

After first login (new user), redirect to an onboarding wizard. The wizard is 3 steps:

**Step 1 — Welcome + Team Name**
- Route: `/app/onboarding/team`
- "Welcome to Sendvery! Let's set up your account."
- Team name input (pre-filled with email domain, e.g., "example.com")
- "Continue" button

**Step 2 — Add Your First Domain**
- Route: `/app/onboarding/domain`
- Domain name input
- "We'll check your DNS records and start monitoring"
- Runs DNS check on submission (shows SPF/DKIM/DMARC status immediately)
- "Continue" button

**Step 3 — Connect Email (Ingestion Method)**
- Route: `/app/onboarding/ingestion`
- Two options:
  1. **"I'll forward reports"** — shows instructions to set `rua=mailto:...` in their DMARC record
  2. **"Connect my mailbox"** — shows IMAP/POP3 connection form (host, port, username, password, encryption)
- Connection test feedback
- "Finish setup" button

**`src/Controller/Onboarding/OnboardingTeamController.php`**
**`src/Controller/Onboarding/OnboardingDomainController.php`**
**`src/Controller/Onboarding/OnboardingIngestionController.php`**
**`src/Controller/Onboarding/OnboardingCompleteController.php`** — route `/app/onboarding/complete`, shows "You're all set!" + redirects to dashboard

### 6. Onboarding State Tracking

**`src/Services/OnboardingTracker.php`:**
- Tracks which onboarding steps the user has completed
- Stores state on User entity (add `onboardingCompletedAt` nullable DateTimeImmutable field)
- Middleware/event listener: if user is authenticated but `onboardingCompletedAt` is null, redirect to onboarding
- After all steps complete → set `onboardingCompletedAt`

### 7. TeamContext Integration

Update `TeamContext` service (from Stage 3) to:
- Read the current team from the authenticated user's session
- On login: store the user's default team (first team membership) in session
- Dashboard controllers use the team from context for all queries

### 8. Secure the Dashboard

- All `/app/*` routes now require authentication
- Dashboard queries use `TeamContext::getCurrentTeamId()` to scope data
- TeamFilter is enabled for all authenticated requests
- Unauthenticated access to `/app/*` redirects to `/login`

### 9. Navigation Updates

Update dashboard navigation:
- Show user email in top bar
- Logout button
- Team name in sidebar header
- If user has multiple teams (future), add team switcher placeholder

Update marketing site navigation:
- Show "Dashboard" link if authenticated, "Sign in" if not
- Show "Sign in" button in nav

### 10. Database Migration

- `magic_link_token` table
- Add `onboarding_completed_at` column to `user` table

### 11. Tests

**Unit tests:**
- MagicLinkToken entity (creation, expiration check, usage)
- MagicLinkAuthenticator (token validation, user creation flow, existing user flow)
- OnboardingTracker (step completion logic)
- Rate limiting logic

**Integration tests:**
- Full magic link flow: request login → token created → email sent → verify token → user logged in
- New user flow: verify token → User + Team + Membership created → redirect to onboarding
- Existing user flow: verify token → logged in → redirect to dashboard
- Expired token → error page
- Used token → error page
- Onboarding flow: team → domain → ingestion → complete → dashboard
- Dashboard access without auth → redirect to login

**Functional tests:**
- GET /login returns form
- POST /login with email → "check your email" page
- GET /login/verify/{valid-token} → redirect to dashboard
- GET /login/verify/{expired-token} → error page
- GET /app without auth → redirect to /login
- GET /app with auth → 200
- Onboarding pages accessible only when onboarding not completed
- Dashboard pages not accessible when onboarding not completed

## Verification Checklist

- [ ] Magic link login works end-to-end (email → click → authenticated)
- [ ] New users are auto-registered on first login
- [ ] Default team is created for new users
- [ ] Onboarding wizard guides through 3 steps
- [ ] DNS check runs during onboarding domain step
- [ ] Mailbox connection test works during onboarding
- [ ] Dashboard is secured (redirect to login if unauthenticated)
- [ ] All queries are team-scoped
- [ ] Long-lived sessions work (user stays logged in)
- [ ] All tests pass, 100% coverage on new code

## What Comes Next

Stage 11 adds DNS monitoring (scheduled DNS checks, change detection) and the alerting system. With auth in place, alerts can be per-team and per-user.
