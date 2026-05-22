<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * Generic safety net: every GET route with no path parameters must render
 * (non-5xx) for both anonymous and onboarded users. Catches Twig parse
 * errors, container wiring breakages, and "I shipped a controller without
 * a test" regressions without anyone having to remember to write a test.
 *
 * Routes with path parameters (e.g. /app/domains/{id}, /learn/{slug}) are
 * covered separately by per-controller scenario tests where we can wire
 * up the right fixture IDs.
 */
final class RouteSmokeTest extends WebTestCase
{
    /**
     * Routes that we cannot smoke-test with a plain GET against a static URL.
     *
     * - Path-parameter routes: handled by targeted scenario tests.
     * - Webhooks / API entrypoints: not page renders.
     * - Health-check: deliberately bypasses auth, has its own coverage.
     * - Logout: terminates session; covered as its own scenario.
     * - billing/manage: hits the Stripe Customer Portal, requires live Stripe state.
     */
    private const array EXCLUDED_ROUTE_NAMES = [
        'webhook_stripe',
        'health_check_liveness',
        'auth_logout',
        'dashboard_billing_manage',
    ];

    private const array EXCLUDED_ROUTE_PREFIXES = [
        '_',           // Symfony internal (_profiler, _wdt, _components)
        'api_',        // API Platform — JSON endpoints, tested separately
        'ux_',         // Symfony UX live components
    ];

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function staticGetRoutesProvider(): iterable
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        assert($router instanceof RouterInterface);

        foreach ($router->getRouteCollection() as $name => $route) {
            if (in_array($name, self::EXCLUDED_ROUTE_NAMES, true)) {
                continue;
            }

            foreach (self::EXCLUDED_ROUTE_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }

            $methods = $route->getMethods();
            if ([] !== $methods && !in_array('GET', $methods, true)) {
                continue;
            }

            $path = $route->getPath();
            if (str_contains($path, '{')) {
                continue;
            }

            yield $name => [$path];
        }

        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('staticGetRoutesProvider')]
    public function routeRendersForAnonymousUser(string $path): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        $status = $client->getResponse()->getStatusCode();
        self::assertLessThan(
            500,
            $status,
            sprintf('Anonymous GET %s returned %d. Body: %s', $path, $status, $this->snippet($client->getResponse()->getContent())),
        );
    }

    #[Test]
    #[DataProvider('staticGetRoutesProvider')]
    public function routeRendersForOnboardedUser(string $path): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', $path);

        $status = $client->getResponse()->getStatusCode();
        self::assertLessThan(
            500,
            $status,
            sprintf('Authenticated GET %s returned %d. Body: %s', $path, $status, $this->snippet($client->getResponse()->getContent())),
        );
    }

    private function snippet(string|false $body): string
    {
        if (false === $body) {
            return '<no body>';
        }

        return substr(strip_tags($body), 0, 400);
    }
}
