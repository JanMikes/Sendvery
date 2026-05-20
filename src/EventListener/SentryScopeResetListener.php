<?php

declare(strict_types=1);

namespace App\EventListener;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resets Sentry scope at the start of every main request.
 *
 * FrankenPHP worker mode keeps PHP processes alive across requests, so the global Sentry scope
 * accumulates breadcrumbs, tags, user data and contexts from previous requests — which both leaks
 * data between users and inflates payloads. Priority 512 ensures we run before any other listener
 * adds breadcrumbs for the new request.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class SentryScopeResetListener
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $this->hub->configureScope(static function (Scope $scope): void {
            $scope->clear();
            $scope->setPropagationContext(PropagationContext::fromDefaults());
        });
    }
}
