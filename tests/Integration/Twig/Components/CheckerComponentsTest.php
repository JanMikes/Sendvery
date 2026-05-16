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
     * End-to-end: rendered markup must wire the submit event to the live
     * controller. Earlier bug — `data-action` relied on Stimulus' default-event
     * detection, which silently failed in some browsers; this test pins the
     * explicit `submit->live#action:prevent` form to prevent regression.
     */
    #[Test]
    public function homeDomainCheckerWiresExplicitSubmitEvent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[data-live-action-param="check"]');
        self::assertGreaterThan(0, $form->count(), 'Homepage must contain a checker form');

        $action = (string) $form->attr('data-action');
        self::assertStringContainsString('submit->live#action', $action, 'Form must explicitly bind to submit event');
        self::assertStringContainsString(':prevent', $action, 'Form submission must call preventDefault');
    }
}
