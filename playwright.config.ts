import { defineConfig, devices } from '@playwright/test';

/*
 * Browser smoke net. NOT a second test suite.
 *
 * It exists for the defects PHPUnit cannot see: CSS hit-testing (every report
 * row opening the same report), Stimulus/Turbo breakage that only shows up as a
 * console error, and the accessibility state of the rendered page. Everything
 * else belongs in tests/Unit and tests/Integration, which are faster and can
 * assert far more.
 *
 * WHY PLAYWRIGHT AND NOT SYMFONY PANTHER: the highest-value assertion here is
 * "zero console errors per page". Panther drives Chrome over W3C WebDriver,
 * where reading the browser log is a non-standard ChromeDriver extension that
 * current Chrome does not reliably serve. Playwright exposes `pageerror` and
 * `console` as first-class events, its locators auto-scroll (so no test ever
 * needs raw coordinates), and @axe-core/playwright is the standard axe
 * integration.
 *
 * Prerequisites — the suite asserts them rather than guessing:
 *   1. the app answering on SENDVERY_BASE_URL (default http://localhost),
 *   2. demo data, which tests/Browser/global-setup.ts seeds for you.
 */
export default defineConfig({
    testDir: './tests/Browser',
    globalSetup: './tests/Browser/global-setup.ts',
    outputDir: './var/playwright/results',

    // A smoke net that takes minutes stops being run. If a single check needs
    // more than 30s, the page is broken in a way worth failing over.
    timeout: 30_000,
    expect: { timeout: 7_000 },

    // Deliberately serial: the suite shares ONE seeded database, and the alerts
    // bulk-action check mutates it. Parallel workers would trade a
    // 40-second run for flakes nobody can reproduce.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,

    reporter: process.env.CI ? [['github'], ['list']] : [['list']],

    use: {
        baseURL: process.env.SENDVERY_BASE_URL ?? 'http://localhost',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        // The guided DNS setup's "Copy" button writes to the clipboard, and the
        // only honest way to assert it copied the right value is to read the
        // clipboard back.
        permissions: ['clipboard-read', 'clipboard-write'],
    },

    projects: [
        {
            name: 'chromium',
            // Bundled Chromium, not `channel: 'chrome'` — a pinned browser
            // build is the only way an axe baseline stays comparable between a
            // laptop and CI.
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
