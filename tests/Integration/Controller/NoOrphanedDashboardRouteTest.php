<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Catches the TASK-031 class of bug: a `dashboard_*` GET route ships with a
 * working controller, template, and tests, but is referenced from zero
 * templates — making it URL-typing-only and invisible to users.
 *
 * Strategy: enumerate every `dashboard_*` route from the router, auto-exclude
 * POST-only routes (action endpoints don't need a `path()` reference), and
 * for every remaining GET-capable route require at least one `path('name'`
 * reference in the rendered `.twig` template tree.
 *
 * Excluded routes are GET-capable but reachable only via external callbacks
 * (e.g. Stripe customer-portal returns) — they MUST never get a template
 * link by design. Add to {@see EXCLUDED_ROUTE_NAMES} with a one-line reason.
 */
final class NoOrphanedDashboardRouteTest extends KernelTestCase
{
    /**
     * - `dashboard_billing_manage`: redirects to Stripe Customer Portal; users
     *   reach it via the "Manage plan" form action POST, not a `path()` link.
     * - `dashboard_billing_success` / `dashboard_billing_cancel`: external
     *   Stripe redirect targets, never linked from our own templates.
     *
     * NB: `dashboard_billing_upgrade` and `dashboard_export_domain_pdf` are
     * deliberately NOT excluded — they are template-linked today, and the whole
     * point of this guard is to catch the case where someone deletes the only
     * link. A defensive exclusion would silently suppress that detection.
     */
    private const array EXCLUDED_ROUTE_NAMES = [
        'dashboard_billing_manage',
        'dashboard_billing_success',
        'dashboard_billing_cancel',
    ];

    #[Test]
    public function everyGetDashboardRouteIsReachableFromAtLeastOneTemplate(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        assert($router instanceof RouterInterface);

        $allTemplates = $this->concatenateAllTemplates();

        $missing = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'dashboard_')) {
                continue;
            }

            if (in_array($name, self::EXCLUDED_ROUTE_NAMES, true)) {
                continue;
            }

            $methods = $route->getMethods();
            // POST-only action routes (mute alert, mark read, etc.) are
            // submitted from forms — they don't need a `path()` lookup link.
            if ([] !== $methods && !in_array('GET', $methods, true)) {
                continue;
            }

            $needle = "path('".$name."'";
            if (!str_contains($allTemplates, $needle)) {
                $missing[] = $name;
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                "Found %d orphaned GET dashboard route(s) — controller works but no template links there:\n  - %s\n"
                ."Either: (a) add `path('<routeName>')` to a template, or (b) add to EXCLUDED_ROUTE_NAMES with a reason.",
                count($missing),
                implode("\n  - ", $missing),
            ),
        );
    }

    private function concatenateAllTemplates(): string
    {
        $templatesRoot = \dirname(__DIR__, 3).'/templates';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templatesRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $buffer = '';
        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            if ('twig' !== $file->getExtension()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            assert(false !== $contents);
            $buffer .= $contents."\n";
        }

        return $buffer;
    }
}
