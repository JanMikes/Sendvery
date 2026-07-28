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

const SECRET_NAME = 'SENDVERY_TEST_LOGIN_SECRET';

/**
 * The order Symfony Dotenv loads these for APP_ENV=dev. LAST ONE WINS, and that
 * is the whole reason the secret belongs in `.env.dev.local`.
 */
const ENV_FILES_IN_LOAD_ORDER = ['.env', '.env.local', '.env.dev', '.env.dev.local'] as const;

/**
 * `undefined` when the file does not exist or never mentions the name, `''` when
 * it sets it to nothing. Those are different facts and the diagnostic needs both:
 * a committed empty value is not a missing value, it is an override.
 */
function readConfiguredValue(file: string, name: string): string | undefined {
    const path = resolve(PROJECT_ROOT, file);

    if (!existsSync(path)) {
        return undefined;
    }

    return readFileSync(path, 'utf8').match(new RegExp(`^${name}=(.*)$`, 'm'))?.[1]?.trim();
}

/**
 * The .env files in load order and what each does to one variable, so a reader
 * can see which file the app actually obeys instead of guessing.
 *
 * Lengths and presence, NEVER values, and deliberately no hash either: DATABASE_URL
 * carries a password and a truncated digest of a short human-chosen secret is a
 * dictionary attack. There is also nothing to compare a hash against — see
 * assertLoginBypassAnswers for why the app cannot be asked what it resolved.
 */
function describeEnvFileState(name: string, emptyMeans: string): string {
    const configured = ENV_FILES_IN_LOAD_ORDER.map((file) => ({ file, value: readConfiguredValue(file, name) }));

    // File-over-file precedence is unconditional — a value already loaded from an
    // earlier .env IS overridden by a later one. Only file-versus-real-variable is
    // the conditional part, which the callers explain. So naming one last writer is
    // a fact; annotating two of them would contradict the sentence above the table.
    const setters = configured.filter(({ value }) => undefined !== value);
    const lastWriter = setters[setters.length - 1];
    const lines: string[] = [];

    for (const { file, value } of configured) {
        let state: string;

        if (undefined === value) {
            state = existsSync(resolve(PROJECT_ROOT, file)) ? 'exists, does not set it' : 'absent';
        } else if ('' === value) {
            state = 'sets it EMPTY';
        } else {
            state = `sets it (${value.length} characters)`;
        }

        if (undefined !== lastWriter && file === lastWriter.file) {
            state +=
                '' === lastWriter.value
                    ? `   <-- last writer: ${emptyMeans}`
                    : '   <-- last writer, so this is the value the app gets';
        }

        lines.push(`    ${file.padEnd(16)} ${state}`);
    }

    return lines.join('\n');
}

function describeSecretWiring(suiteSource: string, suiteSecret: string): string {
    return [
        `The suite resolved its secret from ${suiteSource} (${suiteSecret.length} characters).`,
        '',
        'What the APP resolves is a separate question, and these are the files that decide it,',
        'in the order Symfony Dotenv loads them — the last one to set the name wins:',
        '',
        describeEnvFileState(SECRET_NAME, 'the endpoint is inert, every request 404s'),
    ].join('\n');
}

/**
 * The app answering on `/` proves far less than it looks. The marketing homepage
 * touches no database, so a web process pointed at an unreachable one still serves
 * it 200 — measured in CI, where `/` was 200 while every dashboard URL was a 500.
 * The first request that noticed was /_test/login, so a DATABASE_URL problem
 * reported itself as a sign-in problem and cost a whole run plus a dig through the
 * server-log step.
 *
 * Unauthenticated /app is the cheapest URL that boots the app AND touches the
 * database: 302 to the login page when healthy, 500 when it cannot. There is no
 * database-backed health endpoint to use instead — /status renders a JSON file and
 * /-/health-check/liveness is a static payload, correctly, since liveness is not
 * readiness.
 *
 * Called AFTER seedDemoData(), and that ordering IS the diagnostic rather than an
 * accident: by this point `bin/console` has connected to the database, seeded it
 * and queried the owner back. So a 500 here cannot be a database that is down — it
 * can only be the web process resolving a different DATABASE_URL than the console
 * did, which is the asymmetry that hid this defect for three CI runs.
 */
async function assertAppCanReachItsDatabase(baseURL: string): Promise<void> {
    const url = new URL('/app', baseURL);
    const status = await probe(url);

    if (status < 500) {
        return;
    }

    throw new Error(
        [
            `${url.pathname} answered ${status}, so the app is running but a request that touches the`,
            'database failed inside it.',
            '',
            'This is NOT a database that is down. bin/console reached it seconds ago — it seeded the demo',
            'team and read the owner back — so the server, the credentials and the schema are all fine.',
            'What differs is WHO IS ASKING. The CLI SAPI puts environment variables in $_SERVER, where',
            'Symfony Dotenv leaves them alone; `php -S` rebuilds $_SERVER per request from request data',
            'and, under php.ini-production\'s variables_order="GPCS", $_ENV is never filled either. So a',
            'committed .env value wins in the web process and only in the web process.',
            '',
            'First suspect is therefore DATABASE_URL: committed .env points it at the docker-compose',
            'service hostname `database`, which resolves nowhere outside compose.',
            '',
            'Env files on disk, in the order Dotenv loads them — the last one to set it wins:',
            '',
            describeEnvFileState('DATABASE_URL', 'the app has no database configured at all'),
            '',
            'The fix is the one the sign-in secret already uses, for the same reason: .env.dev.local is',
            'loaded LAST, so it wins regardless of SAPI or variables_order.',
            '',
            'In CI that file is written by the "Wire this job\'s environment into the app" step in',
            '.github/workflows/ci.yml — add the variable there. The app\'s own exception names the real',
            'cause, and the "Show server log on failure" step prints it.',
        ].join('\n'),
    );
}

/**
 * Never a literal copy of the secret: it is configured outside this file, so
 * changing it there must not silently turn every test into a 404.
 *
 * `.env.dev.local` and not `.env.dev`: the secret signs in as any existing user
 * in any team, and `.env.dev` is committed to a public repository. Only
 * `.env.*.local` is ignored by both .gitignore and .dockerignore.
 */
function resolveLoginSecret(): { secret: string; source: string } {
    const fromEnvironment = process.env.SENDVERY_TEST_LOGIN_SECRET;

    if (undefined !== fromEnvironment && '' !== fromEnvironment) {
        return { secret: fromEnvironment, source: `the ${SECRET_NAME} environment variable` };
    }

    // Same precedence Symfony Dotenv uses: the .local file wins.
    for (const file of ['.env.dev.local', '.env.dev']) {
        const configured = readConfiguredValue(file, SECRET_NAME);

        if (undefined !== configured && '' !== configured) {
            return { secret: configured, source: file };
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
 *
 * This HTTP round-trip is the ONLY authoritative answer, and asking `bin/console`
 * instead would actively mislead. Measured, in the exact state that broke CI —
 * secret in a real environment variable, nothing in `.env.dev.local` — the two
 * SAPIs disagree: plain CLI finds the name in `$_SERVER`, so Dotenv leaves it
 * alone and `debug:dotenv` proudly prints the correct value, while `php -S`
 * rebuilds `$_SERVER` per request from request data, never sees it, and lets
 * `.env`'s empty value through. Symfony's own `debug:dotenv` footer says as much:
 * "Note that values might be different between web and CLI." So the failure below
 * reports what the suite sent and what is on disk — both certain — and never
 * pretends to know what the web process resolved.
 */
async function assertLoginBypassAnswers(
    baseURL: string,
    context: BrowserSuiteContext,
    // Not folded into BrowserSuiteContext: the fixtures do not need it, and that
    // type is serialised to var/playwright/context.json for every worker to read.
    secretSource: string,
): Promise<void> {
    const url = new URL('/_test/login', baseURL);
    url.searchParams.set('secret', context.loginSecret);
    url.searchParams.set('email', context.demoOwnerEmail);

    // probe() does not follow redirects, so a 302 here is the app's own answer
    // and not the dashboard's.
    const status = await probe(url);

    if (302 !== status) {
        throw new Error(
            [
                `/_test/login answered ${status} instead of the 302 redirect a successful sign-in returns.`,
                `It signed in as ${context.demoOwnerEmail}, which the database confirmed owns the demo team,`,
                'so the account exists and the remaining suspect is the secret.',
                '',
                describeSecretWiring(secretSource, context.loginSecret),
                '',
                'A REAL ENVIRONMENT VARIABLE IS NOT ENOUGH. The app resolves the secret through Symfony',
                'Dotenv, which only leaves an existing variable alone when PHP has put the name in $_ENV or',
                '$_SERVER — and whether it did depends on `variables_order` and on the SAPI. Under `php -S`',
                'with php.ini-production\'s default "GPCS" it does not, so a committed empty value wins and',
                'TestLoginController refuses at its empty-secret gate. Every refusal there is a 404 on',
                'purpose, so the status cannot tell you which gate closed; the app\'s own log names the line.',
                '',
                'The fix is the same everywhere, because .env.dev.local is loaded LAST:',
                '',
                `    echo '${SECRET_NAME}=pick-something-random' > .env.dev.local`,
                '    docker compose restart app        # the app reads env files at boot',
                '',
                'In CI the `browser` job writes that file from its job-level environment variable — see',
                '.github/workflows/ci.yml. If you just changed the file locally, the restart is the fix:',
                'a running FrankenPHP worker is still holding the value it booted with.',
            ].join('\n'),
        );
    }
}

export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0]?.use.baseURL ?? 'http://localhost';

    await assertAppIsReachable(baseURL);
    seedDemoData();
    await assertAppCanReachItsDatabase(baseURL);

    const { secret, source } = resolveLoginSecret();
    const context: BrowserSuiteContext = {
        loginSecret: secret,
        demoOwnerEmail: resolveDemoOwnerEmail(),
    };

    assertOwnerBelongsOnlyToTheDemoTeam(context.demoOwnerEmail);
    await assertLoginBypassAnswers(baseURL, context, source);

    mkdirSync(dirname(CONTEXT_FILE), { recursive: true });
    writeFileSync(CONTEXT_FILE, JSON.stringify(context, null, 2));
}
