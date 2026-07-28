<?php

declare(strict_types=1);

namespace App\Tests\TestSupport;

use App\Entity\Alert;
use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\SenderIdentity;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\Fixtures\Persona;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use App\Value\SenderRole;
use App\Value\SubscriptionPlan;
use App\Value\TeamRole;
use App\Value\WeeklyDigestSection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * A team whose weekly digest contains **every** {@see WeeklyDigestSection}.
 *
 * The digest ships as two alternatives written by two renderers, and the only
 * way to prove they say the same things is to render a week in which every
 * section is switched on. A fixture that happens to leave one section empty
 * makes the parity assertion for that section vacuously true — which is why the
 * parity test asserts the fixture really did light all of them up.
 *
 * Everything the digest *prints* is fixed rather than random: the golden-file
 * test renders this same fixture and diffs the result, so a `uniqid()` anywhere
 * in the visible output would make the golden unstable. The two values that
 * cannot be pinned (the reporting period, which comes from the wall clock, and
 * the domain UUID inside the review deep link) are normalised by that test.
 */
final readonly class FullWeeklyDigestFixture
{
    public const string DOMAIN = 'parity.example';
    public const string TEAM_NAME = 'Parity Team';

    /** New this week, healthy, and recognisable — the calm case. */
    public const string ESP_IP = '77.75.78.89';
    public const string ESP_ORGANIZATION = 'Seznam';

    /** New this week, failing SPF by design — the case that must not read as an attack. */
    public const string FORWARDER_IP = '52.212.19.177';
    public const string FORWARDER_DOMAIN = 'cloud-sec-av.com';

    /** Seen before the window, so it is NOT a discovery — it only moves the trend. */
    public const string ESTABLISHED_IP = '198.51.100.99';

    /**
     * Fixed so the "last checked" line in the broken-DNS section is stable.
     * Well before any digest window, which is deliberate: currently-broken DNS
     * is standing state, not a this-week event.
     */
    public const string DNS_CHECKED_AT = '2026-03-10 07:15:00';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function seed(string $userEmail, string $teamSlug): Persona
    {
        $user = new User(
            id: Uuid::uuid7(),
            email: $userEmail,
            createdAt: new \DateTimeImmutable('-60 days'),
            onboardingCompletedAt: new \DateTimeImmutable('-60 days'),
        );
        $user->popEvents();
        $this->entityManager->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: self::TEAM_NAME,
            slug: $teamSlug,
            createdAt: new \DateTimeImmutable('-60 days'),
            // The AI section is plan-gated before the service is ever called,
            // so a free-plan team cannot exercise it even with a stubbed
            // provider.
            plan: SubscriptionPlan::PersonalAi->value,
        );
        $team->popEvents();
        $this->entityManager->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable('-60 days'),
        );
        $this->entityManager->persist($membership);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: self::DOMAIN,
            createdAt: new \DateTimeImmutable('-60 days'),
        );
        $domain->popEvents();
        $this->entityManager->persist($domain);

        $this->seedSenderIdentities();
        $this->seedReports($domain);
        $this->seedUnreviewedSenders($domain);
        $this->seedAlerts($team, $domain);
        $this->seedDnsCheckResults($domain);

        $this->entityManager->flush();

        return new Persona($user, $team, $membership, $domain);
    }

    private function seedSenderIdentities(): void
    {
        $this->entityManager->persist(new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: self::ESP_IP,
            resolvedAt: new \DateTimeImmutable('-20 days'),
            hostname: 'mxb.seznam.cz',
            registrableDomain: 'seznam.cz',
            organization: self::ESP_ORGANIZATION,
            role: SenderRole::Esp,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('-20 days'),
        ));

        $this->entityManager->persist(new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: self::FORWARDER_IP,
            resolvedAt: new \DateTimeImmutable('-20 days'),
            hostname: 'eu.'.self::FORWARDER_DOMAIN,
            registrableDomain: self::FORWARDER_DOMAIN,
            organization: null,
            role: SenderRole::Forwarder,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('-20 days'),
        ));
    }

    private function seedReports(MonitoredDomain $domain): void
    {
        // Last week: one established sender, half its mail failing. Present so
        // the week-over-week delta has real numbers on both sides.
        $this->seedReport($domain, new \DateTimeImmutable('-10 days'), [
            [self::ESTABLISHED_IP, 5, true],
            [self::ESTABLISHED_IP, 5, false],
        ]);

        // This week: the established sender recovered, and two senders appear
        // for the first time.
        $this->seedReport($domain, new \DateTimeImmutable('-1 day'), [
            [self::ESTABLISHED_IP, 10, true],
            [self::ESP_IP, 10, true],
            [self::FORWARDER_IP, 4, false],
        ]);
    }

    /**
     * @param list<array{0: string, 1: int, 2: bool}> $records
     */
    private function seedReport(MonitoredDomain $domain, \DateTimeImmutable $periodEnd, array $records): void
    {
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
        $this->entityManager->persist($report);

        foreach ($records as [$ip, $count, $passes]) {
            $this->entityManager->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: $ip,
                count: $count,
                disposition: Disposition::None,
                dkimResult: $passes ? AuthResult::Pass : AuthResult::Fail,
                spfResult: $passes ? AuthResult::Pass : AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }
    }

    /**
     * Standing authorization state — `updated_at IS NULL` is how "nobody has
     * decided yet" is stored. Two names so the section has something to list
     * and the count is not confusable with the number of new senders.
     */
    private function seedUnreviewedSenders(MonitoredDomain $domain): void
    {
        foreach ([
            [self::ESP_IP, self::ESP_ORGANIZATION, 240],
            ['203.0.113.17', null, 12],
        ] as [$ip, $organization, $messages]) {
            $this->entityManager->persist(new KnownSender(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                sourceIp: $ip,
                firstSeenAt: new \DateTimeImmutable('-30 days'),
                lastSeenAt: new \DateTimeImmutable('-1 day'),
                totalMessages: $messages,
                passRate: 100.0,
                organization: $organization,
            ));
        }
    }

    private function seedAlerts(Team $team, MonitoredDomain $domain): void
    {
        $outstanding = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::DnsRecordMissing,
            severity: AlertSeverity::Critical,
            title: 'DMARC record is missing',
            message: 'No DMARC record was found for this domain.',
            data: [],
            createdAt: new \DateTimeImmutable('-2 days'),
            resolvedAt: null,
        );
        $outstanding->popEvents();
        $this->entityManager->persist($outstanding);

        // Three detections of one thing on one domain. They collapse into a
        // single grouped row, which is what makes `alertsCount` (3 + 1 = 4) and
        // `attentionAlertGroups` (2) different numbers — without that, the two
        // alternatives could print either one and look identical.
        for ($index = 0; $index < 3; ++$index) {
            $repeated = new Alert(
                id: Uuid::uuid7(),
                team: $team,
                monitoredDomain: $domain,
                type: AlertType::NewUnknownSender,
                severity: AlertSeverity::Warning,
                title: 'New senders detected',
                message: 'A sender we have not seen before delivered mail as this domain.',
                data: [],
                createdAt: new \DateTimeImmutable('-'.($index + 3).' days'),
                resolvedAt: null,
            );
            $repeated->popEvents();
            $this->entityManager->persist($repeated);
        }

        $fixed = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Warning,
            title: 'Authentication failures spiked',
            message: 'Failures returned to normal.',
            data: [],
            createdAt: new \DateTimeImmutable('-6 days'),
            resolvedAt: new \DateTimeImmutable('-3 days'),
        );
        $fixed->popEvents();
        $this->entityManager->persist($fixed);
    }

    /**
     * DMARC: latest check invalid, so the record shows as still broken.
     * SPF: an earlier baseline plus a later changed check, which is what the
     * "DNS changes detected" count requires — the baseline of a freshly added
     * domain deliberately does not count as a change.
     */
    private function seedDnsCheckResults(MonitoredDomain $domain): void
    {
        $this->entityManager->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(self::DNS_CHECKED_AT),
            rawRecord: null,
            isValid: false,
            issues: [['severity' => 'error', 'message' => 'No DMARC record found.']],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        ));

        $this->entityManager->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable('-30 days'),
            rawRecord: 'v=spf1 include:_spf.google.com ~all',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        ));

        $this->entityManager->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable('-2 days'),
            rawRecord: 'v=spf1 include:_spf.google.com include:spf.protection.outlook.com ~all',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: 'v=spf1 include:_spf.google.com ~all',
            hasChanged: true,
            isFirstCheck: false,
        ));
    }
}
