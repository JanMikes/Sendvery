<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the `ai_available` Twig global derived from the presence of
 * ANTHROPIC_API_KEY (DEC-057). Built as a runtime Twig extension rather
 * than via `twig.globals` because Symfony resolves `%env(bool:...)%` at
 * container-build time, which bakes the wrong value into the cache.
 *
 * `Autowire` injects the env at service-construction time, which IS
 * request-bound, so the cache stays correct across env changes.
 */
final class PricingFlagsExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        #[Autowire(env: 'ANTHROPIC_API_KEY')]
        private readonly string $anthropicApiKey,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'ai_available' => '' !== $this->anthropicApiKey,
        ];
    }
}
