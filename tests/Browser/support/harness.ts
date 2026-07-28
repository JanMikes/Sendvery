import { ConsoleMessage, Page, expect, test as base } from '@playwright/test';
import { readSuiteContext } from './context';

/*
 * The shared harness every spec imports instead of @playwright/test.
 *
 * Two things are wired here rather than in each spec:
 *
 *  1. ZERO CONSOLE ERRORS, asserted by overriding the `context` fixture and
 *     attaching to `context.on('page')`. That covers the built-in `page` AND any
 *     page a spec opens with `context.newPage()`, so there is no block to copy
 *     and therefore none to leave out of a new spec.
 *
 *     THE BOUNDARY, stated rather than overclaimed: a spec that builds its own
 *     context from the `browser` fixture (`browser.newContext()`) is outside
 *     this, and would pass while logging errors. Take `page` or `context`
 *     instead. Nothing in tests/Browser/ uses `browser` today.
 *
 *     This assertion is what catches Stimulus/Turbo breakage: a controller that
 *     throws still renders a page that looks fine and passes every server-side
 *     test.
 *
 *  2. SIGN-IN, automatically. Every surface worth smoke-testing is behind
 *     ROLE_USER, and the product's only real login is a magic link throttled to
 *     5/hour that fails silently past the cap. /_test/login is the documented
 *     way in (see App\Controller\TestLoginController).
 */

type Harness = {
    /** Console errors and uncaught exceptions the app itself is answerable for. */
    pageProblems: string[];
    /** Auto-used: signs the browser in before the test body runs. */
    signedIn: void;
};

/**
 * The Symfony web debug toolbar fetches `/_wdt/<token>` after every page load
 * and, under rapid automated navigation, occasionally gets a 404 for a profile
 * that has not been flushed yet. Dev-only chrome that no customer sees and no
 * product change can fix.
 */
const DEV_TOOLBAR_URL = /\/_(wdt|profiler)\//;

/**
 * Whether the app is answerable for a console error.
 *
 * Anything that failed to load from a THIRD-PARTY origin is not:
 * templates/base.html.twig pulls Inter and JetBrains Mono from
 * fonts.googleapis.com on every page, so one CDN blip on a CI runner would
 * otherwise turn the whole suite red with a message blaming the product. A suite
 * that cries wolf gets re-run instead of read — the exact credibility loss this
 * workstream exists to prevent. (The fonts are deliberately NOT stubbed: the
 * value of a browser test is that it renders what a user renders.)
 *
 * Fails CLOSED: a message with no parseable location counts as the app's,
 * because "we could not tell" must never become "not our problem".
 */
function isOursToFix(location: string, appOrigin: string): boolean {
    if ('' === location) {
        return true;
    }

    let origin: string;

    try {
        origin = new URL(location).origin;
    } catch {
        return true;
    }

    if (origin !== appOrigin) {
        return false;
    }

    return !DEV_TOOLBAR_URL.test(location);
}

function watch(page: Page, appOrigin: string, ours: string[], theirs: string[]): void {
    page.on('pageerror', (error) => {
        // An uncaught exception is always ours: our code threw, even if a
        // third-party resource provoked it.
        ours.push(`uncaught ${error.name}: ${error.message}`);
    });

    page.on('console', (message: ConsoleMessage) => {
        if ('error' !== message.type()) {
            return;
        }

        const location = message.location().url;
        const entry = `console.error at ${'' === location ? '<no location>' : location}: ${message.text()}`;

        // Collected, never annotated from inside the listener: a console event
        // can arrive while the page is closing, and test.info() is unavailable
        // then.
        (isOursToFix(location, appOrigin) ? ours : theirs).push(entry);
    });
}

export const test = base.extend<Harness>({
    pageProblems: async ({}, use) => {
        await use([]);
    },

    context: async ({ context, pageProblems, baseURL }, use) => {
        const appOrigin = new URL(baseURL ?? 'http://localhost').origin;
        const notOurs: string[] = [];

        context.on('page', (page) => watch(page, appOrigin, pageProblems, notOurs));
        // Belt and braces for a context that already has a page by the time this
        // fixture runs.
        for (const page of context.pages()) {
            watch(page, appOrigin, pageProblems, notOurs);
        }

        await use(context);

        for (const entry of notOurs) {
            // Recorded rather than silently dropped, so the exclusion shows up in
            // the run report instead of becoming folklore.
            test.info().annotations.push({ type: 'third-party-console-error', description: entry });
        }

        expect(
            pageProblems,
            'Pages visited by this test logged errors in the browser console. A page that throws still ' +
                'renders and still passes every server-side test, so this is the only place it shows up. ' +
                '(Third-party resource failures are annotated instead — this list is app-origin only.)',
        ).toEqual([]);
    },

    signedIn: [
        async ({ page }, use) => {
            const { loginSecret, demoOwnerEmail } = readSuiteContext();
            const query = new URLSearchParams({ secret: loginSecret, email: demoOwnerEmail });

            await page.goto(`/_test/login?${query.toString()}`);

            // Landing anywhere else means the sign-in did not take: /login when
            // the secret is wrong, an onboarding step when the seeded account is
            // not onboarded. Both are worth naming here rather than leaving every
            // spec to fail on a missing table.
            await expect(
                page,
                'Signing in should land on the dashboard overview. Anything else means /_test/login ' +
                    'did not authenticate this account.',
            ).toHaveURL(/\/app$/);

            await use();
        },
        { auto: true },
    ],
});

export { expect };

/**
 * Row counts the seeded demo data makes exact. Asserting the exact number
 * matters: `toBeGreaterThan(0)`-style checks keep passing when a list silently
 * empties, and a cap assertion (`at most N`) passes hardest of all when the
 * feature is switched off entirely.
 */
export const SEEDED = {
    /** ListReportsController: 25 per page, and the seeder makes 90 reports, so page 1 is full. */
    reportsPerPage: 25,
    /** DashboardOverviewController passes limit: 10 to the Recent Reports query. */
    recentReportsOnOverview: 10,
    /** SeedDemoDataCommand::ALERT_COUNT, and /app/alerts is unpaginated. */
    alerts: 5,
    /** acme.example, okay.example, broken.example. */
    domains: 3,
} as const;
