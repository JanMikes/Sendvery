import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// __dirname, not import.meta.url: package.json is CommonJS (it is load-bearing
// for the Tailwind build, which resolves daisyUI from node_modules), so
// Playwright transpiles these files to CJS and import.meta does not exist there.
export const PROJECT_ROOT = resolve(__dirname, '../../..');

/*
 * Written by global-setup.ts, read by the fixtures.
 *
 * A file rather than `process.env`: Playwright forks worker processes, and
 * relying on when exactly a mutated env var is inherited is the kind of
 * assumption that produces a suite which passes on one machine and not another.
 * It also leaves the values on disk, so a developer can see what the last run
 * signed in as.
 */
export const CONTEXT_FILE = resolve(PROJECT_ROOT, 'var/playwright/context.json');

export type BrowserSuiteContext = {
    /** Shared secret for /_test/login — see App\Controller\TestLoginController. */
    loginSecret: string;
    /** Owner of the seeded demo team, resolved from the database, never guessed. */
    demoOwnerEmail: string;
};

export function readSuiteContext(): BrowserSuiteContext {
    try {
        return JSON.parse(readFileSync(CONTEXT_FILE, 'utf8')) as BrowserSuiteContext;
    } catch (cause) {
        throw new Error(
            `Could not read ${CONTEXT_FILE}. It is written by tests/Browser/global-setup.ts — ` +
                'run the suite through `npx playwright test` rather than invoking a spec directly.',
            { cause },
        );
    }
}
