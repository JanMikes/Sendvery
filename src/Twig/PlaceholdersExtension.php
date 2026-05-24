<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the placeholder content from `config/placeholders.php` as Twig
 * globals. Single source of truth for any "we'll replace this at launch"
 * marketing content — currently testimonials (TASK-023) and the founder photo
 * / LinkedIn URL keys reserved for TASK-024. A required file is `array_merge`d
 * with safe defaults so the templates never see an undefined global even if
 * the config file is mis-edited.
 *
 * `final` (not `readonly final`) because `AbstractExtension` itself is not
 * readonly-compatible.
 */
final class PlaceholdersExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        $placeholders = $this->loadPlaceholders();

        // Explicit key enumeration (no `...` spread) prevents the config file
        // from ever leaking unexpected keys into the global Twig namespace.
        return [
            'testimonials' => $placeholders['testimonials'],
            'founder_photo' => $placeholders['founder_photo'],
            'linkedin_url' => $placeholders['linkedin_url'],
        ];
    }

    /**
     * @return array{
     *     testimonials: list<array{quote: string, name: string, role: string, company: string, initials: string}>,
     *     founder_photo: string|null,
     *     linkedin_url: string|null
     * }
     */
    private function loadPlaceholders(): array
    {
        $defaults = [
            'testimonials' => [],
            'founder_photo' => null,
            'linkedin_url' => null,
        ];

        /** @var array<string, mixed> $loaded */
        $loaded = require $this->projectDir.'/config/placeholders.php';

        /** @var array{testimonials: list<array{quote: string, name: string, role: string, company: string, initials: string}>, founder_photo: string|null, linkedin_url: string|null} $merged */
        $merged = array_merge($defaults, $loaded);

        return $merged;
    }
}
