<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TeamPagesTest extends WebTestCase
{
    #[Test]
    public function teamSettingsReturns200ForOwnerWithMultipleMembers(): void
    {
        // Regression: the "Transfer ownership" block (gated on `canTransfer and
        // members|length > 1`) used the removed Twig `for ... if` syntax and
        // crashed whenever the owner viewed a team with at least one teammate.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('team')->build();
        $member = $fixtures->addExtraTeammate($persona->team);

        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Transfer ownership');
        self::assertSelectorTextContains('body', $member->email);
    }

    #[Test]
    public function teamSettingsReturns200ForSoloOwner(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function freePlanHidesInviteFormAndShowsUpgradePrompt(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('free')->build();

        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
        // Plan summary visible at the top.
        self::assertSelectorTextContains('body', 'Free plan');
        self::assertSelectorTextContains('body', 'Team members:');
        self::assertSelectorTextContains('body', '1/1');
        // No invite form.
        self::assertSelectorNotExists('input[type="email"][name="email"]');
        // Upgrade CTA instead.
        self::assertSelectorTextContains('body', 'Upgrade to Team');
        self::assertSelectorExists('a[href*="request-access"]');
    }

    #[Test]
    public function personalPlanAlsoShowsUpgradePromptInsteadOfInviteForm(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        // Personal is also a single-seat plan — no teammates.
        $persona = $fixtures->persona()->plan('personal')->build();

        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Personal plan');
        self::assertSelectorNotExists('input[type="email"][name="email"]');
        self::assertSelectorTextContains('body', 'Upgrade to Team');
    }

    #[Test]
    public function teamPlanWithHeadroomShowsInviteFormAndCounter(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('team')->build();

        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Team plan');
        self::assertSelectorTextContains('body', '1/10');
        // Invite form present.
        self::assertSelectorExists('input[type="email"][name="email"]');
    }

    #[Test]
    public function teamPlanAtMemberCapShowsLimitReachedPrompt(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        // Owner + 9 teammates = 10 = Team plan cap.
        $persona = $fixtures->persona()->plan('team')->build();
        for ($i = 0; $i < 9; ++$i) {
            $fixtures->addExtraTeammate($persona->team);
        }

        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '10/10');
        // No invite form once we're at the cap.
        self::assertSelectorNotExists('input[type="email"][name="email"]');
        self::assertSelectorTextContains('body', "reached your plan's team-member limit");
    }

    #[Test]
    public function pendingInvitationsCountTowardTheCap(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()->plan('team')->build();
        // 1 owner + 8 teammates + 1 pending invitation = 10 effective = cap.
        for ($i = 0; $i < 8; ++$i) {
            $fixtures->addExtraTeammate($persona->team);
        }
        $em->persist(new \App\Entity\TeamInvitation(
            id: \Ramsey\Uuid\Uuid::uuid7(),
            team: $persona->team,
            invitedEmail: 'pending-'.\Ramsey\Uuid\Uuid::uuid7()->toString().'@example.com',
            invitedBy: $persona->user,
            role: \App\Value\TeamRole::Member,
            invitationToken: bin2hex(random_bytes(32)),
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', '/app/team');

        self::assertResponseIsSuccessful();
        // Counter shows 10/10 (9 members + 1 pending).
        self::assertSelectorTextContains('body', '10/10');
        self::assertSelectorTextContains('body', '1 pending');
        // Invite form hidden.
        self::assertSelectorNotExists('input[type="email"][name="email"]');
    }
}
