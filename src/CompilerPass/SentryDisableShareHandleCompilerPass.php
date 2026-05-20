<?php

declare(strict_types=1);

namespace App\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Disables curl's persistent share handle in Sentry's HTTP transport.
 *
 * FrankenPHP worker mode keeps a single PHP process — and the curl handle — alive across
 * requests. When the remote Sentry ingest closes an idle pooled connection, curl happily reuses
 * the dead socket and surfaces "upstream connect error / disconnect before headers". Turning
 * the share handle off forces a fresh connection per send, at the cost of a few ms of overhead
 * on error reports — a worthwhile trade.
 */
final class SentryDisableShareHandleCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('sentry.client.options')) {
            return;
        }

        $definition = $container->getDefinition('sentry.client.options');

        /** @var array<string, mixed> $options */
        $options = $definition->getArgument(0);
        $options['http_enable_curl_share_handle'] = false;

        $definition->setArgument(0, $options);
    }
}
