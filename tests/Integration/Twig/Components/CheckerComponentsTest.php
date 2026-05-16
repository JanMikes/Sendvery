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
}
