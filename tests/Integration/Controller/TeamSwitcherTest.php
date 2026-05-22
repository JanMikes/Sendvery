<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\TeamMembership;
use App\Services\DashboardContext;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Multi-team UX: the active team is persisted in the session; the sidebar
 * dropdown swaps it; mutations target the active team; a user can only
 * switch to teams they actually belong to.
 */
final class TeamSwitcherTest extends WebTestCase
{
    #[Test]
    public function singleTeamUserSeesStaticTeamName(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        // Sidebar shows the team name; no dropdown menu.
        self::assertSelectorTextContains('aside', $persona->team->name);
        self::assertSelectorNotExists('aside label.btn[tabindex="0"]');
    }

    #[Test]
    public function multiTeamUserSeesDropdown(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $personaA = $fixtures->persona()->emailPrefix('multi-a')->teamName('Acme Inc')->build();
        $personaB = $fixtures->persona()->emailPrefix('multi-b')->teamName('Beta Corp')->build();

        // Make personaA a member of Beta Corp too.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $personaA->user,
            team: $personaB->team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $client->loginUser($personaA->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        // Sidebar dropdown lists both teams.
        self::assertSelectorTextContains('aside', 'Acme Inc');
        self::assertSelectorTextContains('aside', 'Beta Corp');
        // Each team has a switch form posting to the switcher endpoint.
        self::assertSelectorExists('aside form[action="/app/team/switch"]');
    }

    #[Test]
    public function switchingActiveTeamUpdatesTheSession(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $personaA = $fixtures->persona()->emailPrefix('switch-a')->teamName('Acme Inc')->build();
        $personaB = $fixtures->persona()->emailPrefix('switch-b')->teamName('Beta Corp')->build();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $personaA->user,
            team: $personaB->team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $client->loginUser($personaA->user);

        // Before switching: team_settings shows the default (sorted-first) team.
        $client->request('GET', '/app/team');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Acme Inc');

        // Switch to Beta Corp.
        $client->request('POST', '/app/team/switch', [
            'team_id' => $personaB->team->id->toString(),
            'return_to' => '/app/team',
        ]);
        self::assertResponseRedirects('/app/team');
        $client->followRedirect();

        // After switching: team_settings shows Beta Corp.
        self::assertSelectorTextContains('h1', 'Beta Corp');
    }

    #[Test]
    public function switchingToNonMemberTeamIs404(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->emailPrefix('hostile')->build();
        $strangersTeam = $fixtures->persona()->emailPrefix('stranger')->build();

        $client->loginUser($persona->user);
        $client->request('POST', '/app/team/switch', [
            'team_id' => $strangersTeam->team->id->toString(),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function switchingWithGarbageIdIs404(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $client->loginUser($persona->user);
        $client->request('POST', '/app/team/switch', ['team_id' => 'not-a-uuid']);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function returnToOnlyHonouredForSameOriginAppPaths(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $client->loginUser($persona->user);

        // External / non-/app path → ignored, falls back to dashboard.
        $client->request('POST', '/app/team/switch', [
            'team_id' => $persona->team->id->toString(),
            'return_to' => 'https://attacker.example/steal',
        ]);

        self::assertResponseRedirects('/app');
    }

    #[Test]
    public function invitingDefaultsToActiveTeamLabelledClearly(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        // Business plan so invite form actually renders (single-seat plans
        // show the upgrade prompt and skip the form).
        $persona = $fixtures->persona()->plan('business')->teamName('Acme Inc')->build();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
        // The "Inviting to {team}" label must name the active team explicitly.
        self::assertSelectorTextContains('body', 'Inviting to');
        self::assertSelectorTextContains('body', 'Acme Inc');
    }

    #[Test]
    public function addDomainPageLabelsTheTargetTeam(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->teamName('Acme Inc')->build();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains/add');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Adding to');
        self::assertSelectorTextContains('body', 'Acme Inc');
    }

    #[Test]
    public function domainsListShowsTeamChipForMultiTeamUser(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $personaA = $fixtures->persona()->emailPrefix('multi-list-a')->teamName('Acme Inc')->build();
        $personaB = $fixtures->persona()->emailPrefix('multi-list-b')->teamName('Beta Corp')->build();
        assert(null !== $personaA->domain && null !== $personaB->domain);

        // personaA is a member of Beta Corp too.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $personaA->user,
            team: $personaB->team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $client->loginUser($personaA->user);
        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        // Both domains visible (aggregated read scope) — and each card shows
        // which team owns it, since the user belongs to more than one. Use
        // the full-page text rather than `.card` directly because
        // assertSelectorTextContains only inspects the first match.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($personaA->domain->domain, $body);
        self::assertStringContainsString($personaB->domain->domain, $body);
        self::assertStringContainsString('Acme Inc', $body);
        self::assertStringContainsString('Beta Corp', $body);
    }

    #[Test]
    public function domainsListHidesTeamChipForSingleTeamUser(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $solo = $fixtures->persona()->emailPrefix('solo-list')->teamName('Solo LLC')->build();

        $client->loginUser($solo->user);
        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        // A single-team user shouldn't see the team name repeated on every
        // card — the sidebar chip already shows it. Confined to the .card so
        // the sidebar's "Solo LLC" doesn't false-positive the assertion.
        self::assertSelectorTextNotContains('.card', 'Solo LLC');
    }

    #[Test]
    public function dashboardContextFallsBackToFirstMembershipWhenSessionEmpty(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        // Login but never switch — the dashboard context resolves the first
        // membership, mutations land on it, and the page renders.
        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $persona->team->name);
    }

    #[Test]
    public function dashboardContextIgnoresStaleSessionTeamId(): void
    {
        // If the session points at a team the user no longer belongs to
        // (e.g. they were removed from it after login), the context must
        // silently fall back to a team they DO belong to. Otherwise we'd
        // either crash on every page load or leak data they can't see.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        // Plant a stale active-team-id in the session.
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set(DashboardContext::SESSION_KEY, Uuid::uuid7()->toString());
        $session->save();
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));

        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        // Falls back to the user's actual team, not a 500 or someone else's team.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $persona->team->name);
    }
}
