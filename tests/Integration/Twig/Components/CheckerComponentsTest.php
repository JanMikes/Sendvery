<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Tests\WebTestCase;
use App\Twig\Components\BlacklistCheckerComponent;
use App\Twig\Components\DkimCheckerComponent;
use App\Twig\Components\SpfCheckerComponent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Smoke tests for the Live Component wiring. The underlying DNS services are
 * tested elsewhere — here we just verify the `check` LiveAction is reachable
 * and that empty input is handled gracefully.
 */
final class CheckerComponentsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    #[Test]
    public function spfCheckerWithEmptyDomainLeavesResultNull(): void
    {
        // Hit the SUT once so KernelTestCase boots before createLiveComponent.
        self::createClient();

        $component = $this->createLiveComponent('SpfChecker')->call('check');

        $instance = $component->component();
        self::assertInstanceOf(SpfCheckerComponent::class, $instance);
        self::assertNull($instance->result);
    }

    #[Test]
    public function dkimCheckerExposesSelectorAsLiveProp(): void
    {
        self::createClient();

        $component = $this->createLiveComponent('DkimChecker')
            ->set('selector', 'google');

        $instance = $component->component();
        self::assertInstanceOf(DkimCheckerComponent::class, $instance);
        self::assertSame('google', $instance->selector);
    }

    #[Test]
    public function blacklistCheckerEmptyDomainProducesNoResult(): void
    {
        self::createClient();

        $component = $this->createLiveComponent('BlacklistChecker')->call('check');

        $instance = $component->component();
        self::assertInstanceOf(BlacklistCheckerComponent::class, $instance);
        self::assertNull($instance->result);
        self::assertFalse($instance->unresolved);
    }

    /**
     * Rendered markup contract for the checker widget: no <form>, a button with
     * data-action="live#action", and an input that triggers the same action on
     * Enter. This pattern matches the Live Component v3 docs example and keeps
     * the LiveAction the only handler in the chain (no submit semantics to
     * intercept, no implicit form posts).
     */
    #[Test]
    public function homeDomainCheckerWiresButtonClickAndEnterKey(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        $crawler = $client->getCrawler();

        $widget = $crawler->filter('[data-live-name-value="HomeDomainChecker"]');
        self::assertGreaterThan(0, $widget->count(), 'Homepage must contain the HomeDomainChecker live component');

        self::assertCount(0, $widget->filter('form'), 'Checker widget must not use a <form> element');

        $button = $widget->filter('button[data-live-action-param="check"]');
        self::assertGreaterThan(0, $button->count(), 'Checker widget must have a button bound to the check action');
        self::assertSame('button', $button->attr('type'), 'Button must be type="button" so no implicit form submission occurs');
        self::assertStringContainsString('live#action', (string) $button->attr('data-action'));

        $input = $widget->filter('input[data-model*="domain"]');
        self::assertGreaterThan(0, $input->count(), 'Checker widget must have a domain input');
        $inputAction = (string) $input->attr('data-action');
        self::assertStringContainsString('keydown.enter->live#action', $inputAction, 'Input must trigger check on Enter');
        self::assertStringContainsString(':prevent', $inputAction, 'Enter handler must preventDefault');
    }

    /**
     * v3 of ux-live-component requires the parentheses syntax for data-loading
     * directives — `addClass(foo)` / `removeClass(foo)` / `addAttribute(foo)`.
     * The colon shorthand (`addClass:foo`) is rejected by parseDirectives,
     * which makes the live controller throw during init — net effect on the
     * page: NO action handlers attach and clicks do nothing. This regressed
     * silently once already; this test pins the correct syntax so any future
     * regression breaks CI instead of just breaking production UX.
     */
    #[Test]
    public function checkerTemplatesUseValidDataLoadingSyntax(): void
    {
        $templatesDir = \dirname(__DIR__, 4).'/templates/components';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templatesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $offenders = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'twig' !== $file->getExtension()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            // Match `data-loading="<action>:<arg>"` — invalid colon syntax.
            if (preg_match_all('/data-loading="(addClass|removeClass|addAttribute|removeAttribute|addAttr|removeAttr)[:][^"]+"/', $contents, $matches, \PREG_OFFSET_CAPTURE) > 0) {
                $offenders[$file->getFilename()] = array_column($matches[0], 0);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "data-loading attribute uses invalid colon syntax (v3 requires parentheses).\n".
            "Fix: change `addClass:foo` -> `addClass(foo)`, `addAttr:foo` -> `addAttribute(foo)`, etc.\n".
            'Offenders: '.json_encode($offenders, \JSON_PRETTY_PRINT),
        );
    }

    /**
     * `import '@symfony/stimulus-bundle';` is a NO-OP — the bundle's loader only
     * exports `startStimulusApp` / `loadControllers`, it does not auto-start.
     * Without an explicit `startStimulusApp()` call the Stimulus application
     * never boots, no controllers register, and every Live Component on the
     * site silently does nothing on click. That's exactly the bug this test
     * exists to prevent.
     */
    #[Test]
    public function appJsActuallyStartsStimulus(): void
    {
        $appJs = file_get_contents(\dirname(__DIR__, 4).'/assets/app.js');
        self::assertIsString($appJs);

        self::assertMatchesRegularExpression(
            '/import\s*\{\s*startStimulusApp\s*\}\s*from\s*[\'"]@symfony\/stimulus-bundle[\'"]/',
            $appJs,
            'assets/app.js must import startStimulusApp from @symfony/stimulus-bundle',
        );
        self::assertMatchesRegularExpression(
            '/startStimulusApp\s*\(\s*\)/',
            $appJs,
            'assets/app.js must call startStimulusApp() — a bare `import "@symfony/stimulus-bundle"` does NOT auto-start Stimulus and silently breaks every Live Component',
        );
    }
}
