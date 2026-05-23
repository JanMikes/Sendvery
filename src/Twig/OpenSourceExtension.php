<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the `is_repo_public` + `github_url` Twig globals consumed by
 * `templates/about/open-source.html.twig`. The repo URL is a single
 * source of truth — change one constant here if the canonical URL ever
 * moves. The flag is read from `SENDVERY_REPO_PUBLIC` at service-construction
 * time (same `Autowire(env:)` trick as `PricingFlagsExtension`) so cache
 * stays correct across env changes.
 */
final class OpenSourceExtension extends AbstractExtension implements GlobalsInterface
{
    private const GITHUB_URL = 'https://github.com/janmikes/sendvery';

    public function __construct(
        #[Autowire(env: 'SENDVERY_REPO_PUBLIC')]
        private readonly string $repoPublic,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'is_repo_public' => '' !== $this->repoPublic && '0' !== $this->repoPublic,
            'github_url' => self::GITHUB_URL,
        ];
    }
}
