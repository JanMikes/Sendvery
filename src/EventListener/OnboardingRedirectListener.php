<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

// Priority must be below Symfony\Component\Security\Http\Firewall (priority 8) so the
// token has been populated from the session by the time this listener runs.
#[AsEventListener(event: 'kernel.request', priority: 4)]
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
        private Connection $database,
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

        if (null !== $user->onboardingCompletedAt && $this->userHasMonitoredDomain($user)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate('onboarding_team')),
        );
    }

    private function userHasMonitoredDomain(User $user): bool
    {
        $count = (int) $this->database->fetchOne(
            'SELECT COUNT(d.id)
             FROM monitored_domain d
             INNER JOIN team_membership tm ON tm.team_id = d.team_id
             WHERE tm.user_id = :userId',
            ['userId' => $user->id->toString()],
        );

        return $count > 0;
    }
}
