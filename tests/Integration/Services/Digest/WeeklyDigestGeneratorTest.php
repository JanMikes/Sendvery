<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Digest;

use App\Entity\Alert;
use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\SenderIdentity;
use App\Entity\Team;
use App\Entity\User;
use App\Services\Digest\WeeklyDigestGenerator;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use App\Value\SenderRole;
use App\Value\WeeklyDigestDomainData;
use App\Value\WeeklyDigestNewSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class WeeklyDigestGeneratorTest extends IntegrationTestCase
{
    #[Test]
    public function surfacesCurrentlyBrokenDnsInDigest(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        // Older check that was healthy
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dkim,
            checkedAt: new \DateTimeImmutable('-2 days'),
            rawRecord: 'v=DKIM1; k=rsa; p=ABC',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        ));

        // Most recent check is invalid — should surface
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dkim,
            checkedAt: new \DateTimeImmutable('-1 hour'),
            rawRecord: null,
            isValid: false,
            issues: [['severity' => 'warning', 'message' => 'CNAME points to nonexistent target', 'recommendation' => 'Fix CNAME']],
            details: [],
            previousRawRecord: 'v=DKIM1; k=rsa; p=ABC',
            hasChanged: true,
        ));

        $em->flush();

        $digest = $generator->generate($team);

        self::assertCount(1, $digest->currentlyBrokenDns);
        self::assertSame('dns-digest-test.com', $digest->currentlyBrokenDns[0]->domainName);
        self::assertSame('DKIM', $digest->currentlyBrokenDns[0]->checkType);
        self::assertContains('CNAME points to nonexistent target', $digest->currentlyBrokenDns[0]->issueMessages);
    }

    #[Test]
    public function excludesRecordsThatRecoveredOnLatestCheck(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable('-1 day'),
            rawRecord: null,
            isValid: false,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        ));

        // Latest check is healthy — must NOT appear in currentlyBrokenDns
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable('-1 hour'),
            rawRecord: 'v=spf1 ~all',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: true,
        ));

        $em->flush();

        $digest = $generator->generate($team);

        self::assertCount(0, $digest->currentlyBrokenDns);
    }

    #[Test]
    public function aDomainThatReceivedNothingThisWeekHasNoPassRateRatherThanZero(): void
    {
        [$team, , $em] = $this->createTeamAndDomain();
        $em->flush();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $digest = $generator->generate($team);

        self::assertCount(1, $digest->domains);
        self::assertNull(
            $digest->domains[0]->passRate,
            'No reports must mean no pass rate — 0% would read as "every message failed".',
        );
        self::assertNull(
            $digest->overallPassRate(),
            'With no reporting domain at all there is no rate to state.',
        );
    }

    #[Test]
    public function repeatedAlertsOfTheSameKindOnOneDomainCollapseIntoOneGroup(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $this->persistAlerts($em, $team, $domain, AlertType::NewUnknownSender, AlertSeverity::Warning, count: 9);
        $em->flush();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $digest = $generator->generate($team);

        self::assertCount(1, $digest->attentionAlerts);
        self::assertSame(9, $digest->attentionAlerts[0]->occurrences);
        self::assertSame(9, $digest->alertsCount, 'The headline count still reflects every individual alert.');
        self::assertSame(1, $digest->attentionAlertGroups);
    }

    #[Test]
    public function onlyAHandfulOfAlertGroupsAreCarriedAndTheRestAreCounted(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        foreach ([
            AlertType::DnsRecordChanged,
            AlertType::DnsRecordInvalid,
            AlertType::DnsRecordMissing,
            AlertType::IpBlacklisted,
            AlertType::FailureSpike,
            AlertType::NewUnknownSender,
            AlertType::PolicyRecommendation,
        ] as $type) {
            $this->persistAlerts($em, $team, $domain, $type, AlertSeverity::Warning, count: 1);
        }
        $em->flush();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $digest = $generator->generate($team);

        self::assertCount(WeeklyDigestGenerator::ATTENTION_ALERTS_LIMIT, $digest->attentionAlerts);
        self::assertSame(7, $digest->attentionAlertGroups);
        self::assertTrue($digest->hasMoreAttentionAlerts());
    }

    #[Test]
    public function criticalAlertsComeBeforeMilderOnes(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $this->persistAlerts($em, $team, $domain, AlertType::PolicyRecommendation, AlertSeverity::Info, count: 1);
        $this->persistAlerts($em, $team, $domain, AlertType::NewUnknownSender, AlertSeverity::Warning, count: 1);
        $this->persistAlerts($em, $team, $domain, AlertType::DnsRecordMissing, AlertSeverity::Critical, count: 1);
        $em->flush();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $digest = $generator->generate($team);

        self::assertSame(
            [AlertSeverity::Critical, AlertSeverity::Warning, AlertSeverity::Info],
            array_map(static fn ($alert): AlertSeverity => $alert->severity, $digest->attentionAlerts),
        );
    }

    #[Test]
    public function alertsWhoseProblemIsAlreadyFixedAreCountedAsGoodNewsNotAsOutstandingWork(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $this->persistAlerts(
            $em,
            $team,
            $domain,
            AlertType::DnsRecordMissing,
            AlertSeverity::Critical,
            count: 1,
            resolvedAt: new \DateTimeImmutable('-1 hour'),
        );
        $em->flush();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $digest = $generator->generate($team);

        self::assertSame([], $digest->attentionAlerts, 'A fixed problem is not something the reader must act on.');
        self::assertSame(0, $digest->alertsCount);
        self::assertSame(1, $digest->resolvedAlertsCount);
    }

    #[Test]
    public function goodNewsAlertsAreNeverListedAsProblems(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $this->persistAlerts($em, $team, $domain, AlertType::DnsRecordPublished, AlertSeverity::Success, count: 1);
        $em->flush();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $digest = $generator->generate($team);

        self::assertSame([], $digest->attentionAlerts);
        self::assertSame(0, $digest->alertsCount);
    }

    #[Test]
    public function alertsRaisedBeforeTheWeekStartedAreNotRepeated(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $this->persistAlerts(
            $em,
            $team,
            $domain,
            AlertType::DnsRecordMissing,
            AlertSeverity::Critical,
            count: 1,
            createdAt: new \DateTimeImmutable('-30 days'),
        );
        $em->flush();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $digest = $generator->generate($team);

        self::assertSame([], $digest->attentionAlerts);
    }

    /**
     * The digest's sender section reads real authorization state, so a sender
     * discovered long before this week still counts — the whole reason it is not
     * fed by the "new senders this week" query.
     */
    #[Test]
    public function sendersNobodyHasDecidedAboutAreReportedHoweverLongAgoTheyAppeared(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '77.75.78.89',
            firstSeenAt: new \DateTimeImmutable('-120 days'),
            lastSeenAt: new \DateTimeImmutable('-90 days'),
            totalMessages: 640,
            passRate: 100.0,
            hostname: 'mxb.seznam.cz',
            organization: 'Seznam',
        ));
        $em->flush();

        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($team);

        self::assertSame(1, $digest->sendersAwaitingReviewCount());
        self::assertSame(1, $digest->domains[0]->senderReview->needsReviewCount);
        self::assertSame(640, $digest->domains[0]->senderReview->needsReviewMessages);
        self::assertSame(['Seznam'], $digest->domains[0]->senderReview->topSenderNames);
        self::assertSame(
            [],
            $digest->domains[0]->newSenders,
            'Nothing arrived this week — the review section is independent of the new-sender list.',
        );
    }

    #[Test]
    public function decidedSendersAreNotReportedAsWaitingForADecision(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $user = new User(
            id: Uuid::uuid7(),
            email: 'digest-review-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $authorized = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '203.0.113.71',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 500,
            passRate: 100.0,
        );
        $authorized->authorize($user, new \DateTimeImmutable('-3 days'));
        $em->persist($authorized);

        $rejected = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '203.0.113.72',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 20,
            passRate: 0.0,
        );
        $rejected->markUnknown($user, new \DateTimeImmutable('-3 days'));
        $em->persist($rejected);
        $em->flush();

        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($team);

        self::assertSame(0, $digest->sendersAwaitingReviewCount());
        self::assertFalse($digest->domains[0]->senderReview->hasAny());
    }

    #[Test]
    public function theNamedSendersAreCappedSoOneChattyDomainCannotFloodTheDigest(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        for ($index = 0; $index < WeeklyDigestGenerator::UNREVIEWED_SENDERS_PER_DOMAIN_LIMIT + 3; ++$index) {
            $em->persist(new KnownSender(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                sourceIp: '203.0.113.'.(100 + $index),
                firstSeenAt: new \DateTimeImmutable('-30 days'),
                lastSeenAt: new \DateTimeImmutable('-1 day'),
                totalMessages: 100 - $index,
                passRate: 100.0,
            ));
        }
        $em->flush();

        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($team);
        $review = $digest->domains[0]->senderReview;

        self::assertSame(WeeklyDigestGenerator::UNREVIEWED_SENDERS_PER_DOMAIN_LIMIT + 3, $review->needsReviewCount);
        self::assertCount(WeeklyDigestGenerator::UNREVIEWED_SENDERS_PER_DOMAIN_LIMIT, $review->topSenderNames);
        self::assertTrue($review->hasMoreThanNamed());
        self::assertSame(3, $review->unnamedCount());
        self::assertSame('203.0.113.100', $review->topSenderNames[0], 'Heaviest sender first — that is the one that matters.');
    }

    /**
     * A provider running several outbound machines is one name in the digest, so
     * the reader recognises "Seznam" instead of scrolling past the same word
     * five times.
     */
    #[Test]
    public function severalAddressesBelongingToOneProviderAppearAsOneName(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        foreach (['77.75.78.89', '77.75.78.90', '77.75.78.91'] as $index => $ip) {
            $em->persist(new KnownSender(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                sourceIp: $ip,
                firstSeenAt: new \DateTimeImmutable('-30 days'),
                lastSeenAt: new \DateTimeImmutable('-1 day'),
                totalMessages: 300 - $index,
                passRate: 100.0,
                organization: 'Seznam',
            ));
        }
        $em->flush();

        $review = $this->getService(WeeklyDigestGenerator::class)->generate($team)->domains[0]->senderReview;

        self::assertSame(3, $review->needsReviewCount);
        self::assertSame(['Seznam'], $review->topSenderNames);
        self::assertFalse($review->hasMoreThanNamed());
    }

    /**
     * The number the digest sentence claims to be describing. Two domains, one
     * sending 10 messages that all passed and one sending 47 of which 45 passed:
     * 55 of 57 messages, so 96.5%. Averaging the two domain percentages gives
     * 97.9% and is what shipped — a headline that disagreed with the volume
     * printed beside it and moved by tens of points when a quiet domain sent
     * one message.
     */
    #[Test]
    public function theHeadlinePassRateIsWeightedByHowMuchMailEachDomainSent(): void
    {
        [$team, $quiet, $em] = $this->createTeamAndDomain();
        $busy = $this->addDomain($team, 'busy-digest-test.com');
        $em->flush();

        $this->seedReport($quiet, new \DateTimeImmutable('-2 days'), [
            ['ip' => '198.51.100.1', 'count' => 10, 'passes' => true],
        ]);
        $this->seedReport($busy, new \DateTimeImmutable('-2 days'), [
            ['ip' => '198.51.100.2', 'count' => 45, 'passes' => true],
            ['ip' => '198.51.100.3', 'count' => 2, 'passes' => false],
        ]);
        $em->flush();

        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($team);

        self::assertSame(57, $digest->totalMessages);
        self::assertSame(55, $digest->totalPassedMessages);
        self::assertSame(
            '96.5',
            number_format((float) $digest->overallPassRate(), 1),
            'Fifty-five of fifty-seven messages passed. Averaging the per-domain rates would claim 97.9%.',
        );
    }

    #[Test]
    public function aDomainWithNoTrafficDoesNotDragTheHeadlineDown(): void
    {
        // A domain awaiting its first report contributes no messages, so it must
        // be invisible to the weighted rate rather than counted as a 0% term.
        [$team, $sending, $em] = $this->createTeamAndDomain();
        $this->addDomain($team, 'silent-digest-test.com');
        $em->flush();

        $this->seedReport($sending, new \DateTimeImmutable('-2 days'), [
            ['ip' => '198.51.100.4', 'count' => 20, 'passes' => true],
        ]);
        $em->flush();

        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($team);

        self::assertSame(100.0, $digest->overallPassRate());
    }

    #[Test]
    public function aTrendIsReportedOnlyWhenBothWeeksActuallyMeasuredSomething(): void
    {
        [$team, $compared, $em] = $this->createTeamAndDomain();
        $firstWeek = $this->addDomain($team, 'first-week-digest-test.com');
        $em->flush();

        // Eight of ten passed last week, ten of ten this week.
        $this->seedReport($compared, new \DateTimeImmutable('-10 days'), [
            ['ip' => '198.51.100.10', 'count' => 8, 'passes' => true],
            ['ip' => '198.51.100.11', 'count' => 2, 'passes' => false],
        ]);
        $this->seedReport($compared, new \DateTimeImmutable('-2 days'), [
            ['ip' => '198.51.100.10', 'count' => 10, 'passes' => true],
        ]);

        // Nothing at all last week — a comparison here would invent the number.
        $this->seedReport($firstWeek, new \DateTimeImmutable('-2 days'), [
            ['ip' => '198.51.100.12', 'count' => 10, 'passes' => true],
        ]);
        $em->flush();

        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($team);

        self::assertSame(
            20.0,
            $this->domainNamed($digest->domains, 'dns-digest-test.com')->passRateDelta,
            'Eighty percent last week and a hundred this week is a twenty point improvement.',
        );
        self::assertNull(
            $this->domainNamed($digest->domains, 'first-week-digest-test.com')->passRateDelta,
            'A domain with no previous week has nothing to compare against — not a jump from zero.',
        );
    }

    /**
     * Seznam sends from a rotating pool. Reporting each address separately is
     * what filled the digest with a wall of IPv6 and made an ordinary relay look
     * like a dozen weekly discoveries.
     */
    #[Test]
    public function aProvidersWholeAddressPoolIsOneNewSenderNotOnePerAddress(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $seznamIps = ['77.75.76.89', '77.75.78.89', '2a02:598:1::1', '2a02:598:2::2', '2a02:598:3::3'];

        foreach ($seznamIps as $index => $ip) {
            $this->seedIdentity(
                sourceIp: $ip,
                hostname: sprintf('mxb-%d-908.seznam.cz', $index + 1),
                registrableDomain: 'seznam.cz',
                organization: 'Seznam',
                role: SenderRole::Esp,
            );
        }

        $this->seedReport($domain, new \DateTimeImmutable('-2 days'), array_map(
            static fn (string $ip): array => ['ip' => $ip, 'count' => 10, 'passes' => true],
            $seznamIps,
        ));
        $em->flush();

        $newSenders = $this->getService(WeeklyDigestGenerator::class)->generate($team)->domains[0]->newSenders;

        self::assertCount(1, $newSenders, 'Five machines belonging to one relay are one sender.');
        self::assertSame('Seznam', $newSenders[0]->label);
        self::assertSame(50, $newSenders[0]->messageCount, 'The volume is the whole pool combined.');
        self::assertSame(100.0, $newSenders[0]->passRate());
    }

    /**
     * `cloud-sec-av.com` and `inkyphishfence.com` are not in the curated
     * organisation list and never will be — the list cannot be complete. Their
     * registrable domain is what collapses `eu.`, `ca.` and `us.` into one
     * entry, and it is what the reader sees instead of three raw IPs.
     */
    #[Test]
    public function gatewaysNobodyHasNamedAreStillGroupedAndNamedByTheirDomain(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();

        foreach (['52.212.19.177' => 'eu', '15.222.110.90' => 'ca', '35.174.145.124' => 'us'] as $ip => $region) {
            $this->seedIdentity(
                sourceIp: $ip,
                hostname: $region.'.cloud-sec-av.com',
                registrableDomain: 'cloud-sec-av.com',
                organization: null,
                role: SenderRole::Forwarder,
            );
        }

        $this->seedReport($domain, new \DateTimeImmutable('-2 days'), [
            ['ip' => '52.212.19.177', 'count' => 2, 'passes' => false],
            ['ip' => '15.222.110.90', 'count' => 1, 'passes' => false],
            ['ip' => '35.174.145.124', 'count' => 1, 'passes' => false],
        ]);
        $em->flush();

        $newSenders = $this->getService(WeeklyDigestGenerator::class)->generate($team)->domains[0]->newSenders;

        self::assertCount(1, $newSenders);
        self::assertSame('cloud-sec-av.com', $newSenders[0]->label, 'No raw IP addresses in front of the reader.');
        self::assertSame(
            SenderRole::Forwarder,
            $newSenders[0]->role,
            'A gateway that failed every message must be named a forwarder, not left to look like an attacker.',
        );
        self::assertSame(4, $newSenders[0]->messageCount);
        self::assertSame(0.0, $newSenders[0]->passRate());
    }

    /**
     * An address the team has been receiving mail from on one domain for weeks
     * is not a discovery on a domain they added yesterday.
     */
    #[Test]
    public function aSenderAlreadyKnownOnASiblingDomainIsNotAnnouncedAsNew(): void
    {
        [$team, $established, $em] = $this->createTeamAndDomain();
        $newlyAdded = $this->addDomain($team, 'sibling-digest-test.com');
        $em->flush();

        $this->seedIdentity(
            sourceIp: '77.75.78.89',
            hostname: 'mxb.seznam.cz',
            registrableDomain: 'seznam.cz',
            organization: 'Seznam',
            role: SenderRole::Esp,
        );

        // Long before the digest window, and on a different domain of the team.
        $this->seedReport($established, new \DateTimeImmutable('-24 days'), [
            ['ip' => '77.75.78.89', 'count' => 200, 'passes' => true],
        ]);
        $this->seedReport($newlyAdded, new \DateTimeImmutable('-2 days'), [
            ['ip' => '77.75.78.89', 'count' => 12, 'passes' => true],
        ]);
        $em->flush();

        $digest = $this->getService(WeeklyDigestGenerator::class)->generate($team);
        $sibling = $this->domainNamed($digest->domains, 'sibling-digest-test.com');

        self::assertSame(
            [],
            $sibling->newSenders,
            'The team has known this relay for weeks — a second domain does not make it a new sender.',
        );
    }

    #[Test]
    public function aSenderTheTeamHasNeverSeenAnywhereIsAnnouncedAsNew(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $this->seedReport($domain, new \DateTimeImmutable('-2 days'), [
            ['ip' => '203.0.113.200', 'count' => 3, 'passes' => false],
        ]);
        $em->flush();

        $newSenders = $this->getService(WeeklyDigestGenerator::class)->generate($team)->domains[0]->newSenders;

        self::assertCount(1, $newSenders);
        self::assertSame(
            '203.0.113.200',
            $newSenders[0]->label,
            'An address nothing could resolve is still reported — as itself, rather than dropped.',
        );
        self::assertSame(SenderRole::Unknown, $newSenders[0]->role);
    }

    #[Test]
    public function theBusiestNewSenderIsListedFirst(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $this->seedReport($domain, new \DateTimeImmutable('-2 days'), [
            ['ip' => '203.0.113.201', 'count' => 2, 'passes' => true],
            ['ip' => '203.0.113.202', 'count' => 40, 'passes' => true],
        ]);
        $em->flush();

        $newSenders = $this->getService(WeeklyDigestGenerator::class)->generate($team)->domains[0]->newSenders;

        self::assertSame(
            ['203.0.113.202', '203.0.113.201'],
            array_map(static fn (WeeklyDigestNewSender $s): string => $s->label, $newSenders),
        );
    }

    /** @param array<WeeklyDigestDomainData> $domains */
    private function domainNamed(array $domains, string $name): WeeklyDigestDomainData
    {
        foreach ($domains as $domain) {
            if ($name === $domain->domainName) {
                return $domain;
            }
        }

        self::fail(sprintf('The digest did not include %s.', $name));
    }

    private function addDomain(Team $team, string $name): MonitoredDomain
    {
        $em = $this->getService(EntityManagerInterface::class);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        return $domain;
    }

    private function seedIdentity(
        string $sourceIp,
        string $hostname,
        ?string $registrableDomain,
        ?string $organization,
        SenderRole $role,
    ): void {
        $this->getService(EntityManagerInterface::class)->persist(new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: $sourceIp,
            resolvedAt: new \DateTimeImmutable(),
            hostname: $hostname,
            registrableDomain: $registrableDomain,
            organization: $organization,
            role: $role,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * @param list<array{ip: string, count: int, passes: bool}> $records
     */
    private function seedReport(MonitoredDomain $domain, \DateTimeImmutable $periodEnd, array $records): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: $periodEnd->modify('-1 day'),
            dateRangeEnd: $periodEnd,
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        foreach ($records as $record) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: $record['ip'],
                count: $record['count'],
                disposition: Disposition::None,
                dkimResult: $record['passes'] ? AuthResult::Pass : AuthResult::Fail,
                spfResult: $record['passes'] ? AuthResult::Pass : AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }
    }

    private function persistAlerts(
        EntityManagerInterface $em,
        Team $team,
        MonitoredDomain $domain,
        AlertType $type,
        AlertSeverity $severity,
        int $count,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $resolvedAt = null,
    ): void {
        for ($index = 0; $index < $count; ++$index) {
            $alert = new Alert(
                id: Uuid::uuid7(),
                team: $team,
                monitoredDomain: $domain,
                type: $type,
                severity: $severity,
                title: sprintf('%s alert #%d', $type->value, $index + 1),
                message: 'Seeded.',
                data: [],
                createdAt: $createdAt ?? new \DateTimeImmutable('-'.($index + 1).' hours'),
                resolvedAt: $resolvedAt,
            );
            $alert->popEvents();
            $em->persist($alert);
        }
    }

    /** @return array{Team, MonitoredDomain, EntityManagerInterface} */
    private function createTeamAndDomain(): array
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Digest Generator Test',
            slug: 'digest-gen-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'dns-digest-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$team, $domain, $em];
    }
}
