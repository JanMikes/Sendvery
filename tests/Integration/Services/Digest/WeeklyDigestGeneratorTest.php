<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Digest;

use App\Entity\Alert;
use App\Entity\DnsCheckResult;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\User;
use App\Services\Digest\WeeklyDigestGenerator;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\DnsCheckType;
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
            $digest->averagePassRate,
            'With no reporting domain at all there is no average to state.',
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
