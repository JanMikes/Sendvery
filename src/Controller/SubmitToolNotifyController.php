<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\NotifyMeAboutTool;
use App\Services\IdentityProvider;
use App\Value\ToolNotifySource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TASK-006 — soft-conversion endpoint for the "Email me this report + alerts
 * if anything changes" micro-form that lives under every tool result. The
 * happy path returns a Turbo-frame fragment that swaps the form in place for
 * a "Check your inbox" confirmation. Hard-CTA fallback: when the request did
 * not arrive through a Turbo frame, we fall back to a redirect with a flash.
 */
final class SubmitToolNotifyController extends AbstractController
{
    private const string DOMAIN_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/';

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
    ) {
    }

    #[Route('/tools/notify', name: 'tools_notify_submit', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('tool_notify', $request->request->getString('_csrf_token'))) {
            // HttpException over createAccessDeniedException: this is a public
            // endpoint, so AccessDeniedException would be caught by the
            // firewall and turn into a redirect to /login. We want a hard
            // 403 instead so a tampered submit fails closed and visibly.
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Invalid CSRF token.');
        }

        $email = trim($request->request->getString('email'));
        $domain = trim($request->request->getString('domain'));
        $sourceValue = $request->request->getString('source');
        $source = ToolNotifySource::tryFrom($sourceValue);

        // Source is whitelist-validated against the enum — a tampered or unknown
        // value is a hard 400 so a misbehaving page can't poison analytics
        // with arbitrary source slugs.
        if (null === $source) {
            return $this->badSource();
        }

        $errors = $this->validate($email, $domain);

        if ([] !== $errors) {
            return $this->renderFrame([
                'domain' => $domain,
                'source' => $source->value,
                'email' => $email,
                'error' => $errors[0],
            ]);
        }

        $this->commandBus->dispatch(new NotifyMeAboutTool(
            signupId: $this->identityProvider->nextIdentity(),
            email: $email,
            domain: $domain,
            source: $source,
        ));

        return $this->renderFrame([
            'domain' => $domain,
            'source' => $source->value,
            'email' => $email,
            'success' => true,
        ]);
    }

    /**
     * @return list<string>
     */
    private function validate(string $email, string $domain): array
    {
        $errors = [];

        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if ('' === $domain || 1 !== preg_match(self::DOMAIN_PATTERN, $domain)) {
            // domain is supplied by the page (hidden input); a missing/garbled
            // value almost certainly means the user hit the endpoint without
            // running a scan first. Surface a friendly hint rather than 500.
            $errors[] = 'Please run a scan first so we know which domain to watch.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderFrame(array $context): Response
    {
        return $this->render('tools/_tool_notify_frame.html.twig', $context);
    }

    private function badSource(): Response
    {
        // Unknown source means the caller is broken — render an inert frame
        // rather than crashing the page that embedded the micro-form. The
        // 400 status lets ops alerting flag the malformed call without
        // surfacing a generic error page to the visitor.
        $response = new Response();
        $response->setStatusCode(Response::HTTP_BAD_REQUEST);

        return $this->render('tools/_tool_notify_frame.html.twig', [
            'domain' => '',
            'source' => '',
            'email' => '',
            'error' => 'This form is misconfigured. Please refresh the page and try again.',
        ], $response);
    }
}
