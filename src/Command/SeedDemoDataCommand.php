<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Alert;
use App\Entity\BlacklistCheckResult;
use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\ManagedDmarcPolicyChange;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Repository\IngestionSourceStatusRepository;
use App\Services\IdentityProvider;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\PolicyChangeSource;
use App\Value\DnsCheckType;
use App\Value\IngestionSource;
use App\Value\SubscriptionPlan;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Populates the local dev database with a fully-realised "Demo Team" so that
 * a fresh `make up` shows a populated dashboard instead of empty surfaces —
 * autonomous CX evaluation runs (and humans onboarding to the codebase) can
 * judge layout/IA/charts without first having to forward real DMARC reports
 * and wait for the nightly cron.
 *
 * Refuses to run in `prod` to keep the truncate-then-seed step safe; only
 * the demo team's data is touched, never any other team's. Idempotent: each
 * run wipes the previous demo team via FK cascade and rebuilds from scratch.
 */
#[AsCommand(
    name: 'sendvery:demo:seed',
    description: 'Seed the local dev database with a demo team, domains, reports, alerts, and health snapshots.',
)]
final class SeedDemoDataCommand extends Command
{
    public const string DEMO_TEAM_NAME = 'Demo Team';
    public const string DEMO_TEAM_SLUG = 'demo-team';
    public const string DEMO_USER_EMAIL = 'demo@sendvery.test';
    public const int REPORTS_PER_DOMAIN = 30;
    public const int SNAPSHOTS_PER_DOMAIN = 30;
    public const int ALERT_COUNT = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityProvider $identityProvider,
        private readonly ClockInterface $clock,
        private readonly IngestionSourceStatusRepository $ingestionSourceStatusRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('sendvery:demo:seed refuses to run in the prod environment. This command truncates demo data and must never touch a production database.');

            return Command::FAILURE;
        }

        $this->wipeExistingDemoTeam();
        $user = $this->ensureDemoUser();
        $team = $this->createDemoTeam($user);

        $domains = $this->createDomains($team);
        $reportCount = 0;
        $snapshotCount = 0;
        foreach ($domains as $config) {
            $reportCount += $this->createReports($config['domain'], $config['passRatio']);
            $snapshotCount += $this->createSnapshots($config['domain'], $config['grade'], $config['scores']);
            $this->createDnsCheckResults($config['domain'], $config['scores']);
        }

        $alertCount = $this->createAlerts($team, array_column($domains, 'domain'));
        $this->markReportIntakeHealthy();

        foreach ($domains as $config) {
            $this->createBlacklistCheckResults($config['domain'], $config['scores']['blacklist']);
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Seeded "%s" with %d domains, %d DMARC reports, %d alerts, %d health snapshots.',
            self::DEMO_TEAM_NAME,
            count($domains),
            $reportCount,
            $alertCount,
            $snapshotCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Delete every team-scoped row explicitly rather than relying on FK
     * cascade — Doctrine `SchemaTool::createSchema()` used by the test
     * bootstrap does not apply the `ON DELETE CASCADE` clauses that live in
     * the production migrations, so a test-env wipe that assumed cascading
     * would fail with a FK violation. Order matters: leaf tables first.
     */
    private function wipeExistingDemoTeam(): void
    {
        $existing = $this->entityManager->getRepository(Team::class)
            ->findOneBy(['slug' => self::DEMO_TEAM_SLUG]);

        if (null === $existing) {
            return;
        }

        $teamId = $existing->id->toString();
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement(
            'DELETE FROM dmarc_record WHERE dmarc_report_id IN (
                SELECT dr.id FROM dmarc_report dr
                JOIN monitored_domain md ON md.id = dr.monitored_domain_id
                WHERE md.team_id = :teamId
            )',
            ['teamId' => $teamId],
        );
        $connection->executeStatement(
            'DELETE FROM dmarc_report WHERE monitored_domain_id IN (
                SELECT id FROM monitored_domain WHERE team_id = :teamId
            )',
            ['teamId' => $teamId],
        );
        $connection->executeStatement(
            'DELETE FROM domain_health_snapshot WHERE monitored_domain_id IN (
                SELECT id FROM monitored_domain WHERE team_id = :teamId
            )',
            ['teamId' => $teamId],
        );
        $connection->executeStatement(
            'DELETE FROM dns_check_result WHERE monitored_domain_id IN (
                SELECT id FROM monitored_domain WHERE team_id = :teamId
            )',
            ['teamId' => $teamId],
        );
        $connection->executeStatement(
            'DELETE FROM alert WHERE team_id = :teamId',
            ['teamId' => $teamId],
        );
        // known_sender rows are created by SenderDiscovery during report
        // ingestion, not by this seeder, so an untouched demo team has none —
        // which is exactly why this was missed. As soon as a demo domain
        // acquires one (ingesting a report, or reviewing a sender by hand), the
        // monitored_domain delete below fails on the FK and reseeding is stuck.
        $connection->executeStatement(
            'DELETE FROM known_sender WHERE monitored_domain_id IN (
                SELECT id FROM monitored_domain WHERE team_id = :teamId
            )',
            ['teamId' => $teamId],
        );
        // Seeded since W1 — without this the monitored_domain delete below
        // fails on the FK and reseeding is stuck, the same trap known_sender
        // sprang.
        $connection->executeStatement(
            'DELETE FROM blacklist_check_result WHERE monitored_domain_id IN (
                SELECT id FROM monitored_domain WHERE team_id = :teamId
            )',
            ['teamId' => $teamId],
        );
        $connection->executeStatement(
            'DELETE FROM monitored_domain WHERE team_id = :teamId',
            ['teamId' => $teamId],
        );
        $connection->executeStatement(
            'DELETE FROM team_membership WHERE team_id = :teamId',
            ['teamId' => $teamId],
        );
        $connection->executeStatement(
            'DELETE FROM team WHERE id = :teamId',
            ['teamId' => $teamId],
        );

        $this->entityManager->clear();
    }

    private function ensureDemoUser(): User
    {
        $existing = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => self::DEMO_USER_EMAIL]);
        if (null !== $existing) {
            return $existing;
        }

        // Adopt the first existing user if any — gives the human running this
        // a populated dashboard tied to the account they already log in with.
        $firstUser = $this->entityManager->getRepository(User::class)
            ->findOneBy([], ['createdAt' => 'ASC']);
        if (null !== $firstUser) {
            return $firstUser;
        }

        $now = $this->clock->now();
        $user = new User(
            id: $this->identityProvider->nextIdentity(),
            email: self::DEMO_USER_EMAIL,
            createdAt: $now,
            onboardingTeamCompletedAt: $now,
            onboardingCompletedAt: $now,
        );
        $user->popEvents();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createDemoTeam(User $user): Team
    {
        $now = $this->clock->now();
        $team = new Team(
            id: $this->identityProvider->nextIdentity(),
            name: self::DEMO_TEAM_NAME,
            slug: self::DEMO_TEAM_SLUG,
            createdAt: $now,
            // Staff-grant tier so the demo surfaces every paid feature (managed
            // DMARC, AI insights, …) without a Stripe subscription.
            plan: SubscriptionPlan::Unlimited->value,
        );
        $team->popEvents();
        $this->entityManager->persist($team);

        $membership = new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: $now,
        );
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $team;
    }

    /**
     * @return list<array{domain: MonitoredDomain, passRatio: float, grade: string, scores: array{spf: int, dkim: int, dmarc: int, mx: int, blacklist: int}}>
     */
    private function createDomains(Team $team): array
    {
        $now = $this->clock->now();

        $acme = new MonitoredDomain(
            id: $this->identityProvider->nextIdentity(),
            team: $team,
            domain: 'acme.example',
            createdAt: $now->modify('-60 days'),
            dmarcPolicy: DmarcPolicy::Reject,
            spfVerifiedAt: $now->modify('-45 days'),
            dkimVerifiedAt: $now->modify('-45 days'),
            dmarcVerifiedAt: $now->modify('-45 days'),
            firstReportAt: $now->modify('-30 days'),
        );
        $acme->popEvents();
        $this->entityManager->persist($acme);

        $okay = new MonitoredDomain(
            id: $this->identityProvider->nextIdentity(),
            team: $team,
            domain: 'okay.example',
            createdAt: $now->modify('-40 days'),
            dmarcPolicy: DmarcPolicy::Quarantine,
            spfVerifiedAt: $now->modify('-30 days'),
            dkimVerifiedAt: $now->modify('-30 days'),
            dmarcVerifiedAt: $now->modify('-30 days'),
            firstReportAt: $now->modify('-29 days'),
        );
        // DEC-058: okay.example is the managed-DMARC demo — verified CNAME,
        // hosted at quarantine, auto-drive on with the next advance to reject
        // scheduled, so the dashboard managed card renders in its active state.
        $okay->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $okay->managedPolicyP = DmarcPolicy::Quarantine;
        $okay->managedPolicyPct = 100;
        $okay->autoRampStage = AutoRampStage::Quarantine;
        $okay->autoRampEnabled = true;
        $okay->managedDmarcEnabledAt = $now->modify('-25 days');
        $okay->cnameVerifiedAt = $now->modify('-24 days');
        $okay->lastPolicyChangeAt = $now->modify('-10 days');
        $okay->autoRampScheduledStage = AutoRampStage::Reject;
        $okay->autoRampScheduledAdvanceAt = $now->modify('+36 hours');
        $okay->cloudflareHostedDmarcRecordId = 'demo-cf-hosted-okay';
        $okay->popEvents();
        $this->entityManager->persist($okay);
        $this->seedManagedAuditTrail($okay, $now);

        $broken = new MonitoredDomain(
            id: $this->identityProvider->nextIdentity(),
            team: $team,
            domain: 'broken.example',
            createdAt: $now->modify('-20 days'),
            dmarcPolicy: DmarcPolicy::None,
            spfVerifiedAt: null,
            dkimVerifiedAt: null,
            dmarcVerifiedAt: $now->modify('-15 days'),
            firstReportAt: $now->modify('-14 days'),
        );
        $broken->popEvents();
        $this->entityManager->persist($broken);

        $this->entityManager->flush();

        return [
            [
                'domain' => $acme,
                'passRatio' => 0.98,
                'grade' => 'A',
                'scores' => ['spf' => 100, 'dkim' => 100, 'dmarc' => 100, 'mx' => 95, 'blacklist' => 100],
            ],
            [
                'domain' => $okay,
                'passRatio' => 0.78,
                'grade' => 'C',
                'scores' => ['spf' => 75, 'dkim' => 60, 'dmarc' => 80, 'mx' => 70, 'blacklist' => 85],
            ],
            [
                'domain' => $broken,
                'passRatio' => 0.42,
                'grade' => 'D',
                'scores' => ['spf' => 0, 'dkim' => 0, 'dmarc' => 50, 'mx' => 60, 'blacklist' => 80],
            ],
        ];
    }

    private function seedManagedAuditTrail(MonitoredDomain $domain, \DateTimeImmutable $now): void
    {
        $this->entityManager->persist(new ManagedDmarcPolicyChange(
            id: $this->identityProvider->nextIdentity(),
            domain: $domain,
            teamId: $domain->team->id,
            actorUserId: null,
            source: PolicyChangeSource::AutoRamp,
            fromPolicy: 'none',
            toPolicy: 'quarantine',
            reason: null,
            createdAt: $now->modify('-10 days'),
        ));
    }

    private function createReports(MonitoredDomain $domain, float $passRatio): int
    {
        $now = $this->clock->now();
        $reporters = [
            ['org' => 'google.com', 'email' => 'noreply-dmarc-support@google.com'],
            ['org' => 'Yahoo! Inc.', 'email' => 'dmarchelp@yahoo.com'],
            ['org' => 'Outlook.com', 'email' => 'dmarcreport@microsoft.com'],
        ];

        $created = 0;
        for ($i = 0; $i < self::REPORTS_PER_DOMAIN; ++$i) {
            $reporter = $reporters[$i % count($reporters)];
            $dayOffset = self::REPORTS_PER_DOMAIN - $i;
            $begin = $now->modify(sprintf('-%d days', $dayOffset))->setTime(0, 0);
            $end = $begin->modify('+1 day');

            $report = new DmarcReport(
                id: $this->identityProvider->nextIdentity(),
                monitoredDomain: $domain,
                reporterOrg: $reporter['org'],
                reporterEmail: $reporter['email'],
                externalReportId: sprintf('demo-%s-%d', $domain->id->toString(), $i),
                dateRangeBegin: $begin,
                dateRangeEnd: $end,
                policyDomain: $domain->domain,
                policyAdkim: DmarcAlignment::Relaxed,
                policyAspf: DmarcAlignment::Relaxed,
                policyP: $domain->dmarcPolicy ?? DmarcPolicy::None,
                policySp: null,
                policyPct: 100,
                rawXml: '<feedback><demo>true</demo></feedback>',
                processedAt: $now->modify(sprintf('-%d days', $dayOffset))->setTime(6, 0),
            );
            $report->popEvents();
            $this->entityManager->persist($report);

            $totalMessages = 120;
            $passCount = (int) round($totalMessages * $passRatio);
            $failCount = $totalMessages - $passCount;

            if ($passCount > 0) {
                $passRecord = new DmarcRecord(
                    id: $this->identityProvider->nextIdentity(),
                    dmarcReport: $report,
                    sourceIp: '209.85.220.41',
                    count: $passCount,
                    disposition: Disposition::None,
                    dkimResult: AuthResult::Pass,
                    spfResult: AuthResult::Pass,
                    headerFrom: $domain->domain,
                    dkimDomain: $domain->domain,
                    dkimSelector: 'default',
                    spfDomain: $domain->domain,
                    resolvedHostname: 'mail-sor-f41.google.com',
                    resolvedOrg: 'Google LLC',
                );
                $this->entityManager->persist($passRecord);
            }

            if ($failCount > 0) {
                $failRecord = new DmarcRecord(
                    id: $this->identityProvider->nextIdentity(),
                    dmarcReport: $report,
                    sourceIp: '198.51.100.7',
                    count: $failCount,
                    disposition: Disposition::Quarantine,
                    dkimResult: AuthResult::Fail,
                    spfResult: AuthResult::Fail,
                    headerFrom: $domain->domain,
                    dkimDomain: null,
                    dkimSelector: null,
                    spfDomain: 'spoofer.example',
                    resolvedHostname: 'unknown.example',
                    resolvedOrg: 'Unknown',
                );
                $this->entityManager->persist($failRecord);
            }

            ++$created;
        }

        return $created;
    }

    /**
     * The AUTHORITATIVE per-protocol state every surface now reads is the newest
     * `dns_check_result` row — not the snapshot scores and not the `*_verified_at`
     * columns. Without these rows the seeded demo domains would render with no
     * protocol badges at all on `/app/domains`, which is exactly the "empty
     * surface misread as a bug" problem the seeder exists to prevent.
     *
     * A score of 0 for a protocol means the demo story is "record absent", so it
     * gets a row with a null raw record; anything else is a published record that
     * validates.
     *
     * @param array{spf: int, dkim: int, dmarc: int, mx: int, blacklist: int} $scores
     */
    private function createDnsCheckResults(MonitoredDomain $domain, array $scores): void
    {
        $checkedAt = $this->clock->now()->modify('-1 day')->setTime(3, 0);

        $rawRecords = [
            DnsCheckType::Spf->value => 'v=spf1 include:_spf.google.com ~all',
            DnsCheckType::Dkim->value => 'v=DKIM1; k=rsa; p=MIGfMA0GCS',
            DnsCheckType::Dmarc->value => 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com',
            DnsCheckType::Mx->value => '10 aspmx.l.google.com',
        ];

        foreach ($rawRecords as $type => $rawRecord) {
            $isValid = $scores[$type] > 0;

            $check = new DnsCheckResult(
                id: $this->identityProvider->nextIdentity(),
                monitoredDomain: $domain,
                type: DnsCheckType::from($type),
                checkedAt: $checkedAt,
                rawRecord: $isValid ? $rawRecord : null,
                isValid: $isValid,
                issues: $isValid ? [] : [['severity' => 'error', 'message' => 'No record found']],
                details: [],
                previousRawRecord: null,
                hasChanged: false,
            );
            // Seeding must not emit DNS-change alerts — the demo alert set is
            // curated separately by createAlerts().
            $check->popEvents();
            $this->entityManager->persist($check);
        }
    }

    /**
     * @param array{spf: int, dkim: int, dmarc: int, mx: int, blacklist: int} $scores
     */
    private function createSnapshots(MonitoredDomain $domain, string $grade, array $scores): int
    {
        $now = $this->clock->now();
        $aggregate = (int) round(($scores['spf'] + $scores['dkim'] + $scores['dmarc'] + $scores['mx'] + $scores['blacklist']) / 5);

        $created = 0;
        for ($i = 0; $i < self::SNAPSHOTS_PER_DOMAIN; ++$i) {
            $dayOffset = self::SNAPSHOTS_PER_DOMAIN - 1 - $i;
            $checkedAt = $now->modify(sprintf('-%d days', $dayOffset))->setTime(3, 0);

            $snapshot = new DomainHealthSnapshot(
                id: $this->identityProvider->nextIdentity(),
                monitoredDomain: $domain,
                grade: $grade,
                score: $aggregate,
                spfScore: $scores['spf'],
                dkimScore: $scores['dkim'],
                dmarcScore: $scores['dmarc'],
                mxScore: $scores['mx'],
                blacklistScore: $scores['blacklist'],
                checkedAt: $checkedAt,
                recommendations: [],
                shareHash: null,
            );
            $this->entityManager->persist($snapshot);
            ++$created;
        }

        return $created;
    }

    /**
     * Stamps a recent successful central-inbox poll.
     *
     * Without it the ingestion page shows "Not checked yet" on every demo and
     * dev environment, because nothing local ever polls a real inbox. That
     * state is honest but it is also the ONE state a reviewer never needs to
     * evaluate — it renders identically whether or not the feature works. The
     * seeder exists precisely so normal empty states are not misread, so it
     * seeds the state that has something to show.
     *
     * This is NOT a substitute for the real thing: a genuinely fresh install
     * still reports NeverPolled until its first scheduled run, and the demo
     * team is wiped and rebuilt on every seed.
     */
    private function markReportIntakeHealthy(): void
    {
        $status = $this->ingestionSourceStatusRepository->getOrCreate(IngestionSource::CentralInbox);
        $status->recordSuccess($this->clock->now()->modify('-4 minutes'));
    }

    /**
     * Backs each seeded snapshot's blacklist score with the check rows that
     * would have produced it.
     *
     * WHY THIS IS NOT OPTIONAL POLISH: the snapshots carry a blacklist score, so
     * without these rows the health page reads "Blacklist 85%" while the
     * Blacklist tab it links to sits empty. Two surfaces disagreeing about the
     * same measurement is exactly the confusion the seeder exists to prevent,
     * and it only became possible once W1 made blacklist scores real.
     *
     * A score below 100 means something is listed, so the seeded rows have to
     * agree: one listed IP for any domain whose score is not perfect.
     */
    private function createBlacklistCheckResults(MonitoredDomain $domain, int $blacklistScore): void
    {
        $now = $this->clock->now();
        $isClean = 100 === $blacklistScore;

        foreach (['203.0.113.10', '203.0.113.11'] as $index => $ip) {
            // Only the first address carries the listing, so a domain with an
            // imperfect score has exactly one thing to look at.
            $listed = !$isClean && 0 === $index;

            $this->entityManager->persist(new BlacklistCheckResult(
                id: $this->identityProvider->nextIdentity(),
                monitoredDomain: $domain,
                ipAddress: $ip,
                checkedAt: $now->modify('-6 hours'),
                results: $listed
                    ? ['zen.spamhaus.org' => ['listed' => true, 'reason' => 'https://check.spamhaus.org/']]
                    : ['zen.spamhaus.org' => ['listed' => false, 'reason' => null]],
                isListed: $listed,
            ));
        }
    }

    /**
     * @param list<MonitoredDomain> $domains
     */
    private function createAlerts(Team $team, array $domains): int
    {
        $now = $this->clock->now();
        [$acme, $okay, $broken] = $domains;

        $blueprints = [
            [
                'domain' => $broken,
                'type' => AlertType::DnsRecordMissing,
                'severity' => AlertSeverity::Critical,
                'title' => 'SPF record missing for broken.example',
                'message' => 'No SPF record is published for broken.example. Outbound mail will fail SPF until you add a TXT record.',
                'data' => ['record_type' => 'spf'],
                'createdAt' => $now->modify('-2 hours'),
            ],
            [
                'domain' => $broken,
                'type' => AlertType::FailureSpike,
                'severity' => AlertSeverity::Critical,
                'title' => 'Failure spike detected for broken.example',
                'message' => 'DMARC failure rate spiked to 58% (average: 25%). 70 failures out of 120 messages.',
                'data' => [
                    'current_fail_rate' => 58.0,
                    'average_fail_rate' => 25.0,
                    'spike_amount' => 33.0,
                    'fail_count' => 70,
                    'pass_count' => 50,
                    'total_messages' => 120,
                ],
                'createdAt' => $now->modify('-1 day'),
            ],
            [
                'domain' => $okay,
                'type' => AlertType::NewUnknownSender,
                'severity' => AlertSeverity::Warning,
                'title' => 'New sender detected on okay.example',
                'message' => 'Source IP 203.0.113.42 (newsender.example) sent 12 messages on behalf of okay.example for the first time.',
                'data' => [
                    'source_ip' => '203.0.113.42',
                    'resolved_org' => 'New Sender Co.',
                    'message_count' => 12,
                ],
                'createdAt' => $now->modify('-3 days'),
            ],
            [
                'domain' => $acme,
                'type' => AlertType::PolicyRecommendation,
                'severity' => AlertSeverity::Info,
                'title' => 'acme.example is ready for p=reject',
                'message' => 'acme.example has run at quarantine for 30 days with zero forwarder breakage. Promote DMARC policy to p=reject.',
                'data' => ['current_policy' => 'quarantine', 'recommended_policy' => 'reject'],
                'createdAt' => $now->modify('-5 days'),
            ],
            [
                'domain' => $acme,
                'type' => AlertType::IpBlacklisted,
                'severity' => AlertSeverity::Warning,
                'title' => 'Sending IP for acme.example listed on Spamhaus',
                'message' => 'IP 192.0.2.55 (a regular sender for acme.example) appeared on Spamhaus SBL. Investigate before deliverability drops.',
                'data' => ['source_ip' => '192.0.2.55', 'blocklist' => 'spamhaus.sbl'],
                'createdAt' => $now->modify('-7 days'),
            ],
        ];

        foreach ($blueprints as $blueprint) {
            $alert = new Alert(
                id: $this->identityProvider->nextIdentity(),
                team: $team,
                monitoredDomain: $blueprint['domain'],
                type: $blueprint['type'],
                severity: $blueprint['severity'],
                title: $blueprint['title'],
                message: $blueprint['message'],
                data: $blueprint['data'],
                createdAt: $blueprint['createdAt'],
            );
            $alert->popEvents();
            $this->entityManager->persist($alert);
        }

        return count($blueprints);
    }
}
