<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\TeamInvitation;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\TeamInvitationStatus;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Verifies the top-bar "+ Add" dropdown (TASK-033) is rendered as the default
 * `header_actions` block on every authenticated dashboard page, and that each
 * of its three items honours the right plan-limit / role gate.
 *
 * The dropdown is the SOLE Add-affordance on the top bar — the previous
 * per-page "+ Add domain" / "+ Add mailbox" overrides have been removed and
 * are now provided by this single component everywhere.
 */
final class GlobalAddDropdownTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function dashboardUrlProvider(): iterable
    {
        yield 'overview' => ['/app'];
        yield 'reports' => ['/app/reports'];
        yield 'alerts' => ['/app/alerts'];
        yield 'billing' => ['/app/settings/billing'];
        yield 'team' => ['/app/team'];
        yield 'quarantine' => ['/app/quarantine'];
    }

    #[Test]
    #[DataProvider('dashboardUrlProvider')]
    public function dropdownRendersOnEveryDashboardPage(string $url): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        // Pro plan: multi-seat (3 max), 20 domains, so all three items render
        // in their enabled / linked form on every page.
        $persona = $fixtures->persona()
            ->emailPrefix('global-add-rendering')
            ->plan('pro')
            ->build();

        $client->loginUser($persona->user);
        $client->request('GET', $url);

        self::assertResponseIsSuccessful(sprintf('Expected 200 on %s, got %d', $url, $client->getResponse()->getStatusCode()));

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/app/domains/add', $body, sprintf('Domain-add link missing on %s', $url));
        self::assertStringContainsString('/app/mailboxes/add', $body, sprintf('Mailbox-add link missing on %s', $url));
        self::assertStringContainsString('/app/team', $body, sprintf('Team-settings link missing on %s', $url));
    }

    #[Test]
    public function addDomainItemEnabledUnderLimit(): void
    {
        // Free plan: 1 max domain. Default persona has 1 domain — already at cap.
        // Use plan 'pro' (20 max) so 1 domain is well under the limit.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('pro')->build();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/app/domains/add', $body);
        // The "Limit reached" copy must NOT appear for the domain item when there's headroom.
        // (It could still appear for other items in different setups, but the Pro plan
        // gives 3-seat headroom and 20-domain headroom, so neither item is in the limit state.)
        self::assertStringNotContainsString('Limit reached', $body);
    }

    #[Test]
    public function addDomainItemDisabledAtLimit(): void
    {
        // Free plan = 1 domain max; default persona has 1 domain already.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()
            ->emailPrefix('domain-cap')
            ->plan('free')
            ->build();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        // Disabled-state copy is present.
        self::assertStringContainsString('Limit reached', $body);
        // Upgrade link to billing must point to the canonical billing URL.
        self::assertStringContainsString('/app/settings/billing', $body);
    }

    #[Test]
    public function addMailboxItemAlwaysEnabled(): void
    {
        // Even on Free plan with everything else maxed out, the mailbox item
        // is always an active link — there is no plan limit on mailboxes.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()
            ->emailPrefix('mailbox-always')
            ->plan('free')
            ->build();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/app/mailboxes/add"]');
    }

    #[Test]
    public function inviteTeammateItemHiddenForMemberRole(): void
    {
        // A teammate with Member role on someone else's team should never see
        // the "Invite teammate" item — even with headroom, members can't invite.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $owner = $fixtures->persona()
            ->emailPrefix('member-hidden-owner')
            ->plan('pro')
            ->build();
        $memberUser = $fixtures->addExtraTeammate($owner->team, TeamRole::Member);

        $client->loginUser($memberUser);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Invite teammate', $body);
    }

    #[Test]
    public function inviteTeammateItemEnabledForAdminUnderLimit(): void
    {
        // Pro plan = 3 seats max. Owner + 1 admin = 2 seats. Admin can invite.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $owner = $fixtures->persona()
            ->emailPrefix('admin-invite')
            ->plan('pro')
            ->build();
        $admin = $fixtures->addExtraTeammate($owner->team, TeamRole::Admin);

        $client->loginUser($admin);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Invite teammate', $body);
        // The link is to /app/team — the team settings page that hosts the invite form.
        self::assertStringContainsString('/app/team', $body);
        // Seat counter copy reflects the 2/3 effective seats.
        self::assertStringContainsString('2/3 seats', $body);
    }

    #[Test]
    public function inviteTeammateItemDisabledForAdminAtMemberCap(): void
    {
        // Pro plan = 3 seats max. Owner + 1 member + 1 pending invite = 3 effective.
        // Owner is at the cap.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $owner = $fixtures->persona()
            ->emailPrefix('cap-owner')
            ->plan('pro')
            ->build();
        $fixtures->addExtraTeammate($owner->team, TeamRole::Member);

        // One pending invitation pushes effective count from 2 → 3 = cap.
        $invitation = new TeamInvitation(
            id: Uuid::uuid7(),
            team: $owner->team,
            invitedEmail: 'pending-'.Uuid::uuid7()->toString().'@example.com',
            invitedBy: $owner->user,
            role: TeamRole::Member,
            invitationToken: bin2hex(random_bytes(32)),
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        // Status defaults to Pending in constructor — assert that's still the case.
        self::assertSame(TeamInvitationStatus::Pending, $invitation->status);
        $em->persist($invitation);
        $em->flush();

        $client->loginUser($owner->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        // Item still renders (manager role), but in the disabled "Limit reached" state.
        self::assertStringContainsString('Invite teammate', $body);
        self::assertStringContainsString('Limit reached', $body);
        // Upgrade link to billing must be present.
        self::assertStringContainsString('/app/settings/billing', $body);
    }

    #[Test]
    public function dropdownAbsentForUnauthenticatedRequest(): void
    {
        // No login → /app redirects to /login. The dropdown extension's null-
        // state fallback must keep the request from blowing up.
        $client = self::createClient();
        $client->request('GET', '/app');

        self::assertResponseRedirects();
        // Response is a redirect — the dropdown HTML is not in the body.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('aria-label="Add"', $body);
    }

    #[Test]
    public function extensionRendersOnPublicPagesWithoutAuthenticatedUser(): void
    {
        // Twig globals are computed on every render, including public marketing
        // pages where there is no authenticated user. The extension's null-state
        // early return must keep the public homepage from blowing up.
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        // The dropdown is only rendered inside the dashboard layout, so the
        // homepage must not contain it — but the request must succeed (no
        // 500 from a Twig render failure inside the extension).
        self::assertStringNotContainsString('aria-label="Add"', $body);
    }

    #[Test]
    public function extensionFallsBackToNullStateForAuthenticatedUserWithoutMemberships(): void
    {
        // A user without a team membership shouldn't normally reach a dashboard
        // page — OnboardingRedirectListener intercepts them earlier — but the
        // extension's try/catch around DashboardContext is the safety net for
        // any Twig render that escapes that gate (e.g. error pages rendered
        // late in the kernel cycle). Verify it doesn't blow up.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = new \App\Entity\User(
            id: Uuid::uuid7(),
            email: 'no-membership-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: null,
        );
        $user->popEvents();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        // Hit the public homepage so the globals fire on a real Twig render.
        // The /app routes would redirect to onboarding before any render, so
        // we use / where the homepage renders and the extension still computes
        // its globals.
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('aria-label="Add"', $body);
    }
}
