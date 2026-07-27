<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Tests\TestSupport\ProjectSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards for the frontend mistakes that FAIL SILENTLY. None of them throws, logs
 * or breaks a build: the page simply renders wrong, or a control simply stops
 * responding, and only a human looking at it in a browser can tell. That is what
 * makes them worth a test — everything noisier is caught the first time someone
 * loads the page.
 *
 * Most of these invariants are documented in CLAUDE.md; these tests are the
 * enforcement CLAUDE.md cannot provide on its own.
 *
 * See {@see ProjectSource} for why asserting the absence of a broken class or
 * variable name does not violate CLAUDE.md's "never assert Tailwind classes"
 * rule.
 *
 * Every guard has a paired `…GuardItselfFailsOn…` test that feeds the detector a
 * synthetic violation, so the guards are never taken on trust.
 */
final class DesignSystemGuardTest extends TestCase
{
    /**
     * daisyUI v3/v4 theme variables. v5 reads `--color-*` and ignores these
     * entirely, so a theme written in the old dialect produces no error and no
     * colour — every semantic token falls back to black and white.
     */
    private const array LEGACY_DAISYUI_THEME_VARIABLES = [
        '--p', '--pf', '--pc',
        '--s', '--sf', '--sc',
        '--a', '--af', '--ac',
        '--n', '--nf', '--nc',
        '--b1', '--b2', '--b3', '--bc',
        '--in', '--inc',
        '--su', '--suc',
        '--wa', '--wac',
        '--er', '--erc',
        '--rounded-box', '--rounded-btn', '--rounded-badge',
        '--animation-btn', '--animation-input',
        '--btn-text-case', '--btn-focus-scale',
        '--tab-border', '--tab-radius',
    ];

    #[Test]
    public function noTailwindDarkVariantIsUsedAnywhere(): void
    {
        $offenders = [];
        foreach ($this->themableSources() as $path => $contents) {
            foreach (self::darkVariantUsages($contents) as $line) {
                $offenders[] = sprintf('%s:%d', $path, $line);
            }
        }

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                The Tailwind `dark:` variant does nothing in this app, so anything styled with
                it is styled with nothing.

                `dark:` responds to the OS colour-scheme media query (or a `.dark` class).
                Sendvery ships a single light daisyUI theme selected by `data-theme="sendvery"`
                on `<html>`, and dark mode was intentionally removed. The result is a rule that
                never matches: contrast fixes, borders and text colours written under `dark:`
                are invisible, and — worse — anyone testing with their OS set to dark sees a
                half-styled page that nobody else can reproduce.

                Style for the single theme, using daisyUI semantic tokens (`bg-base-200`,
                `text-base-content/70`, `border-base-300`) which already carry theme-correct
                values. See CLAUDE.md § Theme.

                Offending lines:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function noTwigBlockIsNestedInsideATwigComponentTag(): void
    {
        $offenders = [];
        foreach (ProjectSource::files('templates', 'twig') as $path => $contents) {
            foreach (self::blocksNestedInComponentTags($contents) as $line) {
                $offenders[] = sprintf('%s:%d', $path, $line);
            }
        }

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                A `{% block %}` inside a `<twig:Component>` tag breaks the TwigPreLexer, and it
                breaks it QUIETLY — nested `<twig:>` tags inside the block stop being
                recognised as components and are emitted as literal text or dropped, so the
                page renders with a section missing instead of raising an error anyone would
                notice.

                Content between `<twig:Component>` and `</twig:Component>` already maps to the
                component's default `content` block, so the wrapper is never needed:

                    {# WRONG #}                          {# CORRECT #}
                    <twig:SectionContainer>              <twig:SectionContainer>
                        {% block content %}                  <twig:PricingTable />
                            <twig:PricingTable />        </twig:SectionContainer>
                        {% endblock %}
                    </twig:SectionContainer>

                See CLAUDE.md § Twig Components.

                Offending blocks:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function theThemeUsesTheDaisyUiVersion5Dialect(): void
    {
        $css = (string) file_get_contents(ProjectSource::projectDir().'/assets/styles/app.css');
        $offenders = self::legacyThemeVariables($css);

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                These are daisyUI v3/v4 theme variables. daisyUI 5 reads `--color-*` inside a
                `@plugin "daisyui/theme" {}` block and ignores the old short names completely —
                no warning, no build failure. The whole UI simply loses its palette: every
                `btn-primary`, `badge-success` and `text-error` falls back to black and white,
                which reads as "the CSS failed to load" rather than "the theme is written in
                the wrong dialect", and sends people hunting in the asset pipeline.

                Use `--color-primary`, `--color-base-100`, `--radius-box`, … as documented in
                CLAUDE.md § Theme definition.

                Offending declarations:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function theThemeStillDeclaresTheRequiredVersion5Tokens(): void
    {
        // The mirror of the guard above: proof that app.css is a real v5 theme
        // rather than an empty file the legacy-variable scan would also pass.
        $css = (string) file_get_contents(ProjectSource::projectDir().'/assets/styles/app.css');

        self::assertStringContainsString('@plugin "daisyui/theme"', $css);
        foreach (['--color-base-100', '--color-base-content', '--color-primary', '--color-success', '--color-warning', '--color-error', '--radius-box'] as $required) {
            self::assertStringContainsString($required.':', $css, sprintf('daisyUI 5 needs %s to render semantic colours.', $required));
        }
    }

    #[Test]
    public function everyStimulusBehaviourATemplateAsksForIsImplemented(): void
    {
        $offenders = [];
        foreach (ProjectSource::files('templates', 'twig') as $path => $contents) {
            foreach (self::stimulusReferences($contents) as $reference) {
                $problem = self::describeMissingStimulusBehaviour($reference['controller'], $reference['method']);
                if (null === $problem) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d — %s', $path, ProjectSource::lineOfOffset($contents, $reference['offset']), $problem);
            }
        }

        self::assertSame(
            [],
            $offenders,
            <<<'TXT'
                A template asks for Stimulus behaviour that does not exist.

                This is the quietest failure in the frontend. A `data-controller` naming a
                controller with no matching file registers nothing at all — no exception, no
                network error, nothing in the UI. A `data-action` naming a method the
                controller does not define logs one line to a console nobody has open. Either
                way the element keeps its `cursor-pointer` and its hover state and simply
                stops working: the user clicks a row, a copy button, a filter, and the page
                does nothing.

                Renaming `assets/controllers/row_link_controller.js` or its `navigate()`
                method would make EVERY clickable table row in the dashboard dead in exactly
                this way, which is why it is worth a test rather than a convention.

                Missing behaviour:
                TXT."\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function stimulusGuardItselfFailsOnAMissingControllerOrMethod(): void
    {
        $missingController = '<div data-controller="row-linkk" data-action="click->row-linkk#navigate">';
        $missingMethod = '<tr data-controller="row-link" data-action="click->row-link#opne">';
        $correct = '<tr data-controller="row-link" data-action="click->row-link#navigate">';

        self::assertNotSame([], self::unimplementedStimulusReferences($missingController));
        self::assertSame(['row-link#opne (row-link has no opne() method)'], self::unimplementedStimulusReferences($missingMethod));
        self::assertSame([], self::unimplementedStimulusReferences($correct));
        self::assertSame(
            [],
            self::unimplementedStimulusReferences('<div data-action="keydown.enter->live#action:prevent">'),
            'Third-party UX controllers ship with the bundle rather than assets/controllers and must not be reported as missing.',
        );
    }

    #[Test]
    public function theGuardsActuallyScanTheThemableSources(): void
    {
        $sources = $this->themableSources();

        self::assertGreaterThan(50, \count($sources), 'The scan should cover the whole template tree plus the stylesheets — it is covering almost nothing.');
        self::assertArrayHasKey('assets/styles/app.css', $sources);
        self::assertArrayNotHasKey('assets/vendor/daisyui/daisyui.index.js', $sources, 'Vendored daisyUI ships the stock dark theme; scanning it would make the `dark:` guard permanently red on code we do not own.');
    }

    #[Test]
    public function darkVariantGuardItselfFailsOnADarkUtility(): void
    {
        self::assertSame([1], self::darkVariantUsages('<div class="bg-base-100 dark:bg-base-900">'));
        self::assertSame([1], self::darkVariantUsages("<div class='dark:text-white'>"));
        self::assertSame([], self::darkVariantUsages('<div class="bg-base-100">'));
        self::assertSame([], self::darkVariantUsages('<p>Prose about dark: patterns in email design.</p>'), 'Prose containing the word must not be mistaken for a utility.');
    }

    #[Test]
    public function componentBlockGuardItselfFailsOnANestedBlock(): void
    {
        $violation = <<<'TWIG'
            <twig:SectionContainer>
                {% block content %}
                    <twig:PricingTable />
                {% endblock %}
            </twig:SectionContainer>
            TWIG;

        self::assertSame([2], self::blocksNestedInComponentTags($violation));
        self::assertSame([], self::blocksNestedInComponentTags("<twig:SectionContainer>\n    <twig:PricingTable />\n</twig:SectionContainer>"));
        self::assertSame([], self::blocksNestedInComponentTags("{% block content %}\n    <twig:PricingTable />\n{% endblock %}"), 'A top-level template block wrapping components is ordinary Twig inheritance.');
    }

    #[Test]
    public function legacyThemeVariableGuardItselfFailsOnTheOldDialect(): void
    {
        self::assertSame(['3: --p'], self::legacyThemeVariables("@plugin \"daisyui/theme\" {\n  name: \"x\";\n  --p: 49% 0.13 176;\n}"));
        self::assertSame(['2: --rounded-btn'], self::legacyThemeVariables(":root {\n  --rounded-btn: 0.5rem;\n}"));
        self::assertSame([], self::legacyThemeVariables(":root {\n  --color-primary: oklch(49% 0.13 176);\n  --padding-card: 1rem;\n}"), 'Long-named custom properties that merely start with the same letters are not the v4 dialect.');
    }

    /**
     * Every source file whose classes or custom properties end up in the
     * compiled stylesheet. `assets/vendor/` is excluded on purpose: it is
     * checked-in third-party code that ships daisyUI's own stock dark theme, so
     * scanning it would make the guard permanently red on code we do not author.
     *
     * @return array<string, string>
     */
    private function themableSources(): array
    {
        return [
            ...ProjectSource::files('templates', 'twig'),
            ...ProjectSource::files('assets/styles', 'css'),
            ...ProjectSource::files('assets/controllers', 'js'),
        ];
    }

    /**
     * Stimulus controllers shipped by Symfony UX bundles rather than by
     * assets/controllers/. Their methods live in vendor code, so only the
     * controller name can be validated — and only against this list.
     */
    private const array VENDOR_CONTROLLERS = ['live', 'turbo-core', 'mercure-turbo-stream'];

    /**
     * Every controller/method pair a template asks Stimulus for, from all three
     * spellings the codebase uses: `{{ stimulus_controller('x') }}`,
     * `data-controller="x"` and `data-action="event->x#method"`.
     *
     * @return list<array{offset: int, controller: string, method: ?string}>
     */
    private static function stimulusReferences(string $twig): array
    {
        $references = [];

        preg_match_all('/stimulus_controller\(\s*[\'"]([a-z0-9_-]+)[\'"]/i', $twig, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $match) {
            $references[] = ['offset' => (int) $match[1], 'controller' => (string) $match[0], 'method' => null];
        }

        preg_match_all('/data-controller="([^"]*)"/i', $twig, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $match) {
            foreach (preg_split('/\s+/', (string) $match[0]) ?: [] as $name) {
                if ('' === $name || str_contains($name, '{')) {
                    continue;
                }

                $references[] = ['offset' => (int) $match[1], 'controller' => $name, 'method' => null];
            }
        }

        preg_match_all('/data-action="([^"]*)"/i', $twig, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $match) {
            foreach (preg_split('/\s+/', (string) $match[0]) ?: [] as $descriptor) {
                if (!str_contains($descriptor, '#') || str_contains($descriptor, '{')) {
                    continue;
                }

                // `click->row-link#navigate` and the event-less `row-link#navigate`
                // are both valid Stimulus action descriptors.
                $arrow = strpos($descriptor, '->');
                $target = false === $arrow ? $descriptor : substr($descriptor, $arrow + 2);
                [$controller, $method] = explode('#', $target, 2);
                $references[] = [
                    'offset' => (int) $match[1],
                    'controller' => $controller,
                    // Stimulus action options (`#action:prevent`, `#action:!passive`)
                    // are not part of the method name.
                    'method' => explode(':', $method)[0],
                ];
            }
        }

        return $references;
    }

    private static function describeMissingStimulusBehaviour(string $controller, ?string $method): ?string
    {
        if (\in_array($controller, self::VENDOR_CONTROLLERS, true)) {
            return null;
        }

        $file = sprintf('%s/assets/controllers/%s_controller.js', ProjectSource::projectDir(), str_replace('-', '_', $controller));
        if (!is_file($file)) {
            return sprintf('%s has no assets/controllers/%s_controller.js', $controller, str_replace('-', '_', $controller));
        }

        if (null === $method) {
            return null;
        }

        $source = (string) file_get_contents($file);
        if (1 === preg_match('/(?<![\w$])'.preg_quote($method, '/').'\s*(?:\(|=)/', $source)) {
            return null;
        }

        return sprintf('%s#%s (%s has no %s() method)', $controller, $method, $controller, $method);
    }

    /**
     * @return list<string>
     */
    private static function unimplementedStimulusReferences(string $twig): array
    {
        $problems = [];
        foreach (self::stimulusReferences($twig) as $reference) {
            $problem = self::describeMissingStimulusBehaviour($reference['controller'], $reference['method']);
            if (null !== $problem) {
                $problems[] = $problem;
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @return list<int> line numbers
     */
    private static function darkVariantUsages(string $contents): array
    {
        // Anchored on a class-list boundary (quote, whitespace, backtick) and
        // followed by the start of a utility, so `dark:` written in prose or in a
        // URL is not mistaken for a Tailwind variant.
        preg_match_all('/(?:^|[\s"\'`])dark:[a-z\[-]/', $contents, $matches, \PREG_OFFSET_CAPTURE);

        $lines = [];
        foreach ($matches[0] as $match) {
            $lines[] = ProjectSource::lineOfOffset($contents, (int) $match[1]);
        }

        return $lines;
    }

    /**
     * @return list<int> line numbers
     */
    private static function blocksNestedInComponentTags(string $twig): array
    {
        $markup = ProjectSource::stripTwigComments($twig);

        $lines = [];
        foreach (self::componentRegions($markup) as $region) {
            $inner = substr($markup, $region['start'], $region['end'] - $region['start']);
            preg_match_all('/\{%-?\s*block\s/', $inner, $blocks, \PREG_OFFSET_CAPTURE);

            foreach ($blocks[0] as $block) {
                $lines[] = ProjectSource::lineOfOffset($markup, $region['start'] + (int) $block[1]);
            }
        }

        sort($lines);

        return $lines;
    }

    /**
     * Inner ranges of every non-self-closing `<twig:Component>` element.
     *
     * @return list<array{start: int, end: int}>
     */
    private static function componentRegions(string $markup): array
    {
        preg_match_all(
            '#<twig:([A-Za-z_][A-Za-z0-9_:]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#s',
            $markup,
            $matches,
            \PREG_OFFSET_CAPTURE,
        );

        $regions = [];
        foreach ($matches[0] as $index => $match) {
            if (str_ends_with(rtrim((string) $matches[2][$index][0]), '/')) {
                continue;
            }

            $name = (string) $matches[1][$index][0];
            $innerStart = (int) $match[1] + \strlen((string) $match[0]);
            $closePosition = strpos($markup, '</twig:'.$name.'>', $innerStart);
            if (false === $closePosition) {
                continue;
            }

            $regions[] = ['start' => $innerStart, 'end' => $closePosition];
        }

        return $regions;
    }

    /**
     * @return list<string> "line: variable" for each offending declaration
     */
    private static function legacyThemeVariables(string $css): array
    {
        $offenders = [];
        foreach (self::LEGACY_DAISYUI_THEME_VARIABLES as $variable) {
            preg_match_all('/(?<![\w-])'.preg_quote($variable, '/').'\s*:/', $css, $matches, \PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                $offenders[] = sprintf('%d: %s', ProjectSource::lineOfOffset($css, (int) $match[1]), $variable);
            }
        }

        sort($offenders);

        return $offenders;
    }
}
