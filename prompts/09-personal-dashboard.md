# Stage 9: Personal Dashboard

## Context

You are building **Sendvery**, an email health & deliverability micro-SaaS.

**Before writing any code, read these files:**
1. `CLAUDE.md` — **MANDATORY.** Architecture conventions, single-action controllers.
2. `docs/09-design-and-branding.md` — Dashboard design section: "Dashboard / Admin Panel", key screens list, component library specs, design principles (function over form, information density, consistency, fast).
3. `docs/10-libraries-and-tools.md` — ApexCharts recommendation, daisyUI for components, Heroicons + Lucide for icons.

## What Already Exists (Stages 1-8 completed)

- Full Symfony 8 project with Docker, Tailwind + daisyUI
- Core infrastructure + identity layer + multi-tenancy (TeamFilter)
- Phase 0A complete (marketing site, DNS tools, beta signup, Knowledge Base)
- Phase 0B partially complete: DMARC parsing + email ingestion pipeline working
- Entities: MonitoredDomain, DmarcReport, DmarcRecord, MailboxConnection
- Queries: GetDomainOverview, GetDomainReports, GetReportDetail
- CLI commands for import and summary
- All tests passing

## What to Build

The web dashboard for viewing parsed DMARC data. This is the "personal use" dashboard — Phase 0B's goal is for Jan to stop deleting DMARC reports and actually understand them. Auth comes in Stage 10; for now, the dashboard is unsecured (personal use only).

### 1. Dashboard Layout

**`templates/dashboard/layout.html.twig`:**
- Sidebar navigation (collapsible on mobile)
- Main content area
- Top bar with breadcrumbs and quick actions
- Different from marketing layout — this is the app, not the website
- Dark mode support
- Responsive: sidebar collapses to bottom nav or hamburger on mobile

**Sidebar navigation items:**
- Dashboard (overview)
- Domains (list)
- Reports (all reports)
- DNS Health (checker, reuses tool page logic)
- Mailboxes (connection management)
- Settings (placeholder for now)

### 2. Twig Components (Dashboard-specific)

Build reusable Twig components:

**`StatCard`** — metric display card:
- Title, value, optional trend (up/down arrow with percentage)
- Color variants for status (green/amber/red)
- Used for: total reports, pass rate, domains monitored, active alerts

**`DataTable`** — sortable, filterable table:
- Column headers with sort indicators
- Optional filters row
- Pagination (Turbo Frame for ajax pagination)
- Empty state component

**`StatusBadge`** — pass/fail/warning indicator:
- Used for auth results, policy status, DNS health

**`ChartCard`** — card wrapping an ApexCharts chart:
- Title, optional description, chart area
- Loading state, empty state

**`DomainCard`** — summary card for a monitored domain:
- Domain name, DMARC policy badge, pass rate, report count, last report date
- Link to domain detail

**`EmptyState`** — for pages with no data:
- Illustration (from unDraw or similar), message, CTA button

### 3. ApexCharts Setup

Install ApexCharts via AssetMapper or importmap:
- Configure Stimulus controller for ApexCharts integration
- Create a generic `chart-controller` Stimulus controller that:
  - Takes chart config as a JSON data attribute
  - Renders the chart on connect
  - Supports theme switching (dark/light mode)

### 4. Dashboard Overview Page

**`src/Controller/Dashboard/DashboardOverviewController.php`:**
- Route: `/app`
- Uses queries to aggregate:
  - Total monitored domains
  - Total reports (last 30 days)
  - Overall DMARC pass rate (last 30 days)
  - Active alerts count (placeholder — alerts come in Stage 11)
- Renders:
  - 4 stat cards (domains, reports, pass rate, alerts)
  - DMARC pass/fail trend chart (line chart, last 30 days)
  - Recent reports table (last 10)
  - Domain health summary cards

**New query:** `src/Query/GetDashboardStats.php`
- Returns `DashboardStatsResult` with aggregate metrics

### 5. Domains List Page

**`src/Controller/Dashboard/ListDomainsController.php`:**
- Route: `/app/domains`
- Uses `GetDomainOverview` query
- Renders domain cards in a grid
- "Add domain" button (links to add form)
- Empty state if no domains

### 6. Domain Detail Page

**`src/Controller/Dashboard/ShowDomainDetailController.php`:**
- Route: `/app/domains/{id}`
- Shows:
  - Domain header (name, policy badge, verification status)
  - DMARC pass/fail rate chart (line, last 90 days)
  - Sender breakdown (bar chart — top 10 source IPs/orgs by message count)
  - DNS record status (current SPF/DKIM/DMARC records, using DNS checker from Stage 5)
  - Recent reports table
  - Quick stats: total messages, pass rate, unique senders, reports count

**New queries:**
- `src/Query/GetDomainDetail.php` — domain info + aggregate stats
- `src/Query/GetDomainSenderBreakdown.php` — top senders for a domain, raw SQL
- `src/Query/GetDomainPassRateTrend.php` — daily pass/fail counts for chart data

### 7. Reports List Page

**`src/Controller/Dashboard/ListReportsController.php`:**
- Route: `/app/reports` (all reports) and `/app/domains/{id}/reports` (domain-scoped)
- Uses `GetDomainReports` query
- Filterable by: domain, date range, reporter org
- Sortable by: date, reporter, record count
- Paginated (Turbo Frame)

### 8. Report Detail Page

**`src/Controller/Dashboard/ShowReportDetailController.php`:**
- Route: `/app/reports/{id}`
- Uses `GetReportDetail` query
- Shows:
  - Report metadata (reporter, date range, external ID)
  - Published policy section
  - Records table: source IP, count, SPF result, DKIM result, disposition, header from
  - Pass/fail summary (donut chart)
  - Source IP analysis (group by resolved org if available)

### 9. Add Domain Page

**`src/Controller/Dashboard/AddDomainController.php`:**
- Route: `/app/domains/add`
- Simple form: domain name input
- Dispatches `AddDomain` command
- Redirects to domain detail page after creation

**Command:** `src/Message/AddDomain.php` (if not already created in previous stages)
**Handler:** Creates MonitoredDomain, runs initial DNS check

### 10. Mailbox Management Page

**`src/Controller/Dashboard/ListMailboxesController.php`:**
- Route: `/app/mailboxes`
- Lists MailboxConnection entities for the team
- Shows: host, type, last polled, status (active/error), last error

**`src/Controller/Dashboard/AddMailboxController.php`:**
- Route: `/app/mailboxes/add`
- Form: host, port, username, password, encryption type, protocol type
- Dispatches `ConnectMailbox` command
- Shows connection test result

### 11. Turbo Frame Integration

Use Turbo Frames for:
- Pagination on tables (load next page without full reload)
- Chart data updates (refresh chart data via async frame)
- Mailbox connection test results
- Domain add form submission

### 12. Tests

**Functional tests (WebTestCase):**
- Dashboard overview returns 200 with correct structure
- Domains list renders domain cards
- Domain detail shows charts, sender breakdown, reports
- Reports list with filters and pagination
- Report detail shows all records
- Add domain form submission creates entity and redirects
- Add mailbox form submission creates entity
- Mailbox list shows connections
- Empty states render correctly (no domains, no reports)
- All pages use dashboard layout (not marketing layout)

**Unit tests:**
- All new queries (GetDashboardStats, GetDomainDetail, GetDomainSenderBreakdown, GetDomainPassRateTrend)
- All new Results DTOs
- StatCard, DataTable component rendering (if using live components)

## Verification Checklist

- [ ] Dashboard overview shows aggregate stats and charts
- [ ] Domain list shows all monitored domains with health indicators
- [ ] Domain detail shows comprehensive data: charts, senders, DNS status, reports
- [ ] Reports can be browsed, filtered, and paginated
- [ ] Report detail shows all records in a readable table
- [ ] Can add a new domain via the dashboard
- [ ] Can add a mailbox connection via the dashboard
- [ ] Charts render with ApexCharts (pass/fail trends, sender breakdown)
- [ ] Turbo Frames work for pagination and form submissions
- [ ] Dashboard is responsive (mobile, tablet, desktop)
- [ ] Dark mode works on all dashboard pages
- [ ] All tests pass, 100% coverage on new code

## What Comes Next

**Phase 0B is complete after this stage.** The personal dashboard provides visibility into DMARC data. Jan can stop deleting reports.

Stage 10 begins Phase 1 (closed beta): adding magic link authentication, user registration, and the onboarding flow that guides new users through domain setup and ingestion configuration.
