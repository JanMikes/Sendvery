<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Message\RequestMagicLink;
use App\Services\IdentityProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Sign-in and registration are the same request on purpose (enumeration
 * resistance), which makes this the single endpoint through which every
 * account is born — and the endpoint a July 2026 abuse campaign drip-fed
 * with victim addresses to turn our magic-link mail into unsolicited spam.
 *
 * Defence follows the in-house layered pattern from AboutContactController
 * (honeypot + time-trap + rate-limiter — no 3rd-party CAPTCHA): the observed
 * bot never fetched the form, so requiring anything that only the rendered
 * form carries (CSRF token, render timestamp) already defeats it, and each
 * additional layer covers the next evolution. Bot-tripped submissions get
 * the same "check your email" redirect as real ones — an error response
 * would teach the operator which layer fired.
 */
final class LoginController extends AbstractController
{
    /**
     * The login form is one prefilled-by-autofill email field, so a real
     * human can clear it faster than the contact form's four fields — but
     * loading the page, seeing it, and clicking still takes over 2 seconds.
     * The bots this targets POST without ever loading the page at all.
     */
    private const int MINIMUM_HUMAN_FILL_SECONDS = 2;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ValidatorInterface $validator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Target('login_form')]
        private readonly RateLimiterFactoryInterface $loginFormLimiter,
    ) {
    }

    #[Route('/login', name: 'auth_login', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard_overview');
        }

        if ($request->isMethod('POST')) {
            return $this->handleLogin($request);
        }

        return $this->renderLoginForm();
    }

    private function handleLogin(Request $request): Response
    {
        $email = trim($request->request->getString('email'));

        // CSRF failure is the one bot-check that re-renders instead of
        // pretending success: a human whose session expired overnight would
        // otherwise wait forever for an email that was never sent. A fresh
        // token comes with the re-render, so the human recovers with one
        // click — while a bot that never fetched the form has no token and
        // gets nothing out of the 422.
        if (!$this->isCsrfTokenValid('login_form', $request->request->getString('_csrf_token'))) {
            return $this->renderLoginForm(
                email: $email,
                errors: ['Your session expired. Please try again.'],
            );
        }

        // Honeypot — bots fill every input they find; humans cannot see
        // `website` (display:none + aria-hidden + tabindex=-1).
        if ('' !== $request->request->getString('website')) {
            return $this->pretendSuccess($request, $email);
        }

        // Time-trap — the render timestamp is missing (form never loaded)
        // or the submit came in faster than a human can act.
        $renderedAtRaw = $request->request->get('renderedAt');
        $renderedAt = is_numeric($renderedAtRaw) ? (int) $renderedAtRaw : null;
        $nowTs = $this->clock->now()->getTimestamp();
        if (null === $renderedAt || ($nowTs - $renderedAt) < self::MINIMUM_HUMAN_FILL_SECONDS) {
            return $this->pretendSuccess($request, $email);
        }

        // Per-IP volume cap. Silent on purpose: announcing "rate limited"
        // would hand the operator the exact request budget to stay under,
        // and a mistyped-email human retrying is nowhere near 10/hour.
        $limiter = $this->loginFormLimiter->create($request->getClientIp() ?? 'anonymous');
        if (!$limiter->consume()->isAccepted()) {
            $this->logger->warning('Login form rate limit exceeded.', [
                'ip' => $request->getClientIp(),
                'email' => $email,
            ]);

            return $this->pretendSuccess($request, $email);
        }

        $violations = $this->validator->validate($email, [
            new Assert\NotBlank(message: 'Please enter your email address.'),
            new Assert\Email(message: 'Please enter a valid email address.'),
        ]);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = (string) $violation->getMessage();
            }

            return $this->renderLoginForm(email: $email, errors: $errors);
        }

        $this->commandBus->dispatch(new RequestMagicLink(
            tokenId: $this->identityProvider->nextIdentity(),
            email: strtolower($email),
            requestedIp: $request->getClientIp(),
            requestedUserAgent: substr($request->headers->get('User-Agent', '') ?? '', 0, 512),
        ));

        $request->getSession()->set('pending_login_email', $email);

        return $this->redirectToRoute('auth_check_email');
    }

    /**
     * Indistinguishable from the real flow — same session key, same
     * redirect, same confirmation page — but no token row and no email.
     */
    private function pretendSuccess(Request $request, string $email): Response
    {
        $request->getSession()->set('pending_login_email', $email);

        return $this->redirectToRoute('auth_check_email');
    }

    /**
     * @param list<string> $errors
     */
    private function renderLoginForm(string $email = '', array $errors = []): Response
    {
        // Turbo treats 422 as a form-error response and replaces the
        // <form> in place. Returning 200 would trigger Turbo's
        // "Form responses must redirect to another location" error.
        return $this->render('auth/login.html.twig', [
            'email' => $email,
            'errors' => $errors,
            'renderedAt' => $this->clock->now()->getTimestamp(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
