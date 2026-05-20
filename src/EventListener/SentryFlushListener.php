<?php

declare(strict_types=1);

namespace App\EventListener;

use Sentry\State\HubInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes the Sentry client after each request finishes.
 *
 * FrankenPHP worker mode never runs PHP shutdown handlers between requests, so events queued
 * via the async transport may never reach Sentry. Low priority (-512) ensures we flush after
 * the bundle's TracingRequestListener has finished the transaction.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -512)]
final readonly class SentryFlushListener
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $client = $this->hub->getClient();

        if (null === $client) {
            return;
        }

        $client->flush();
    }
}
