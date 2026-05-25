<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the `github_url` Twig global so templates have a single source of
 * truth for the canonical Sendvery GitHub URL — change one constant here if
 * it ever moves. (The previous `is_repo_public` env gate was retired in
 * TASK-136 once the repo went public; every CTA now links directly to GitHub.).
 */
final class OpenSourceExtension extends AbstractExtension implements GlobalsInterface
{
    private const GITHUB_URL = 'https://github.com/janmikes/sendvery';

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'github_url' => self::GITHUB_URL,
        ];
    }
}
