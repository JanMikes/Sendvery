<?php

declare(strict_types=1);

/**
 * Reads a Clover coverage report and prints every src/ file whose line
 * coverage is below 100%, with the uncovered line numbers. Exit code 1 if any
 * file is below 100%. Used to enforce the project's 100%-coverage rule locally
 * (CI emits the clover; this turns it into a pass/fail gate).
 *
 * Usage: php bin/coverage-audit.php coverage.xml
 */
$path = $argv[1] ?? 'coverage.xml';
if (!is_file($path)) {
    fwrite(STDERR, "Clover report not found: {$path}\n");
    exit(2);
}

$xml = simplexml_load_file($path);
if (false === $xml) {
    fwrite(STDERR, "Could not parse clover: {$path}\n");
    exit(2);
}

$violations = [];
foreach ($xml->xpath('//file') ?: [] as $file) {
    $name = (string) $file['name'];
    if (!str_contains($name, '/src/')) {
        continue;
    }

    $uncovered = [];
    foreach ($file->line as $line) {
        if ('stmt' !== (string) $line['type']) {
            continue;
        }
        if (0 === (int) $line['count']) {
            $uncovered[] = (int) $line['num'];
        }
    }

    if ([] !== $uncovered) {
        $rel = substr($name, (int) strpos($name, '/src/') + 1);
        $violations[$rel] = $uncovered;
    }
}

if ([] === $violations) {
    echo "✓ 100% line coverage across src/\n";
    exit(0);
}

echo "✗ Files below 100% line coverage:\n";
foreach ($violations as $rel => $lines) {
    echo sprintf("  %s — uncovered lines: %s\n", $rel, implode(', ', $lines));
}
exit(1);
