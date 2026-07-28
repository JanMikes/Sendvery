<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Tests\TestSupport\ProjectSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `tests/Unit/Services/Dns/SocketSmtpProbeTest.php` declares
 * `App\Services\Dns\fsockopen()` so it can observe the address the SMTP probe
 * dials. PHP resolves an unqualified call inside a namespace against that
 * namespace first, which is the whole reason the technique works — and also its
 * one hazard: the override is not scoped to the class it was written for. It
 * covers EVERY class in `App\Services\Dns`, for the entire PHPUnit process.
 *
 * So a second caller added to that namespace next year would be silently faked.
 * Not merely "unfaked and hitting the network" — the opposite, and worse: its
 * tests would pass while exercising a stub nobody wrote for it, returning
 * `false` for every address it was never told about. Nothing in the code or the
 * test output would say so.
 *
 * This guard is the tripwire. If it fails, the choices are to route the new
 * caller through the existing probe, or to teach the double about it
 * deliberately — never to delete the assertion.
 */
final class FakedNetworkFunctionGuardTest extends TestCase
{
    /**
     * Functions a test file re-declares inside a `src/` namespace, mapped to the
     * one file allowed to call them.
     */
    private const array OVERRIDDEN_IN_TESTS = [
        'src/Services/Dns' => ['fsockopen' => 'src/Services/Dns/SocketSmtpProbe.php'],
    ];

    #[Test]
    public function onlyTheClassTheDoubleWasWrittenForCallsTheFakedFunction(): void
    {
        $offenders = [];

        foreach (self::OVERRIDDEN_IN_TESTS as $directory => $functions) {
            foreach (ProjectSource::files($directory, 'php') as $path => $contents) {
                foreach ($functions as $function => $allowedPath) {
                    if ($path === $allowedPath || !self::callsFunction($contents, $function)) {
                        continue;
                    }

                    $offenders[] = sprintf('%s calls %s(), which is faked for the whole %s namespace', $path, $function, $directory);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                A class other than the one the test double was written for calls a function
                that the double overrides across its entire namespace.

                The call will be silently intercepted in every test in the suite, and the
                interception looks exactly like a real answer: the double returns false for
                any address it was not told about, so the new caller's tests go green while
                testing the stub. Route the call through the existing adapter, or extend the
                double on purpose and add the new file here.

                Unexpected callers:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function theOverrideThisGuardProtectsActuallyExists(): void
    {
        // Without this, deleting the test double would leave the guard above
        // passing forever while protecting nothing.
        $double = (string) file_get_contents(ProjectSource::projectDir().'/tests/Unit/Services/Dns/SocketSmtpProbeTest.php');

        self::assertStringContainsString('namespace App\Services\Dns;', $double);
        self::assertStringContainsString('function fsockopen(', $double);
    }

    #[Test]
    public function theGuardItselfSpotsAnUnexpectedCaller(): void
    {
        self::assertTrue(self::callsFunction('$socket = @fsockopen($host, 25);', 'fsockopen'));
        self::assertTrue(self::callsFunction("\$socket = fsockopen(\n    \$host,\n    25,\n);", 'fsockopen'));
        self::assertFalse(self::callsFunction('// never call fsockopen here', 'fsockopen'), 'A warning against the call is not the call.');
        self::assertFalse(self::callsFunction('private function myFsockopen(): void {}', 'fsockopen'), 'A longer identifier that merely ends in the name is a different function.');
    }

    private static function callsFunction(string $phpSource, string $function): bool
    {
        $withoutComments = (string) preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $phpSource);

        return 1 === preg_match('/(?<![\w$>])'.preg_quote($function, '/').'\s*\(/', $withoutComments);
    }
}
