import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import type { FullConfig } from '@playwright/test';
import { runConsole, seedDemoData } from './support/console';
import { BrowserSuiteContext, CONTEXT_FILE, PROJECT_ROOT } from './support/context';

/*
 * Puts the app into the one state the suite is written against, and refuses to
 * run if it cannot.
 *
 * Everything here is a precondition the specs would otherwise assert
 * indirectly, producing a failure that reads like a product bug when it is
 * really "you forgot to seed". Each step fails with the command to run.
 */

const DEMO_TEAM_SLUG = 'demo-team';

async function assertAppIsReachable(baseURL: string): Promise<void> {
    let status: number;

    try {
        status = (await fetch(baseURL, { redirect: 'manual' })).status;
    } catch (cause) {
        throw new Error(
            `The app is not answering on ${baseURL}. Start it (\`docker compose up -d\`) or point ` +
                'SENDVERY_BASE_URL at a running instance.',
            { cause },
        );
    }

    if (status >= 400) {
        throw new Error(`The app answered ${status} on ${baseURL}; the browser suite needs a healthy app.`);
    }
}

/**
 * Never a literal copy of the secret: it is configured outside this file, so
 * changing it there must not silently turn every test into a 404.
 *
 * `.env.dev.local` and not `.env.dev`: the secret signs in as any existing user
 * in any team, and `.env.dev` is committed to a public repository. Only
 * `.env.*.local` is ignored by both .gitignore and .dockerignore.
 */
function resolveLoginSecret(): string {
    const fromEnvironment = process.env.SENDVERY_TEST_LOGIN_SECRET;

    if (undefined !== fromEnvironment && '' !== fromEnvironment) {
        return fromEnvironment;
    }

    // Same precedence Symfony Dotenv uses: the .local file wins.
    for (const file of ['.env.dev.local', '.env.dev']) {
        const path = resolve(PROJECT_ROOT, file);

        if (!existsSync(path)) {
            continue;
        }

        const configured = readFileSync(path, 'utf8').match(/^SENDVERY_TEST_LOGIN_SECRET=(.+)$/m)?.[1]?.trim();

        if (undefined !== configured && '' !== configured) {
            return configured;
        }
    }

    throw new Error(
        [
            'The browser suite has no sign-in secret, so it cannot log in.',
            '',
            'This is a one-time local setup step. Run these two commands from the project root:',
            '',
            "    echo 'SENDVERY_TEST_LOGIN_SECRET=pick-something-random' > .env.dev.local",
            '    docker compose restart app',
            '',
            'Then run the suite again. .env.dev.local is gitignored and dockerignored on purpose —',
            '/_test/login signs in as any existing user, so its secret must never be committed or',
            'baked into an image. The restart is needed because the app reads env files at boot.',
            '',
            'In CI, set SENDVERY_TEST_LOGIN_SECRET in the job environment instead (both the app and',
            'this suite read it from there) — see the `browser` job in .github/workflows/ci.yml.',
        ].join('\n'),
    );
}

/**
 * The seeder ADOPTS the first existing user rather than always creating
 * demo@sendvery.test (src/Command/SeedDemoDataCommand.php), so which account
 * owns the demo team depends on the database. Asking is the only way to be
 * right on both a fresh CI database and a laptop that has been logged into.
 */
function resolveDemoOwnerEmail(): string {
    const output = runConsole(
        `dbal:run-sql "SELECT u.email FROM \\"user\\" u ` +
            `JOIN team_membership tm ON tm.user_id = u.id ` +
            `JOIN team t ON t.id = tm.team_id ` +
            `WHERE t.slug = '${DEMO_TEAM_SLUG}' AND tm.role = 'owner' LIMIT 1"`,
    );

    // dbal:run-sql renders an ASCII table; the address is the only token in it
    // that can contain an @.
    const email = output.match(/[^\s|]+@[^\s|]+/)?.[0];

    if (undefined === email) {
        throw new Error(
            `No owner found for the "${DEMO_TEAM_SLUG}" team. Seed it with ` +
                '`bin/console sendvery:demo:seed`. Raw output was:\n' +
                output,
        );
    }

    return email;
}

/**
 * Every dashboard list is scoped to EVERY team the signed-in user belongs to
 * (DashboardContext::getTeamIdStrings), not just the active one. So a second
 * team on the same account silently adds rows, and the exact row counts this
 * suite asserts would be wrong for a reason that has nothing to do with the
 * code under test. Better to say so here than to fail as a phantom regression.
 */
function assertOwnerBelongsOnlyToTheDemoTeam(email: string): void {
    const output = runConsole(
        `dbal:run-sql "SELECT count(*) AS teams FROM team_membership tm ` +
            `JOIN \\"user\\" u ON u.id = tm.user_id WHERE u.email = '${email}'"`,
    );
    const teams = Number(output.match(/\b\d+\b/)?.[0] ?? NaN);

    if (1 !== teams) {
        throw new Error(
            `"${email}" owns the seeded demo team but belongs to ${teams} teams. The dashboard lists ` +
                'every team this account can see, so the exact row counts this suite asserts would be ' +
                'wrong. Point the suite at a database whose first user is demo-only, or delete the ' +
                'extra memberships.',
        );
    }
}

/**
 * Proves the login bypass is switched on in the app we are about to drive.
 * Without this a misconfigured secret surfaces as every single test timing out
 * on a login page, which is the least diagnosable failure available.
 */
async function assertLoginBypassAnswers(baseURL: string, context: BrowserSuiteContext): Promise<void> {
    const url = new URL('/_test/login', baseURL);
    url.searchParams.set('secret', context.loginSecret);
    url.searchParams.set('email', context.demoOwnerEmail);

    const response = await fetch(url, { redirect: 'manual' });

    if (302 !== response.status) {
        throw new Error(
            `/_test/login answered ${response.status} instead of a redirect. The app must be running ` +
                'with APP_ENV=dev and the SAME SENDVERY_TEST_LOGIN_SECRET this suite resolved. If you ' +
                'just created or changed .env.dev.local, the app has not read it yet:\n\n' +
                '    docker compose restart app',
        );
    }
}

export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0]?.use.baseURL ?? 'http://localhost';

    await assertAppIsReachable(baseURL);
    seedDemoData();

    const context: BrowserSuiteContext = {
        loginSecret: resolveLoginSecret(),
        demoOwnerEmail: resolveDemoOwnerEmail(),
    };

    assertOwnerBelongsOnlyToTheDemoTeam(context.demoOwnerEmail);
    await assertLoginBypassAnswers(baseURL, context);

    mkdirSync(dirname(CONTEXT_FILE), { recursive: true });
    writeFileSync(CONTEXT_FILE, JSON.stringify(context, null, 2));
}
