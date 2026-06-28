<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\AutoRampDmarcCommand;
use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainReadinessSignals;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\CloudflareDnsClient;
use App\Services\Dns\DmarcRampReadinessEvaluator;
use App\Services\ReportAddressProvider;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Messenger\MessageBusInterface;

final class AutoRampDmarcCommandTest extends IntegrationTestCase
{
    #[Test]
    public function schedulesA48hAdvanceWithNoticeWhenDomainBecomesReady(): void
    {
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: true, ready: true);

        $this->runSweep();

        $domain = $this->reload($domainId);
        self::assertSame(AutoRampStage::Quarantine, $domain->autoRampScheduledStage);
        self::assertNotNull($domain->autoRampScheduledAdvanceAt);
        self::assertGreaterThan(new \DateTimeImmutable('+47 hours'), $domain->autoRampScheduledAdvanceAt);
    }

    #[Test]
    public function executesTheScheduledAdvanceOnlyIfStillReady(): void
    {
        $dueAt = new \DateTimeImmutable('-1 hour');
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: true, ready: true, scheduledAdvanceAt: $dueAt, scheduledStage: AutoRampStage::Quarantine);

        $this->runSweep();

        self::assertSame(DmarcPolicy::Quarantine, $this->reload($domainId)->managedPolicyP);
    }

    #[Test]
    public function pausesInsteadOfAdvancingWhenAScheduledAdvanceIsNoLongerReady(): void
    {
        $dueAt = new \DateTimeImmutable('-1 hour');
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: true, ready: false, scheduledAdvanceAt: $dueAt, scheduledStage: AutoRampStage::Quarantine);

        $this->runSweep();

        $domain = $this->reload($domainId);
        self::assertSame(DmarcPolicy::None, $domain->managedPolicyP);
        self::assertNotNull($domain->autoRampPausedAt);
    }

    #[Test]
    public function pausesTheRampOnRegressionInsteadOfTightening(): void
    {
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: true, ready: true, withAuthorizedFailure: true);

        $this->runSweep();

        $domain = $this->reload($domainId);
        self::assertSame(DmarcPolicy::None, $domain->managedPolicyP, 'Regression must never tighten.');
        self::assertNotNull($domain->autoRampPausedAt);
    }

    #[Test]
    public function rollsBackAndPausesOnHardRegressionAtAnEnforcingTier(): void
    {
        $domainId = $this->managedDomain('pro', DmarcPolicy::Quarantine, autoRampEnabled: true, ready: true, withAuthorizedFailure: true);

        $this->runSweep();

        $domain = $this->reload($domainId);
        self::assertSame(DmarcPolicy::None, $domain->managedPolicyP, 'Hard regression rolls back to the previous (looser) tier.');
        self::assertNotNull($domain->autoRampPausedAt);
    }

    #[Test]
    public function nudgesGuidedDomainsThatBecomeReady(): void
    {
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: false, ready: true);

        $this->runSweep();

        $alerts = $this->getService(EntityManagerInterface::class)
            ->getRepository(\App\Entity\Alert::class)
            ->findBy(['monitoredDomain' => $domainId->toString()]);
        self::assertCount(1, $alerts);
        self::assertSame(\App\Value\AlertType::ManagedDmarcReady, $alerts[0]->type);
    }

    #[Test]
    public function doesNotReNudgeAGuidedDomainWithARecentReadyAlert(): void
    {
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: false, ready: true);
        $this->seedRecentReadyAlert($domainId);

        $this->runSweep();

        // Still just the seeded alert — no duplicate nudge.
        $alerts = $this->getService(EntityManagerInterface::class)
            ->getRepository(\App\Entity\Alert::class)
            ->findBy(['monitoredDomain' => $domainId->toString()]);
        self::assertCount(1, $alerts);
    }

    #[Test]
    public function skipsADomainWhoseTeamLostTheEntitlement(): void
    {
        $domainId = $this->managedDomain('free', DmarcPolicy::None, autoRampEnabled: true, ready: true);

        $this->runSweep();

        // Not selected (free team) → never scheduled.
        self::assertNull($this->reload($domainId)->autoRampScheduledAdvanceAt);
    }

    #[Test]
    public function continuesPastAFailingDomain(): void
    {
        // A corrupt plan value makes the advance dispatch throw for the first
        // domain; the second domain must still be processed.
        $failing = $this->managedDomain('bogus-plan', DmarcPolicy::None, autoRampEnabled: true, ready: true, scheduledAdvanceAt: new \DateTimeImmutable('-1 hour'), scheduledStage: AutoRampStage::Quarantine);
        $healthy = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: true, ready: true);

        $this->runSweep();

        self::assertSame(DmarcPolicy::None, $this->reload($failing)->managedPolicyP, 'The failing domain is left untouched.');
        self::assertNotNull($this->reload($healthy)->autoRampScheduledAdvanceAt, 'The sweep continued past the failure and scheduled the healthy domain.');
    }

    #[Test]
    public function leavesAnAlreadyPausedDomainUntouched(): void
    {
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: true, ready: true, paused: true);

        $this->runSweep();

        self::assertNull($this->reload($domainId)->autoRampScheduledAdvanceAt, 'A paused ramp is left alone.');
    }

    #[Test]
    public function pausesWhenTheCnameIsLost(): void
    {
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: true, ready: true, cnameVerified: false);

        $this->runSweep();

        self::assertNotNull($this->reload($domainId)->autoRampPausedAt, 'A lost CNAME freezes the ramp.');
    }

    #[Test]
    public function skipsEntirelyWhenCloudflareIsNotConfigured(): void
    {
        $domainId = $this->managedDomain('pro', DmarcPolicy::None, autoRampEnabled: true, ready: true);

        $command = new AutoRampDmarcCommand(
            new CloudflareDnsClient(new MockHttpClient(), new ReportAddressProvider('reports@sendvery.test'), new NullLogger(), '', ''),
            $this->getService(Connection::class),
            $this->getService(MonitoredDomainRepository::class),
            $this->getService(GetDomainReadinessSignals::class),
            $this->getService(DmarcRampReadinessEvaluator::class),
            $this->getService(MessageBusInterface::class),
            $this->getService(\Psr\Clock\ClockInterface::class),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('not configured', $tester->getDisplay());
        self::assertNull($this->reload($domainId)->autoRampScheduledAdvanceAt);
    }

    private function runSweep(): void
    {
        $tester = new CommandTester($this->getService(AutoRampDmarcCommand::class));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
    }

    private function reload(UuidInterface $domainId): MonitoredDomain
    {
        $this->getService(EntityManagerInterface::class)->clear();

        return $this->getService(MonitoredDomainRepository::class)->get($domainId);
    }

    private function seedRecentReadyAlert(UuidInterface $domainId): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        $em->persist(new \App\Entity\Alert(
            id: Uuid::uuid7(),
            team: $domain->team,
            monitoredDomain: $domain,
            type: \App\Value\AlertType::ManagedDmarcReady,
            severity: \App\Value\AlertSeverity::Info,
            title: 'already nudged',
            message: 'already nudged',
            data: [],
            createdAt: new \DateTimeImmutable('-1 day'),
        ));
        $em->flush();
    }

    private function managedDomain(
        string $plan,
        DmarcPolicy $policy,
        bool $autoRampEnabled,
        bool $ready,
        bool $withAuthorizedFailure = false,
        ?\DateTimeImmutable $scheduledAdvanceAt = null,
        ?AutoRampStage $scheduledStage = null,
        bool $cnameVerified = true,
        bool $paused = false,
    ): UuidInterface {
        $em = $this->getService(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();

        $team = new Team(id: Uuid::uuid7(), name: 'Ramp', slug: 'ramp-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: $plan);
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'ramp-'.bin2hex(random_bytes(3)).'.example',
            createdAt: $now->modify('-90 days'),
            firstReportAt: $now->modify('-70 days'),
        );
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = $policy;
        $domain->autoRampStage = AutoRampStage::fromPolicy($policy);
        $domain->autoRampEnabled = $autoRampEnabled;
        $domain->cnameVerifiedAt = $cnameVerified ? $now->modify('-60 days') : null;
        $domain->autoRampPausedAt = $paused ? $now->modify('-1 day') : null;
        $domain->lastPolicyChangeAt = $now->modify('-30 days');
        $domain->autoRampScheduledStage = $scheduledStage;
        $domain->autoRampScheduledAdvanceAt = $scheduledAdvanceAt;
        $em->persist($domain);

        if ($ready || $withAuthorizedFailure) {
            $report = new DmarcReport(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                reporterOrg: 'google.com',
                reporterEmail: 'noreply@google.com',
                externalReportId: 'ext-'.Uuid::uuid7()->toString(),
                dateRangeBegin: $now->modify('-2 days'),
                dateRangeEnd: $now->modify('-2 days'),
                policyDomain: $domain->domain,
                policyAdkim: DmarcAlignment::Relaxed,
                policyAspf: DmarcAlignment::Relaxed,
                policyP: $policy,
                policySp: null,
                policyPct: 100,
                rawXml: '<feedback></feedback>',
                processedAt: $now,
            );
            $em->persist($report);

            // Three reports' worth of passing volume across two sources.
            foreach (['1.1.1.1', '2.2.2.2'] as $ip) {
                $em->persist(new DmarcRecord(id: Uuid::uuid7(), dmarcReport: $report, sourceIp: $ip, count: 200, disposition: Disposition::None, dkimResult: AuthResult::Pass, spfResult: AuthResult::Pass, headerFrom: $domain->domain));
            }
            for ($i = 0; $i < 2; ++$i) {
                $extra = new DmarcReport(id: Uuid::uuid7(), monitoredDomain: $domain, reporterOrg: 'microsoft.com', reporterEmail: 'noreply@microsoft.com', externalReportId: 'ext-'.Uuid::uuid7()->toString(), dateRangeBegin: $now->modify('-2 days'), dateRangeEnd: $now->modify('-2 days'), policyDomain: $domain->domain, policyAdkim: DmarcAlignment::Relaxed, policyAspf: DmarcAlignment::Relaxed, policyP: $policy, policySp: null, policyPct: 100, rawXml: '<feedback></feedback>', processedAt: $now);
                $em->persist($extra);
                $em->persist(new DmarcRecord(id: Uuid::uuid7(), dmarcReport: $extra, sourceIp: '1.1.1.1', count: 100, disposition: Disposition::None, dkimResult: AuthResult::Pass, spfResult: AuthResult::Pass, headerFrom: $domain->domain));
            }

            if ($withAuthorizedFailure) {
                // An authorized sender that is now failing alignment — the regression signal.
                $em->persist($this->knownSender($domain, '9.9.9.9'));
                $em->persist(new DmarcRecord(id: Uuid::uuid7(), dmarcReport: $report, sourceIp: '9.9.9.9', count: 40, disposition: Disposition::None, dkimResult: AuthResult::Fail, spfResult: AuthResult::Fail, headerFrom: $domain->domain));
            }
        }

        $em->flush();

        return $domainId;
    }

    private function knownSender(MonitoredDomain $domain, string $ip): KnownSender
    {
        return new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: $ip,
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable(),
            totalMessages: 100,
            passRate: 50.0,
            isAuthorized: true,
        );
    }
}
