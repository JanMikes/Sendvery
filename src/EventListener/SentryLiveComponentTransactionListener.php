<?php

declare(strict_types=1);

namespace App\EventListener;

use Sentry\State\HubInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renames Sentry transactions for LiveComponent requests.
 *
 * Without this, every LiveComponent re-render shows up as the generic UX endpoint, which collapses
 * unrelated components into a single noisy transaction. We rewrite the name to
 * `LiveComponent::<ComponentName>::<action>` once the request attributes are populated.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: 10)]
final readonly class SentryLiveComponentTransactionListener
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (false === $request->attributes->has('_live_component')) {
            return;
        }

        $transaction = $this->hub->getTransaction();

        if (null === $transaction) {
            return;
        }

        $componentName = $request->attributes->get('_component_name')
            ?? $request->attributes->get('_live_component');

        if (!is_string($componentName) || '' === $componentName) {
            return;
        }

        $liveAction = $request->attributes->get('_live_action');
        $action = is_string($liveAction) && '' !== $liveAction ? $liveAction : 'render';

        $transaction->setName(sprintf('LiveComponent::%s::%s', $componentName, $action));
    }
}
