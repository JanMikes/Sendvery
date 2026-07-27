<?php

declare(strict_types=1);

namespace App\Tests\TestSupport;

/**
 * Read-only, kernel-free view over the project's own template and asset source,
 * used by the architecture guard tests.
 *
 * WHY SCAN SOURCE INSTEAD OF CRAWLING RENDERED PAGES: a crawl only ever covers
 * the handful of URLs a test happens to visit, and it needs fixture data shaped
 * so the offending markup renders at all. A source scan covers every template in
 * the tree — including one added tomorrow, because the file set is discovered
 * from disk rather than listed — for the price of a few file reads. Guards that
 * genuinely need a browser-shaped DOM (e.g. "are these hrefs actually distinct
 * once the query has run?") still crawl; everything structural is scanned here.
 *
 * NOTE ON CLAUDE.md's "never assert CSS/Tailwind classes" RULE — do not delete
 * the guards built on this helper as rule violations. That rule forbids
 * asserting utility classes in order to pin down *styling* (spacing, font size,
 * responsive breakpoints, layout), because those churn on every UI pass and
 * carry no business meaning. The guards here assert the *absence of a
 * structurally broken markup pattern*: markup that makes a control unclickable,
 * a row navigate to the wrong record, or a theme render black-and-white. That is
 * a behavioural assertion that happens to be spelled in class names, and the
 * repo already does it (see AccessibleRowNavigationTest::noOnclickInAnyDashboardPage
 * and DomainsWithDnsHealthTest::noTemplateReferencesDashboardDnsHealthRoute).
 */
final class ProjectSource
{
    public static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Every file with $extension under $relativeDir, keyed by project-relative
     * path. Discovered from disk on every run so a newly added template is
     * covered without anyone remembering to register it.
     *
     * @param list<string> $excludedPrefixes project-relative path prefixes to skip
     *
     * @return array<string, string> project-relative path => file contents
     */
    public static function files(string $relativeDir, string $extension, array $excludedPrefixes = []): array
    {
        $root = self::projectDir().'/'.$relativeDir;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);

            if (!$file->isFile() || $extension !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen(self::projectDir()) + 1);

            foreach ($excludedPrefixes as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    /**
     * Blanks out `{# … #}` comments, keeping every byte offset and line number
     * intact. Essential for these guards: several templates carry long comments
     * that quote the very anti-patterns being banned ("never reinstate an
     * `absolute inset-0` overlay…"), and a naive scan would flag the warning
     * against the bug as the bug.
     */
    public static function stripTwigComments(string $twig): string
    {
        return self::blankMatches('/\{#.*?#\}/s', $twig);
    }

    /**
     * Blanks out `{{ … }}` and `{% … %}`, leaving only the literal markup and
     * copy. Used by the wording guards so that retained *identifiers* — route
     * names, filter query values, form POST values — are invisible to a scan
     * that is only interested in what the user reads.
     */
    public static function stripTwigTags(string $twig): string
    {
        return self::blankMatches('/\{\{.*?\}\}|\{%.*?%\}/s', $twig);
    }

    public static function lineOfOffset(string $contents, int $offset): int
    {
        return substr_count($contents, "\n", 0, $offset) + 1;
    }

    /**
     * Opening tags for one element name, with their byte offsets.
     *
     * The attribute matcher is quote-aware rather than `[^>]*` because these
     * templates routinely embed `>=` inside an attribute value
     * (`class="{{ rate >= 90 ? … }}"`), which silently truncates the naive
     * pattern and makes a scan miss whole tags.
     *
     * @return list<array{offset: int, source: string}>
     */
    public static function openingTags(string $contents, string $tag): array
    {
        $pattern = '#<'.preg_quote($tag, '#').'(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#si';
        preg_match_all($pattern, $contents, $matches, \PREG_OFFSET_CAPTURE);

        $tags = [];
        foreach ($matches[0] as $match) {
            $tags[] = ['offset' => (int) $match[1], 'source' => (string) $match[0]];
        }

        return $tags;
    }

    /**
     * Byte ranges covered by one element name, nesting-aware. An unclosed
     * element runs to end of file — deliberately conservative, so a malformed
     * template errs towards being flagged rather than silently skipped.
     *
     * @return list<array{start: int, end: int}>
     */
    public static function regions(string $contents, string $tag): array
    {
        $events = [];
        foreach (self::openingTags($contents, $tag) as $open) {
            if (str_ends_with(rtrim(substr($open['source'], 0, -1)), '/')) {
                continue;
            }

            $events[] = ['offset' => $open['offset'], 'isOpen' => true, 'length' => \strlen($open['source'])];
        }

        preg_match_all('#</'.preg_quote($tag, '#').'\s*>#si', $contents, $closes, \PREG_OFFSET_CAPTURE);
        foreach ($closes[0] as $close) {
            $events[] = ['offset' => (int) $close[1], 'isOpen' => false, 'length' => \strlen((string) $close[0])];
        }

        usort($events, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $open = [];
        $regions = [];
        foreach ($events as $event) {
            if ($event['isOpen']) {
                $open[] = $event['offset'];

                continue;
            }

            $start = array_pop($open);
            if (null === $start) {
                continue;
            }

            $regions[] = ['start' => $start, 'end' => $event['offset'] + $event['length']];
        }

        foreach ($open as $start) {
            $regions[] = ['start' => $start, 'end' => \strlen($contents)];
        }

        return $regions;
    }

    /**
     * Elements of one name with their opening tag and inner content, so a guard
     * can reason about "this `<tr>` says it is clickable — does its body hold a
     * real anchor?".
     *
     * @return list<array{start: int, openTag: string, inner: string}>
     */
    public static function elements(string $contents, string $tag): array
    {
        $byStart = [];
        foreach (self::openingTags($contents, $tag) as $open) {
            $byStart[$open['offset']] = $open['source'];
        }

        $elements = [];
        foreach (self::regions($contents, $tag) as $region) {
            $openTag = $byStart[$region['start']] ?? null;
            if (null === $openTag) {
                continue;
            }

            $innerStart = $region['start'] + \strlen($openTag);
            $elements[] = [
                'start' => $region['start'],
                'openTag' => $openTag,
                'inner' => substr($contents, $innerStart, max(0, $region['end'] - $innerStart)),
            ];
        }

        return $elements;
    }

    /**
     * `{% for x in … %}` … `{% endfor %}` ranges with the loop variable name,
     * nesting-aware.
     *
     * @return list<array{start: int, end: int, variable: string, body: string}>
     */
    public static function twigForLoops(string $contents): array
    {
        $events = [];
        preg_match_all(
            '/\{%-?\s*for\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s*,\s*([A-Za-z_][A-Za-z0-9_]*))?\s+in\s/',
            $contents,
            $opens,
            \PREG_OFFSET_CAPTURE,
        );
        foreach ($opens[0] as $index => $match) {
            $events[] = [
                'offset' => (int) $match[1],
                'isOpen' => true,
                'length' => \strlen((string) $match[0]),
                'variable' => '' !== (string) ($opens[2][$index][0] ?? '') ? (string) $opens[2][$index][0] : (string) $opens[1][$index][0],
            ];
        }

        preg_match_all('/\{%-?\s*endfor\s*-?%\}/', $contents, $closes, \PREG_OFFSET_CAPTURE);
        foreach ($closes[0] as $match) {
            $events[] = [
                'offset' => (int) $match[1],
                'isOpen' => false,
                'length' => \strlen((string) $match[0]),
                'variable' => '',
            ];
        }

        usort($events, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        $open = [];
        $loops = [];
        foreach ($events as $event) {
            if ($event['isOpen']) {
                $open[] = ['offset' => $event['offset'] + $event['length'], 'variable' => $event['variable']];

                continue;
            }

            $start = array_pop($open);
            if (null === $start) {
                continue;
            }

            $loops[] = [
                'start' => $start['offset'],
                'end' => $event['offset'],
                'variable' => $start['variable'],
                'body' => substr($contents, $start['offset'], max(0, $event['offset'] - $start['offset'])),
            ];
        }

        return $loops;
    }

    /**
     * @param list<array{start: int, end: int}> $regions
     */
    public static function isInsideAnyRegion(int $offset, array $regions): bool
    {
        foreach ($regions as $region) {
            if ($offset >= $region['start'] && $offset < $region['end']) {
                return true;
            }
        }

        return false;
    }

    public static function attributeValue(string $tagSource, string $name): ?string
    {
        if (1 !== preg_match('#\b'.preg_quote($name, '#').'="([^"]*)"#i', $tagSource, $match)) {
            return null;
        }

        return $match[1];
    }

    /**
     * @return list<string>
     */
    public static function classTokens(string $tagSource): array
    {
        $class = self::attributeValue($tagSource, 'class');
        if (null === $class) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', $class) ?: [], static fn (string $token): bool => '' !== $token));
    }

    /**
     * Tailwind variants (`sm:absolute`, `hover:relative`) apply the same
     * property, so a guard that only compared bare tokens would be trivially
     * bypassed by prefixing one.
     *
     * @param list<string> $tokens
     */
    public static function hasUtility(array $tokens, string $utility): bool
    {
        foreach ($tokens as $token) {
            if ($token === $utility || str_ends_with($token, ':'.$utility)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything in a template that a human actually reads: text nodes plus the
     * attributes that are rendered or announced (`title`, `aria-label`,
     * `data-tip`, `alt`, `placeholder`). Twig tags are removed first, so route
     * names, filter values and form POST values — identifiers we deliberately
     * keep for backwards compatibility — never look like copy.
     */
    public static function visibleCopy(string $twig): string
    {
        $markup = self::stripTwigTags(self::stripTwigComments($twig));

        preg_match_all('/\b(?:title|aria-label|data-tip|alt|placeholder)="([^"]*)"/i', $markup, $attributes);

        $text = (string) preg_replace('#<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#s', ' ', $markup);

        return $text."\n".implode("\n", $attributes[1]);
    }

    private static function blankMatches(string $pattern, string $subject): string
    {
        return (string) preg_replace_callback(
            $pattern,
            static fn (array $match): string => (string) preg_replace('/[^\n]/', ' ', (string) $match[0]),
            $subject,
        );
    }
}
