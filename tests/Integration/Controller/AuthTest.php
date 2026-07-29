<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MagicLinkToken;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Message\RequestMagicLink;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AuthTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Wipe the rate-limiter pool between tests — it is filesystem-backed
        // in test (see when@test framework.cache) so limiter state would
        // otherwise leak across tests and make the per-IP cap tests
        // order-dependent (every functional test shares the 127.0.0.1 key).
        self::bootKernel();
        $pool = self::getContainer()->get('cache.rate_limiter');
        assert($pool instanceof \Psr\Cache\CacheItemPoolInterface);
        $pool->clear();
        self::ensureKernelShutdown();
    }

    #[Test]
    public function loginPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email"]');
    }

    #[Test]
    public function loginPageHasForm(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('button[type="submit"]');
    }

    #[Test]
    public function submitValidEmailRedirectsToCheckEmailPage(): void
    {
        $client = self::createClient();
        $email = 'login-'.Uuid::uuid7()->toString().'@example.com';

        $this->postLogin($client, $email);

        self::assertResponseRedirects('/login/check-email');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Check your email');
    }

    #[Test]
    public function validSubmissionQueuesMagicLinkRequestWithOrigin(): void
    {
        $client = self::createClient();
        $email = 'Origin-'.Uuid::uuid7()->toString().'@Example.com';

        $this->postLogin($client, $email);

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent, 'A legitimate submission must queue exactly one magic-link request — the email is sent by the worker, never inside the web request, so a flood of submissions cannot occupy web workers with SMTP transactions.');

        $message = $sent[0]->getMessage();
        assert($message instanceof RequestMagicLink);
        self::assertSame(strtolower($email), $message->email);
        self::assertSame('127.0.0.1', $message->requestedIp, 'The origin IP must travel with the request so the token row keeps a forensic trail of who asked.');
        self::assertNotNull($message->requestedUserAgent, 'The User-Agent must travel with the request — rotating decade-old UAs is how the July 2026 campaign was identified.');
    }

    #[Test]
    public function loginFormCarriesBotDefences(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_csrf_token"]', 'The form must carry a CSRF token — the observed bots never fetch the form, so requiring anything only the rendered form contains defeats them.');
        self::assertSelectorExists('input[name="renderedAt"]', 'The form must carry the render timestamp for the time-trap.');

        $crawler = $client->getCrawler();
        $wrapper = $crawler->filter('div[style*="display:none"][aria-hidden="true"] input[name="website"]');
        self::assertCount(1, $wrapper, 'The honeypot must be invisible to humans AND assistive tech — a visible honeypot gets filled by screen-reader users and produces false positives.');
        self::assertSame('-1', $wrapper->attr('tabindex'), 'The honeypot must be skipped by keyboard navigation.');
        self::assertSame('off', $wrapper->attr('autocomplete'), 'autocomplete="off" keeps browsers from auto-filling the honeypot for legitimate users.');
    }

    #[Test]
    public function submissionWithoutCsrfTokenRerendersWithRecoveryPath(): void
    {
        $client = self::createClient();

        $client->request('POST', '/login', [
            'email' => 'no-csrf@example.com',
            'renderedAt' => (string) $this->pastTimestamp(),
            'website' => '',
        ]);

        self::assertResponseStatusCodeSame(422, 'A missing CSRF token must not be a hard 403: a human whose session expired overnight needs a recovery path, and the 422 re-render carries a fresh token.');
        self::assertSelectorExists('.alert-error');
        self::assertSelectorExists('input[name="_csrf_token"]', 'The re-render must include a fresh CSRF token so the human can simply resubmit.');
        self::assertSame([], $this->asyncTransport()->getSent(), 'No magic-link request may be queued without a valid CSRF token — this is the layer that stops bots which never fetch the form.');
    }

    #[Test]
    public function honeypotTrippedSubmissionPretendsSuccessAndSendsNothing(): void
    {
        $client = self::createClient();

        $this->postLogin($client, 'honeypot-victim@example.com', ['website' => 'https://spam.example']);

        self::assertResponseRedirects('/login/check-email', 302, 'A honeypot-tripped submission must get the same redirect as a real one — an error response would teach the bot operator which layer fired.');
        self::assertSame([], $this->asyncTransport()->getSent(), 'A honeypot-tripped submission must never queue mail — the login form would remain a spam cannon otherwise.');
    }

    #[Test]
    public function submissionWithoutRenderTimestampPretendsSuccessAndSendsNothing(): void
    {
        $client = self::createClient();

        $this->postLogin($client, 'no-timestamp@example.com', ['renderedAt' => null]);

        self::assertResponseRedirects('/login/check-email');
        self::assertSame([], $this->asyncTransport()->getSent(), 'A POST without the render timestamp means the form was never loaded — exactly how the July 2026 campaign operated. No mail may go out.');
    }

    #[Test]
    public function submissionFasterThanAHumanPretendsSuccessAndSendsNothing(): void
    {
        $client = self::createClient();
        $clock = self::getContainer()->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        $this->postLogin($client, 'too-fast@example.com', [
            'renderedAt' => (string) $clock->now()->getTimestamp(),
        ]);

        self::assertResponseRedirects('/login/check-email');
        self::assertSame([], $this->asyncTransport()->getSent(), 'A submission arriving faster than a human can act is scripted — pretend success, send nothing.');
    }

    #[Test]
    public function perIpRateLimitSilentlyDropsRequestsPastTheCap(): void
    {
        $client = self::createClient();
        $token = $this->harvestCsrfToken($client);

        for ($i = 1; $i <= 10; ++$i) {
            $client->request('POST', '/login', [
                'email' => 'burst-'.$i.'@example.com',
                '_csrf_token' => $token,
                'renderedAt' => (string) $this->pastTimestamp(),
                'website' => '',
            ]);
            self::assertResponseRedirects('/login/check-email');
        }

        self::assertCount(1, $this->asyncTransport()->getSent(), 'The 10th request from one IP within an hour is still legitimate — an office behind one NAT must fit under the cap.');

        $client->request('POST', '/login', [
            'email' => 'burst-11@example.com',
            '_csrf_token' => $token,
            'renderedAt' => (string) $this->pastTimestamp(),
            'website' => '',
        ]);

        self::assertResponseRedirects('/login/check-email', 302, 'The over-cap request must get the same redirect as an accepted one — announcing "rate limited" would hand the operator the exact budget to stay under.');
        self::assertSame([], $this->asyncTransport()->getSent(), 'The 11th request from one IP within an hour must be silently dropped — one source walking an address list is the abuse signature.');
    }

    #[Test]
    public function checkEmailWithoutPendingEmailRedirectsToLogin(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login/check-email');

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function submitInvalidEmailReturns422WithError(): void
    {
        $client = self::createClient();

        $this->postLogin($client, 'not-an-email');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function submitEmptyEmailReturns422WithError(): void
    {
        $client = self::createClient();

        $this->postLogin($client, '');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function verifyValidTokenLogsInExistingUser(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Create user + team + membership
        $userId = Uuid::uuid7();
        $email = 'auth-existing-'.$userId->toString().'@example.com';
        $user = new User(
            id: $userId,
            email: $email,
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Test Team',
            slug: 'auth-test-'.substr($teamId->toString(), 0, 8),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);

        // Create valid token
        $tokenString = bin2hex(random_bytes(32));
        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: $email,
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('+15 minutes'),
            createdAt: new \DateTimeImmutable(),
            user: $user,
        );
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/login/verify/'.$tokenString);

        self::assertResponseRedirects('/app');
    }

    #[Test]
    public function verifyValidTokenCreatesNewUserAndRedirectsToOnboarding(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $email = 'auth-new-'.Uuid::uuid7()->toString().'@example.com';
        $tokenString = bin2hex(random_bytes(32));

        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: $email,
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('+15 minutes'),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/login/verify/'.$tokenString);

        self::assertResponseRedirects('/app/onboarding/team');

        // Verify user was created
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        // Verify team was created
        $memberships = $em->getRepository(TeamMembership::class)->findBy(['user' => $user->id->toString()]);
        self::assertCount(1, $memberships);
        self::assertSame(TeamRole::Owner, $memberships[0]->role);
    }

    #[Test]
    public function verifyExpiredTokenShowsError(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $tokenString = bin2hex(random_bytes(32));
        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: 'expired@example.com',
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('-1 hour'),
            createdAt: new \DateTimeImmutable('-2 hours'),
        );
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/login/verify/'.$tokenString);

        self::assertResponseRedirects('/login/failed');
    }

    #[Test]
    public function verifyUsedTokenShowsError(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $tokenString = bin2hex(random_bytes(32));
        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: 'used@example.com',
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('+15 minutes'),
            createdAt: new \DateTimeImmutable(),
            usedAt: new \DateTimeImmutable(),
        );
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/login/verify/'.$tokenString);

        self::assertResponseRedirects('/login/failed');
    }

    #[Test]
    public function verifyInvalidTokenShowsError(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login/verify/nonexistenttoken');

        self::assertResponseRedirects('/login/failed');
    }

    #[Test]
    public function dashboardWithoutAuthRedirectsToLogin(): void
    {
        $client = self::createClient();

        $client->request('GET', '/app');

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function loginFailedPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login/failed');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function authenticatedUserOnLoginPageRedirectsToDashboard(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Create user with completed onboarding
        $userId = Uuid::uuid7();
        $email = 'already-auth-'.$userId->toString().'@example.com';
        $user = new User(
            id: $userId,
            email: $email,
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Auth Team',
            slug: 'already-auth-'.substr($teamId->toString(), 0, 8),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);
        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/login');
        self::assertResponseRedirects('/app');
    }

    /**
     * Submits the login form the way a legitimate browser would: with the
     * CSRF token harvested from the rendered page, a render timestamp far
     * enough in the past to clear the time-trap, and an empty honeypot.
     * Pass an override of null to omit a field entirely.
     *
     * @param array<string, string|null> $overrides
     */
    private function postLogin(KernelBrowser $client, string $email, array $overrides = []): void
    {
        $fields = array_merge([
            'email' => $email,
            '_csrf_token' => $this->harvestCsrfToken($client),
            'renderedAt' => (string) $this->pastTimestamp(),
            'website' => '',
        ], $overrides);

        $client->request('POST', '/login', array_filter($fields, static fn (?string $value) => null !== $value));
    }

    private function harvestCsrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }

    /**
     * A "renderedAt" the controller will treat as past the time-trap window.
     */
    private function pastTimestamp(): int
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        return $clock->now()->getTimestamp() - 5;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
