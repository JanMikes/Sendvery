<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Onboarding;

use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * The onboarding domain step renames the team's newest domain in place rather
 * than appending a second row, so someone can correct a typo in the domain they
 * just typed.
 *
 * That is only safe while the domain is unambiguously *theirs*. Accepting a team
 * invitation does not set `onboardingCompletedAt`, so an invited teammate is
 * walked through onboarding against a team that already monitors something —
 * and the stepper links step 2. `findLatestForTeam` then hands them a colleague's
 * domain, and submitting any other name re-pointed that domain — keeping its id,
 * its DMARC reports, its alerts and its DNS history — at a name its owner never
 * chose. The original domain simply stopped being monitored, since inbound
 * reports route by name.
 */
final class OnboardingDomainRenameGuardTest extends WebTestCase
{
    #[Test]
    public function anInvitedTeammateCannotRenameTheTeamsExistingDomain(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $owner = $fixtures->persona()->withDomain('acme.example')->build();
        assert(null !== $owner->domain);
        $teamDomain = $owner->domain->domain;

        // A teammate mid-onboarding: invited, joined, never finished setup.
        $teammate = $fixtures->addExtraTeammate($owner->team, TeamRole::Member);
        $teammate->onboardingCompletedAt = null;
        $this->flush();
        $client->loginUser($teammate);

        $client->request('POST', '/app/onboarding/domain', ['domain_name' => 'teammate-own.example']);

        self::assertSame(
            $teamDomain,
            $this->reloadDomain($owner)->domain,
            "A teammate's submission must never rename a domain the team already monitors.",
        );
    }

    #[Test]
    public function theTeamsDomainKeepsMonitoringItsOwnReportsAfterATeammateSubmits(): void
    {
        // The rename kept the row id, so nothing looked deleted — the damage was
        // that every report, alert and DNS check hanging off that id silently
        // started describing a different domain name.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $owner = $fixtures->persona()->withDomain('acme.example')->build();
        assert(null !== $owner->domain);
        $originalId = $owner->domain->id->toString();
        $originalName = $owner->domain->domain;

        $teammate = $fixtures->addExtraTeammate($owner->team, TeamRole::Member);
        $teammate->onboardingCompletedAt = null;
        $this->flush();
        $client->loginUser($teammate);

        $client->request('POST', '/app/onboarding/domain', ['domain_name' => 'somewhere-else.example']);

        $reloaded = $this->reloadDomain($owner);
        self::assertSame($originalId, $reloaded->id->toString(), 'The row is the same row.');
        self::assertSame($originalName, $reloaded->domain, 'And it still carries the name its own history belongs to.');
    }

    #[Test]
    public function theTeammateIsToldWhyTheirSubmissionChangedNothing(): void
    {
        // Refusing the rename silently would look like the form accepted the
        // entry, which is its own kind of lie.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $owner = $fixtures->persona()->withDomain('acme.example')->build();
        assert(null !== $owner->domain);

        $teammate = $fixtures->addExtraTeammate($owner->team, TeamRole::Member);
        $teammate->onboardingCompletedAt = null;
        $this->flush();
        $client->loginUser($teammate);

        $crawler = $client->request('POST', '/app/onboarding/domain', ['domain_name' => 'teammate-own.example']);

        self::assertResponseIsSuccessful();
        $notice = $crawler->filter('[data-testid="team-domain-already-set"]');
        self::assertCount(1, $notice, 'The teammate must be told their team already has this covered.');
        self::assertStringContainsString(
            $owner->domain->domain,
            $notice->text(),
            'And which domain the team is already monitoring.',
        );
    }

    #[Test]
    public function aSoleMemberCanStillCorrectATypoInTheDomainTheyJustEntered(): void
    {
        // The reason the rename exists. Guarding it must not remove it.
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())
            // Order matters: notOnboarded() clears the domain, withDomain() re-adds it.
            ->persona()->notOnboarded()->withDomain('acme.example')->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $client->request('POST', '/app/onboarding/domain', ['domain_name' => 'corrected.example']);

        self::assertSame(
            'corrected.example',
            $this->reloadDomain($persona)->domain,
            'A sole member is unambiguously renaming their own entry.',
        );
    }

    #[Test]
    public function resubmittingTheSameDomainIsAcceptedQuietly(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $owner = $fixtures->persona()->withDomain('acme.example')->build();
        assert(null !== $owner->domain);
        $existingName = $owner->domain->domain;

        $teammate = $fixtures->addExtraTeammate($owner->team, TeamRole::Member);
        $teammate->onboardingCompletedAt = null;
        $this->flush();
        $client->loginUser($teammate);

        $client->request('POST', '/app/onboarding/domain', ['domain_name' => $existingName]);

        self::assertResponseRedirects('/app/onboarding/domain');
        self::assertSame($existingName, $this->reloadDomain($owner)->domain);
    }

    private function flush(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->flush();
    }

    private function reloadDomain(Persona $persona): MonitoredDomain
    {
        assert(null !== $persona->domain);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        assert($domain instanceof MonitoredDomain);

        return $domain;
    }
}
