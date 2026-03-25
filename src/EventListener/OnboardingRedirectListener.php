<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsEventListener(event: 'kernel.request', priority: 10)]
final readonly class OnboardingRedirectListener
{
    private const array ONBOARDING_ROUTES = [
        'onboarding_team',
        'onboarding_domain',
        'onboarding_ingestion',
        'onboarding_complete',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (null === $route) {
            return;
        }

        // Only intercept /app/* routes (but not onboarding routes themselves)
        if (!str_starts_with($request->getPathInfo(), '/app')) {
            return;
        }

        if (in_array($route, self::ONBOARDING_ROUTES, true)) {
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (null === $token) {
            return;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (null === $user->onboardingCompletedAt) {
            $event->setResponse(
                new RedirectResponse($this->urlGenerator->generate('onboarding_team')),
            );
        }
    }
}
