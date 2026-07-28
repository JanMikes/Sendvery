<?php

declare(strict_types=1);

/**
 * Coverage ratchet — turns a Clover report into a pass/fail gate.
 *
 * The project standard is 100% line coverage, but a few hundred src/ files
 * predate any enforcement, so a hard --coverage-min=100 would fail on the first
 * commit and be switched off the same afternoon. Instead CI compares
 * coverage.xml against coverage-baseline.json, which records how many uncovered
 * statements each file is currently allowed. A file may get better; it may
 * never get worse; and a new file may not join the list without an explicit,
 * reviewable edit to the baseline.
 *
 *   php bin/coverage-audit.php coverage.xml            report every file below 100% (exit 1 if any)
 *   php bin/coverage-audit.php coverage.xml --ratchet  the CI gate
 *   php bin/coverage-audit.php coverage.xml --update    rewrite the baseline (never run by CI)
 *   --baseline=path                                     override coverage-baseline.json
 *
 * Why per-file counts and not line numbers: line numbers are exact but shift on
 * every insertion above them, so an unrelated edit would report a wall of "new
 * uncovered lines" and teach everyone to re-run --update without reading it.
 * Counts survive reformatting and moves within a file. The known hole is that
 * swapping one uncovered line for another inside an already-listed file keeps
 * the count equal — the new code is still visible in the diff, and nothing gets
 * worse in aggregate.
 *
 * Why per-file counts and not one total: a single number lets one file improve
 * while another rots. Every file is compared on its own.
 *
 * Exit codes: 0 pass · 1 gate failure · 2 the check could not run at all
 * (missing or garbled clover, unreadable baseline, a report that does not cover
 * the whole tree, a report where nothing executed). A guard that cannot run
 * must never be mistaken for a guard that passed.
 *
 * The numbers are recorded from CI's own clover, downloadable as the
 * `coverage-clover` artifact of any run of the tests job. That is not
 * ceremony: CodeCoverage::applyExecutableLinesFilter() intersects the coverage
 * driver's line map with its own static analysis, so pcov and Xdebug disagree
 * over attribute arguments in the API Platform resource classes — 26 statements
 * across two files, measured. CI pins neither the PHP patch version nor the
 * driver version, and a developer's container pins both differently, so the
 * report CI produced is the only one guaranteed to agree with the report CI
 * will produce.
 */
const BASELINE_DOC = [
    'Coverage debt that predates the ratchet: uncovered statements allowed per file.',
    'CI gate: php bin/coverage-audit.php coverage.xml --ratchet',
    'A listed file may never exceed its number. A file that is not listed must be 100% covered.',
    'Adding an entry or raising a number is a deliberate exception - justify it in the pull request.',
    'When coverage improves the gate fails as stale: run --update and commit the smaller numbers.',
    'Regenerate from CI: download the tests job coverage-clover artifact, then --update against it.',
];

const REGENERATE_HINT = <<<'HINT'
      # download the coverage-clover artifact from the tests job of this run, then:
      docker compose exec app php bin/coverage-audit.php coverage.xml --update
    HINT;

const MAX_LINES_SHOWN = 12;

/**
 * The check itself is broken or its inputs are not what it thinks they are.
 * Distinct from a gate failure so nobody reads "could not run" as "clean".
 */
function cannotRun(string $message): never
{
    fwrite(STDERR, "✗ coverage-audit cannot run: {$message}\n");

    exit(2);
}

/**
 * @return list<string> every src/*.php on disk, relative to the project root
 */
function sourceFilesOnDisk(string $projectRoot): array
{
    $directory = $projectRoot.'/src';
    if (!is_dir($directory)) {
        cannotRun("there is no src/ directory at {$directory}");
    }

    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && 'php' === $file->getExtension()) {
            $found[] = 'src/'.str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        }
    }

    sort($found);

    return $found;
}

/**
 * Clover records absolute paths, which differ between the container (/app/...)
 * and a CI runner. Strip the project root when it matches, otherwise fall back
 * to the last /src/ in the path.
 */
function relativeSourcePath(string $absolute, string $projectRoot): ?string
{
    $normalised = str_replace('\\', '/', $absolute);
    $rootPrefix = str_replace('\\', '/', $projectRoot).'/';

    if (str_starts_with($normalised, $rootPrefix)) {
        $relative = substr($normalised, strlen($rootPrefix));
    } else {
        $marker = strrpos($normalised, '/src/');
        if (false === $marker) {
            return null;
        }
        $relative = substr($normalised, $marker + 1);
    }

    return str_starts_with($relative, 'src/') ? $relative : null;
}

/**
 * @param bool $requireWholeTree gate modes only — the plain report is a useful
 *                               thing to run over a subset of the suite, and
 *                               refusing to print one would just retire the
 *                               everyday "did I cover my file" workflow
 *
 * @return array{uncoveredLines: array<string, list<int>>, statements: int, uncovered: int}
 */
function readClover(string $path, string $projectRoot, bool $requireWholeTree): array
{
    if (!is_file($path)) {
        cannotRun("clover report not found at {$path} — produce one with vendor/bin/phpunit --coverage-clover={$path}");
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    libxml_use_internal_errors($previous);

    if (false === $xml) {
        cannotRun("clover report at {$path} is not parseable XML");
    }

    $fileNodes = $xml->xpath('//file') ?: [];
    if ([] === $fileNodes) {
        cannotRun("clover report at {$path} contains no <file> elements — it measured nothing");
    }

    $uncoveredLines = [];
    $sourceOf = [];
    $statements = 0;
    $uncovered = 0;

    foreach ($fileNodes as $node) {
        $absolute = (string) $node['name'];
        $relative = relativeSourcePath($absolute, $projectRoot);
        if (null === $relative) {
            continue;
        }

        if (isset($sourceOf[$relative])) {
            cannotRun(sprintf('two clover entries collapse to %s (%s and %s)', $relative, $sourceOf[$relative], $absolute));
        }
        $sourceOf[$relative] = $absolute;

        $lines = [];
        foreach ($node->line as $line) {
            if ('stmt' !== (string) $line['type']) {
                continue;
            }

            ++$statements;
            if (0 === (int) $line['count']) {
                $lines[] = (int) $line['num'];
                ++$uncovered;
            }
        }

        $uncoveredLines[$relative] = $lines;
    }

    // Three assertions about our own scan. Without them this tool reports a
    // clean sheet whenever it is pointed at the wrong file or reads the right
    // file the wrong way, which is the failure mode of every guard that has
    // ever quietly stopped working.
    if ([] === $uncoveredLines) {
        cannotRun("clover report at {$path} contains no src/ files — wrong report, or the source filter changed");
    }

    if (0 === $statements) {
        cannotRun("clover report at {$path} contains no executable statements for src/");
    }

    if ($statements === $uncovered) {
        cannotRun("clover report at {$path} says not one statement executed — the coverage driver was probably disabled");
    }

    $missing = $requireWholeTree
        ? array_values(array_diff(sourceFilesOnDisk($projectRoot), array_keys($uncoveredLines)))
        : [];

    if ([] !== $missing) {
        cannotRun(sprintf(
            '%d src/ file(s) are absent from %s, so it does not measure the whole tree (%s%s) — regenerate it from a full run',
            count($missing),
            $path,
            implode(', ', array_slice($missing, 0, 5)),
            count($missing) > 5 ? ', …' : '',
        ));
    }

    return ['uncoveredLines' => $uncoveredLines, 'statements' => $statements, 'uncovered' => $uncovered];
}

/**
 * @return array{files: array<string, int>, statements: ?int}
 */
function readBaseline(string $path, bool $required): array
{
    if (!is_file($path)) {
        if ($required) {
            cannotRun("coverage baseline not found at {$path} — an absent baseline is not an empty one; create it with --update");
        }

        return ['files' => [], 'statements' => null];
    }

    $raw = file_get_contents($path);
    if (false === $raw) {
        cannotRun("coverage baseline at {$path} is unreadable");
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        cannotRun("coverage baseline at {$path} is not valid JSON: ".$exception->getMessage());
    }

    if (!is_array($decoded) || !array_key_exists('files', $decoded) || !is_array($decoded['files'])) {
        cannotRun("coverage baseline at {$path} has no \"files\" object");
    }

    $files = [];
    foreach ($decoded['files'] as $relative => $allowed) {
        if (!is_string($relative) || !str_starts_with($relative, 'src/')) {
            cannotRun(sprintf('coverage baseline at %s has an entry that is not a src/ path: %s', $path, var_export($relative, true)));
        }

        if (!is_int($allowed) || $allowed < 1) {
            cannotRun(sprintf('coverage baseline entry "%s" must be a positive integer, got %s', $relative, var_export($allowed, true)));
        }

        $files[$relative] = $allowed;
    }

    $statements = $decoded['statements'] ?? null;
    if (null !== $statements && (!is_int($statements) || $statements < 1)) {
        cannotRun(sprintf('coverage baseline at %s has a non-positive "statements" total: %s', $path, var_export($statements, true)));
    }

    return ['files' => $files, 'statements' => $statements];
}

/**
 * The statement total is recorded for diagnosis only — never gated on. A large
 * swing in it next to a crowd of per-file deltas is the signature of a report
 * taken in a different environment from the baseline — the other coverage
 * driver, say — rather than of real code changes.
 *
 * @param array<string, int> $files
 */
function writeBaseline(string $path, array $files, int $statements): void
{
    ksort($files);

    $json = json_encode(
        ['__doc__' => BASELINE_DOC, 'statements' => $statements, 'files' => (object) $files],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    if (false === file_put_contents($path, $json."\n")) {
        cannotRun("could not write the coverage baseline to {$path}");
    }
}

/**
 * @param list<int> $lines
 */
function formatLines(array $lines): string
{
    $shown = array_slice($lines, 0, MAX_LINES_SHOWN);
    $suffix = count($lines) > MAX_LINES_SHOWN ? sprintf(', … (+%d more)', count($lines) - MAX_LINES_SHOWN) : '';

    return implode(', ', $shown).$suffix;
}

$projectRoot = dirname(__DIR__);
$cloverPath = null;
$baselinePath = $projectRoot.'/coverage-baseline.json';
$mode = 'report';

/** @var list<string> $arguments */
$arguments = array_slice($_SERVER['argv'] ?? [], 1);

foreach ($arguments as $argument) {
    if ('--ratchet' === $argument || '--update' === $argument) {
        $requested = substr($argument, 2);
        if ('report' !== $mode && $requested !== $mode) {
            cannotRun('--ratchet and --update do opposite things; pass one');
        }
        $mode = $requested;
    } elseif (str_starts_with($argument, '--baseline=')) {
        $baselinePath = substr($argument, strlen('--baseline='));
    } elseif (str_starts_with($argument, '--')) {
        cannotRun("unknown option {$argument} — expected --ratchet, --update or --baseline=path");
    } elseif (null === $cloverPath) {
        $cloverPath = $argument;
    } else {
        cannotRun('expected exactly one clover report path');
    }
}

$clover = readClover($cloverPath ?? 'coverage.xml', $projectRoot, requireWholeTree: 'report' !== $mode);

/** @var array<string, int> $actual */
$actual = [];
foreach ($clover['uncoveredLines'] as $relative => $lines) {
    if ([] !== $lines) {
        $actual[$relative] = count($lines);
    }
}

$percentage = 100 * ($clover['statements'] - $clover['uncovered']) / $clover['statements'];
$summary = sprintf(
    '%d statements in src/, %d uncovered in %d file(s) — %.2f%% line coverage',
    $clover['statements'],
    $clover['uncovered'],
    count($actual),
    $percentage,
);

if ('update' === $mode) {
    $previous = readBaseline($baselinePath, required: false)['files'];
    writeBaseline($baselinePath, $actual, $clover['statements']);

    $added = array_diff_key($actual, $previous);
    $removed = array_diff_key($previous, $actual);
    $changed = 0;
    foreach ($actual as $relative => $count) {
        if (isset($previous[$relative]) && $previous[$relative] !== $count) {
            ++$changed;
        }
    }

    echo "{$summary}\n";
    echo sprintf(
        "Wrote %s: %d file(s), %d uncovered line(s) allowed (%d added, %d removed, %d changed; allowance %d → %d).\n",
        $baselinePath,
        count($actual),
        array_sum($actual),
        count($added),
        count($removed),
        $changed,
        array_sum($previous),
        array_sum($actual),
    );
    echo "Review the diff before committing — every number in it is debt someone has to pay off.\n";

    exit(0);
}

if ('ratchet' === $mode) {
    $recorded = readBaseline($baselinePath, required: true);
    $baseline = $recorded['files'];
    $onDisk = array_flip(sourceFilesOnDisk($projectRoot));

    /** @var array<string, array{int, int}> $regressed */
    $regressed = [];
    /** @var array<string, int> $unbaselined */
    $unbaselined = [];
    /** @var array<string, array{int, int}> $stale */
    $stale = [];

    foreach ($actual as $relative => $count) {
        if (!isset($baseline[$relative])) {
            $unbaselined[$relative] = $count;
        } elseif ($count > $baseline[$relative]) {
            $regressed[$relative] = [$count, $baseline[$relative]];
        }
    }

    foreach ($baseline as $relative => $allowed) {
        $count = $actual[$relative] ?? 0;
        if ($count < $allowed) {
            $stale[$relative] = [$count, $allowed];
        }
    }

    echo "{$summary}\n";
    echo sprintf(
        "Baseline %s: %d file(s), %d uncovered line(s) allowed, recorded against %s statements.\n",
        basename($baselinePath),
        count($baseline),
        array_sum($baseline),
        null === $recorded['statements'] ? 'an unrecorded number of' : (string) $recorded['statements'],
    );

    if ([] === $regressed && [] === $unbaselined && [] === $stale) {
        echo "✓ Coverage ratchet holds: nothing got worse and the baseline is exact.\n";

        exit(0);
    }

    if ([] !== $regressed) {
        echo sprintf("\n✗ Coverage regressed in %d file(s) — new uncovered lines are not allowed:\n", count($regressed));
        foreach ($regressed as $relative => [$count, $allowed]) {
            echo sprintf("  %s — %d uncovered, baseline allows %d (+%d)\n", $relative, $count, $allowed, $count - $allowed);
            echo sprintf("      uncovered lines: %s\n", formatLines($clover['uncoveredLines'][$relative]));
        }
        echo "  Cover the new lines. Raising the number in the baseline needs a reason in the pull request.\n";
    }

    if ([] !== $unbaselined) {
        echo sprintf("\n✗ %d file(s) are not in the baseline and are not fully covered:\n", count($unbaselined));
        foreach ($unbaselined as $relative => $count) {
            echo sprintf("  %s — %d uncovered line(s): %s\n", $relative, $count, formatLines($clover['uncoveredLines'][$relative]));
        }
        echo "  New code is 100% covered. If a file genuinely cannot be, add it to the baseline in the\n";
        echo "  same commit and say why — that line in the JSON diff is the whole point.\n";
    }

    if ([] !== $stale) {
        echo sprintf("\n✗ The baseline is stale for %d file(s) — coverage improved and was not recorded:\n", count($stale));
        foreach ($stale as $relative => [$count, $allowed]) {
            $reason = match (true) {
                !isset($onDisk[$relative]) => 'the file no longer exists',
                0 === $count => 'it is now fully covered',
                default => sprintf('only %d are uncovered', $count),
            };
            echo sprintf("  %s — baseline allows %d, %s\n", $relative, $allowed, $reason);
        }
        echo "  This is the burn-down, and it is not optional: record the smaller numbers so the\n";
        echo "  allowance can never drift above reality.\n";
    }

    // Ten-plus files failing at once is not what one change looks like. Paired
    // with a moved statement total it usually means this report and the
    // baseline came from different measurement environments — a different
    // coverage driver, or a tree that moved under the run.
    $looksLikeEnvironmentMismatch = count($regressed) + count($unbaselined) + count($stale) >= 10
        && null !== $recorded['statements']
        && $recorded['statements'] !== $clover['statements'];

    if ($looksLikeEnvironmentMismatch) {
        echo sprintf(
            "\n⚠ This report measured %d statements; the baseline was recorded against %d.\n",
            $clover['statements'],
            $recorded['statements'],
        );
        echo "  A swing there alongside this many findings usually means this report was not taken\n";
        echo "  the way the baseline was — a different coverage driver, or src/ changing mid-run.\n";
        echo "  Check that before believing the list above.\n";
    }

    // Only offered when re-recording is genuinely the right move. A plain
    // regression is fixed by writing the test, and pointing at --update there
    // would be teaching the bypass.
    if ([] !== $stale || $looksLikeEnvironmentMismatch) {
        echo "\nRe-record from CI's own report, which is the reference measurement:\n";
        echo REGENERATE_HINT."\n";
        echo "Then review the JSON diff: every number in it is debt someone has to pay off.\n";
    }

    exit(1);
}

if ([] === $actual) {
    echo "✓ 100% line coverage across src/\n";

    exit(0);
}

echo "✗ Files below 100% line coverage:\n";
foreach (array_keys($actual) as $relative) {
    echo sprintf("  %s — uncovered lines: %s\n", $relative, implode(', ', $clover['uncoveredLines'][$relative]));
}
echo "{$summary}\n";

exit(1);
