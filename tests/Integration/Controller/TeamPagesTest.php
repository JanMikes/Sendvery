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
}
