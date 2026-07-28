<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Controller\TestLoginController;
use App\Repository\UserRepository;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The login bypass the browser smoke suite depends on must be unusable to
 * anyone who is not the browser smoke suite.
 *
 * WHAT THESE TESTS PROVE: with a secret configured (`.env.test` sets one), the
 * endpoint answers only to that exact secret and only for a user that already
 * exists; and with the controller constructed the way a production container
 * would construct it — `prod` environment, or an empty secret, which is what
 * `.env` ships — it refuses regardless of what the caller supplies.
 *
 * WHAT THEY DO NOT PROVE: that a real `APP_ENV=prod` container behaves this
 * way, because the test suite cannot boot a prod kernel (prod requires compiled
 * assets, a warmed prod cache and real credentials). The prod-environment and
 * empty-secret cases are therefore exercised by constructing the controller
 * directly with those values. That covers the controller's own decision, not
 * the wiring in front of it; the wiring is covered by
 * {@see envFileDoesNotShipAUsableSecret}, which reads the committed `.env`.
 */
final class TestLoginRouteTest extends WebTestCase
{
    #[Test]
    public function correctSecretSignsTheUserInAndLandsOnTheDashboard(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('testlogin-happy')
            ->build();

        $client->request('GET', '/_test/login', [
            'secret' => 'integration-test-secret',
            'email' => $persona->user->email,
        ]);

        self::assertResponseRedirects('/app');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function requestWithoutASecretIsIndistinguishableFromAMissingPage(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('testlogin-nosecret')
            ->build();

        $client->request('GET', '/_test/login', ['email' => $persona->user->email]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function requestWithAWrongSecretIsIndistinguishableFromAMissingPage(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('testlogin-wrongsecret')
            ->build();

        $client->request('GET', '/_test/login', [
            'secret' => 'integration-test-secret-x',
            'email' => $persona->user->email,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function theEndpointNeverCreatesAnAccountItWasAskedToSignIn(): void
    {
        $client = self::createClient();

        $client->request('GET', '/_test/login', [
            'secret' => 'integration-test-secret',
            'email' => 'never-seen-before@example.com',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull(
            $this->getService(UserRepository::class)->findByEmail('never-seen-before@example.com'),
            'A refused sign-in must not leave a new account behind.',
        );
    }

    #[Test]
    public function refusedRequestDoesNotSignAnybodyIn(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('testlogin-stillanon')
            ->build();

        $client->request('GET', '/_test/login', [
            'secret' => 'wrong',
            'email' => $persona->user->email,
        ]);
        self::assertResponseStatusCodeSame(404);

        // Proof the refusal is real and not merely a cosmetic status code: the
        // protected area still bounces the client to the login page.
        $client->request('GET', '/app');
        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function aProductionEnvironmentRefusesEvenWithTheRightSecret(): void
    {
        self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('testlogin-prodenv')
            ->build();

        $controller = $this->controllerConfiguredAs('prod', 'integration-test-secret');

        $this->expectException(NotFoundHttpException::class);
        $controller(Request::create('/_test/login', parameters: [
            'secret' => 'integration-test-secret',
            'email' => $persona->user->email,
        ]));
    }

    #[Test]
    public function anUnnamedEnvironmentRefusesRatherThanBeingAdmittedByOmission(): void
    {
        self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('testlogin-stagingenv')
            ->build();

        $controller = $this->controllerConfiguredAs('staging', 'integration-test-secret');

        $this->expectException(NotFoundHttpException::class);
        $controller(Request::create('/_test/login', parameters: [
            'secret' => 'integration-test-secret',
            'email' => $persona->user->email,
        ]));
    }

    #[Test]
    public function anUnconfiguredSecretLeavesTheEndpointInert(): void
    {
        self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('testlogin-nosecretconfigured')
            ->build();

        $controller = $this->controllerConfiguredAs('dev', '');

        // An empty configured secret must not be satisfiable by an empty
        // supplied secret — the endpoint is off, not open.
        $this->expectException(NotFoundHttpException::class);
        $controller(Request::create('/_test/login', parameters: [
            'secret' => '',
            'email' => $persona->user->email,
        ]));
    }

    #[Test]
    public function envFileDoesNotShipAUsableSecret(): void
    {
        $env = file_get_contents(dirname(__DIR__, 3).'/.env');
        self::assertIsString($env);

        self::assertStringContainsString(
            "\nSENDVERY_TEST_LOGIN_SECRET=\n",
            $env,
            'The committed .env must leave the browser-test login secret empty: it is the value production inherits.',
        );
    }

    private function controllerConfiguredAs(string $environment, string $secret): TestLoginController
    {
        return new TestLoginController(
            security: $this->getService(Security::class),
            userRepository: $this->getService(UserRepository::class),
            urlGenerator: $this->getService(UrlGeneratorInterface::class),
            environment: $environment,
            testLoginSecret: $secret,
        );
    }
}
