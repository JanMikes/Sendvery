<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * TASK-096 coverage for the onboarding ingestion step.
 *
 * Two invariants this test class locks:
 *  1. DOM order — the DNS-based path heading text strictly precedes the
 *     mailbox path heading text. Pins the visual hierarchy at CI time so a
 *     future refactor of the template can't silently flip the order.
 *  2. Skip-when-PointsAtSendvery — when the team's primary domain's latest
 *     DnsCheckResult already publishes a rua= pointing at Sendvery, the
 *     ingestion step bypasses to /app/onboarding/complete. Bypasses friction
 *     for users whose DNS was correct before they finished signing up.
 */
final class OnboardingIngestionStepTest extends WebTestCase
{
    #[Test]
    public function dnsPathHeadingRendersStrictlyBeforeMailboxPathHeading(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $this->makeFreshUser($em, withDomain: true);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/app/onboarding/ingestion');

        self::assertResponseIsSuccessful();

        $html = $client->getResponse()->getContent();
        self::assertIsString($html);
        $dnsHeading = 'Forward reports to us via DNS';
        $mailboxHeading = 'Connect a mailbox via IMAP';

        $dnsPos = strpos($html, $dnsHeading);
        $mailboxPos = strpos($html, $mailboxHeading);

        self::assertNotFalse($dnsPos, 'DNS path heading must render.');
        self::assertNotFalse($mailboxPos, 'Mailbox path heading must render.');
        self::assertLessThan(
            $mailboxPos,
            $dnsPos,
            'DNS-based path heading must appear strictly before the mailbox path heading.',
        );
    }

    #[Test]
    public function dnsPathHasRecommendedBadgeAndMailboxIsCollapsed(): void
    {
        // Visual-hierarchy sanity check: the DNS card must be the visually
        // primary option (Recommended badge) and the mailbox card must be
        // collapsed-by-default inside a <details> element (the de-emphasized
        // shape TASK-090/091 require to keep the recommendation hierarchy
        // intact across surfaces).
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $this->makeFreshUser($em, withDomain: true);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/app/onboarding/ingestion');

        self::assertResponseIsSuccessful();

        // The Recommended badge appears in the DNS card only.
        self::assertGreaterThan(
            0,
            $crawler->filter('.badge:contains("Recommended")')->count(),
            'DNS path must carry the Recommended badge.',
        );
        // The mailbox card is a <details> (collapsed by default).
        self::assertGreaterThan(
            0,
            $crawler->filter('details')->count(),
            'Mailbox path must render inside a collapsed <details>.',
        );
    }

    #[Test]
    public function ingestionStepIsSkippedWhenDmarcAlreadyPointsAtSendvery(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $this->makeFreshUser($em, withDomain: true);

        $membership = $em->getRepository(TeamMembership::class)->findOneBy(['user' => $user->id->toString()]);
        self::assertNotNull($membership);
        $domain = $em->getRepository(MonitoredDomain::class)->findOneBy(['team' => $membership->team->id->toString()]);
        self::assertNotNull($domain);

        // Persist a DnsCheckResult whose rua= tag points at the test report
        // address — RuaScenarioResolver reads the latest stored check, no
        // live DNS lookup, so this is all it takes to short-circuit the page.
        $this->persistDmarcCheck(
            $em,
            $domain,
            rawRecord: 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test',
        );

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion');

        self::assertResponseRedirects('/app/onboarding/complete');
    }

    #[Test]
    public function ingestionStepRendersWhenDmarcPointsAtExternalAddress(): void
    {
        // Negative control: the skip only fires for PointsAtSendvery, not
        // for any record that exists. A rua= pointing somewhere else should
        // keep the user on the step so they can decide what to do.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $this->makeFreshUser($em, withDomain: true);

        $membership = $em->getRepository(TeamMembership::class)->findOneBy(['user' => $user->id->toString()]);
        self::assertNotNull($membership);
        $domain = $em->getRepository(MonitoredDomain::class)->findOneBy(['team' => $membership->team->id->toString()]);
        self::assertNotNull($domain);

        $this->persistDmarcCheck(
            $em,
            $domain,
            rawRecord: 'v=DMARC1; p=none; rua=mailto:reports@external.example',
        );

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function ingestionStepRendersWhenNoDnsCheckRunYet(): void
    {
        // The skip only fires once the domain has been DNS-checked at least
        // once and the check shows PointsAtSendvery. A brand-new domain with
        // no stored check stays on the page so the user can see what to do.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $this->makeFreshUser($em, withDomain: true);

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function postingForwardChoiceRedirectsToCompleteRegardlessOfDnsState(): void
    {
        // POST handler is independent of the skip logic — the user can
        // always commit to "I'll publish a DMARC record" without the
        // controller running the rua= scenario check.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $this->makeFreshUser($em, withDomain: true);

        $client->loginUser($user);
        $client->request('POST', '/app/onboarding/ingestion', ['method' => 'forward']);

        self::assertResponseRedirects('/app/onboarding/complete');
    }

    private function makeFreshUser(EntityManagerInterface $em, bool $withDomain): User
    {
        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'ingest-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingTeamCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Ingest team',
            slug: 'ingest-'.substr($teamId->toString(), 0, 8),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        if ($withDomain) {
            $domain = new MonitoredDomain(
                id: Uuid::uuid7(),
                team: $team,
                domain: 'ingest-'.substr($teamId->toString(), 0, 8).'.example',
                createdAt: new \DateTimeImmutable(),
            );
            $domain->popEvents();
            $em->persist($domain);
        }

        $em->flush();

        return $user;
    }

    private function persistDmarcCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        string $rawRecord,
    ): void {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();
    }
}
