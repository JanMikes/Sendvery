import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
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

/**
 * Generous for a loopback request, short enough that an app which accepts the
 * connection and then never answers fails with a sentence instead of sitting
 * until the job's 15-minute ceiling. Playwright puts no time limit on
 * globalSetup, so this file is the only place that limit can exist.
 */
const PROBE_TIMEOUT_MS = 10_000;

/**
 * One request, status code only, body drained and discarded — and deliberately
 * NOT through `fetch`.
 *
 * The `fetch` version of this crashed the entire CI job: not a failed
 * assertion but a process-level AssertionError out of Node's bundled undici
 * (`assert(!this.paused)` in `Parser.finish`), with no frame of our code in the
 * stack and therefore no diagnostic at all. It needs three things at once, and
 * CI supplied every one of them while a laptop supplies none:
 *
 *   1. a response body nobody reads. Taking `.status` and returning leaves
 *      undici's parser paused on backpressure once the body passes the stream's
 *      high-water mark. The 125 KB marketing homepage this probes does; a small
 *      302 like /_test/login's does not, which is why only the first probe
 *      actually crashed and the second was one template change away from it.
 *   2. a server that frames the body by closing the connection. `php -S`, which
 *      the CI job runs, sends `Connection: close` and NO `Content-Length`, so
 *      the message is complete only at EOF — and undici finishes an EOF-framed
 *      message from the socket's `end` handler, which is where it asserts.
 *      FrankenPHP locally sends `Transfer-Encoding: chunked` over a keep-alive
 *      socket, so the message completes and the socket never ends.
 *   3. Node 22. Reproduced against `php -S` on 22.23.1, the version CI resolves
 *      from `node-version: '22'`; survives on 24 and on the 25 a laptop has.
 *
 * `await res.text()` also fixes it, and is not enough: it leaves the next probe
 * one forgotten `await` away from an uncatchable crash on a path nobody tests,
 * and the crash tells you nothing. `node:http` cannot fail this way — an unread
 * IncomingMessage is an ordinary paused stream, `resume()` is the drain, and no
 * assertion sits behind it. It never follows redirects either, which is what
 * assertLoginBypassAnswers is asserting on.
 */
function probe(target: string | URL): Promise<number> {
    const url = new URL(target);
    // agent: false — one socket, closed as soon as the response ends, rather
    // than parked in the global keep-alive pool with nobody left to use it.
    const request = ('https:' === url.protocol ? httpsRequest : httpRequest)(url, { method: 'GET', agent: false });

    return new Promise<number>((settle, fail) => {
        request.setTimeout(PROBE_TIMEOUT_MS, () => {
            request.destroy(
                new Error(`${url.href} accepted the connection but sent no response within ${PROBE_TIMEOUT_MS}ms.`),
            );
        });

        request.on('error', fail);

        request.on('response', (response) => {
            const status = response.statusCode ?? 0;

            // Drain first, resolve second: settling on `end` means that by the
            // time a caller acts on the status there is nothing left in flight.
            response.resume();
            response.on('error', fail);
            response.on('end', () => settle(status));
        });

        request.end();
    });
}

async function assertAppIsReachable(baseURL: string): Promise<void> {
    let status: number;

    try {
        status = await probe(baseURL);
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

    // probe() does not follow redirects, so a 302 here is the app's own answer
    // and not the dashboard's.
    const status = await probe(url);

    if (302 !== status) {
        throw new Error(
            `/_test/login answered ${status} instead of a redirect. The app must be running ` +
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
