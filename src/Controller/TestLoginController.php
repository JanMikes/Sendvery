<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signs a browser-test session in without a magic link.
 *
 * WHY THIS EXISTS: the browser smoke suite (tests/Browser/) drives a real
 * Chromium against a running app, and the product has no password login —
 * `RequestMagicLinkHandler::MAX_REQUESTS_PER_HOUR` caps magic links at 5 per
 * hour per email and, past the cap, fails SILENTLY (no mail, no error). A suite
 * that logged in for real would therefore pass for its first few runs and then
 * start timing out with no diagnosis. PHPUnit's KernelBrowser sidesteps this by
 * never speaking HTTP, which is exactly why it could not see the defects this
 * suite exists to catch.
 *
 * WHY IT IS SAFE: three independent gates, each of which alone is enough to
 * make the endpoint inert, and all three must open before a single line of
 * authentication runs.
 *
 *   1. Environment ALLOW-list (`dev`, `test`). Deliberately not `!== 'prod'`:
 *      a future `staging`/`preprod` environment is refused because it was never
 *      named, rather than admitted because nobody remembered to exclude it.
 *   2. A shared secret that is EMPTY in `.env` — the value every environment
 *      inherits unless something overrides it. Production ships with no secret
 *      configured and therefore no usable endpoint even if gate 1 were wrong.
 *      Only `.env.dev` and `.env.test` set a value, and neither file is loaded
 *      when `APP_ENV=prod`.
 *   3. The user must already exist. The endpoint never creates an account, so
 *      it cannot manufacture access to a database it was pointed at.
 *
 * Every refusal is a 404, never a 403: a 403 would confirm that the path is
 * real and that a secret is configured. `access_control` is untouched — this
 * logs a user in against the `main` firewall the normal way and `^/app` still
 * requires ROLE_USER.
 */
final readonly class TestLoginController
{
    private const array ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private Security $security,
        private UserRepository $userRepository,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        private string $testLoginSecret,
    ) {
    }

    #[Route('/_test/login', name: 'test_login', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new NotFoundHttpException('No such route.');
        }

        if ('' === $this->testLoginSecret) {
            throw new NotFoundHttpException('No such route.');
        }

        if (!hash_equals($this->testLoginSecret, $request->query->getString('secret'))) {
            throw new NotFoundHttpException('No such route.');
        }

        $email = strtolower(trim($request->query->getString('email')));
        $user = $this->userRepository->findByEmail($email);

        if (null === $user) {
            throw new NotFoundHttpException('No such route.');
        }

        $this->security->login($user, firewallName: 'main');

        // The response Security::login() hands back is MagicLinkAuthenticator's
        // (onboarding or overview, depending on the user). We deliberately
        // return our own fixed destination instead: a browser test needs one
        // known landing page, and OnboardingRedirectListener still bounces an
        // un-onboarded user from there, so nothing is bypassed.
        return new RedirectResponse($this->urlGenerator->generate('dashboard_overview'));
    }
}
