import { execSync } from 'node:child_process';
import { PROJECT_ROOT } from './context';

/**
 * How to reach `bin/console`. Locally that means going through Docker (every
 * PHP command in this project does); in CI PHP runs on the runner itself, so the
 * workflow sets this to an empty string.
 */
const CONSOLE_PREFIX = process.env.SENDVERY_CONSOLE_PREFIX ?? 'docker compose exec -T app';

export function runConsole(argv: string): string {
    return execSync(`${CONSOLE_PREFIX} bin/console ${argv}`.trim(), {
        cwd: PROJECT_ROOT,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'inherit'],
    });
}

/**
 * Idempotent: wipes and rebuilds only the demo team, never anything else.
 * Re-seeding is what lets the specs assert exact row counts instead of "> 0",
 * which is the assertion that keeps passing after a list silently empties.
 */
export function seedDemoData(): void {
    runConsole('sendvery:demo:seed');
}
