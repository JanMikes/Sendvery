<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Repository\MagicLinkTokenRepository;
use App\Repository\UserRepository;
use App\Services\IdentityProvider;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MagicLinkAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly MagicLinkTokenRepository $magicLinkTokenRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityProvider $identityProvider,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'auth_verify_magic_link' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $tokenString = $request->attributes->get('token', '');
        $magicLinkToken = $this->magicLinkTokenRepository->findByToken($tokenString);

        if (null === $magicLinkToken) {
            throw new CustomUserMessageAuthenticationException('This login link is invalid.');
        }

        $now = $this->clock->now();

        if ($magicLinkToken->isExpired($now)) {
            throw new CustomUserMessageAuthenticationException('This login link has expired. Please request a new one.');
        }

        if ($magicLinkToken->isUsed()) {
            throw new CustomUserMessageAuthenticationException('This login link has already been used. Please request a new one.');
        }

        $magicLinkToken->markUsed($now);

        $user = $this->userRepository->findByEmail($magicLinkToken->email);

        if (null === $user) {
            $user = $this->createNewUser($magicLinkToken->email, $now);
            $magicLinkToken->user = $user;
        }

        $user->lastLoginAt = $now;

        $this->entityManager->flush();

        return new SelfValidatingPassport(
            new UserBadge($user->email),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();

        if ($user instanceof User && null === $user->onboardingCompletedAt) {
            return new RedirectResponse($this->urlGenerator->generate('onboarding_team'));
        }

        return new RedirectResponse($this->urlGenerator->generate('dashboard_overview'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('auth_login'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set('auth_error', $exception->getMessageKey());

        return new RedirectResponse($this->urlGenerator->generate('auth_login_failed'));
    }

    private function createNewUser(string $email, \DateTimeImmutable $now): User
    {
        $user = new User(
            id: $this->identityProvider->nextIdentity(),
            email: $email,
            createdAt: $now,
        );

        $this->entityManager->persist($user);

        $domain = $this->extractDomain($email);
        $slugger = new AsciiSlugger();
        $slug = $slugger->slug($domain)->lower()->toString().'-'.substr($user->id->toString(), 0, 8);

        $team = new Team(
            id: $this->identityProvider->nextIdentity(),
            name: $domain,
            slug: $slug,
            createdAt: $now,
        );

        $this->entityManager->persist($team);

        $membership = new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: $now,
        );

        $this->entityManager->persist($membership);

        return $user;
    }

    private function extractDomain(string $email): string
    {
        $parts = explode('@', $email);

        return $parts[1] ?? 'personal';
    }
}
