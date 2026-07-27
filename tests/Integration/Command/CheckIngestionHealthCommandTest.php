<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Alert;
use App\Entity\DmarcReport;
use App\Entity\IngestionSourceStatus;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\AlertType;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\IngestionSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The product's core promise is monitoring, and until this command existed it
 * could not notice its own monitoring stopping.
 *
 * "No reports have arrived" was only ever evaluated while
 * `monitored_domain.first_report_at` was NULL. The moment a domain's first
 * report landed, that branch became unreachable for the life of the domain — so
 * a domain that reported every day for a year and then went completely silent
 * produced no alert, no email and no badge. The failure mode with the highest
 * possible stakes was the one the product was structurally blind to.
 *
 * The second half of the contract matters just as much: silence is only the
 * customer's problem if OUR side is demonstrably working. Reporting "your
 * reports stopped" while our own poller is stuck sends users to re-check DNS
 * that was correct all along, which is the exact defect the 48-hour message
 * shipped with.
 */
final class CheckIngestionHealthCommandTest extends IntegrationTestCase
{
    #[Test]
    public function aDomainThatReportedDailyAndWentSilentIsAlerted(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = $this->domainReportingDailyUntil($em, daysAgo: 10);
        $this->provePipelineHealthy($em);
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);

        $alert = $this->findAlertFor($em, $domain, AlertType::ReportsStopped);
        self::assertNotNull(
            $alert,
            'A domain with an established daily reporting rhythm that has been silent for ten days must raise an alert. Silent monitoring is indistinguishable from no monitoring.',
        );
        self::assertStringContainsString(
            $domain->domain,
            $alert->title.' '.$alert->message,
            'The alert has to name the domain it is about, or an owner with many domains cannot act on it.',
        );
    }

    #[Test]
    public function aDomainStillReportingOnScheduleIsLeftAlone(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = $this->domainReportingDailyUntil($em, daysAgo: 0);
        $this->provePipelineHealthy($em);
        $em->flush();

        $this->tester()->execute([]);

        self::assertNull(
            $this->findAlertFor($em, $domain, AlertType::ReportsStopped),
            'A domain whose reports arrived today is healthy. Alerting here would train owners to ignore the alert that matters.',
        );
    }

    #[Test]
    public function noCustomerIsBlamedWhileOurOwnPipelineIsUnproven(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = $this->domainReportingDailyUntil($em, daysAgo: 10);
        // Deliberately no IngestionSourceStatus row: this is the state of a
        // fresh deployment, and of a poller that has never once succeeded.
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertNull(
            $this->findAlertFor($em, $domain, AlertType::ReportsStopped),
            'When we cannot prove our own poller is working, a quiet domain is evidence about us, not about the customer. Telling them to check their rua= tag would send them to fix DNS that is already correct.',
        );
        self::assertStringContainsString(
            'pipeline',
            strtolower($tester->getDisplay()),
            'An operator running this must be told the run was suppressed by our own pipeline health, not that every domain happened to be fine.',
        );
    }

    #[Test]
    public function aDomainIsNotAlertedTwiceForTheSameSilence(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domain = $this->domainReportingDailyUntil($em, daysAgo: 10);
        $this->provePipelineHealthy($em);
        $em->flush();

        $this->tester()->execute([]);
        $this->tester()->execute([]);

        self::assertCount(
            1,
            $this->allAlertsFor($em, $domain, AlertType::ReportsStopped),
            'A daily cron must not restate the same unresolved silence every morning. Repetition is how an alert becomes noise.',
        );
    }

    /**
     * A domain with a believable history: one report a day, ending `daysAgo`
     * days back. That history is what gives the domain an observed cadence to
     * be measured against.
     */
    private function domainReportingDailyUntil(EntityManagerInterface $em, int $daysAgo): MonitoredDomain
    {
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $domain = $persona->domain;
        assert($domain instanceof MonitoredDomain);

        $now = $this->getService(\Psr\Clock\ClockInterface::class)->now();

        for ($i = 0; $i < 14; ++$i) {
            $arrivedAt = $now->modify(sprintf('-%d days', $daysAgo + $i));

            $report = new DmarcReport(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                reporterOrg: 'google.com',
                reporterEmail: 'noreply-dmarc@google.com',
                externalReportId: 'cadence-'.$daysAgo.'-'.$i,
                dateRangeBegin: $arrivedAt->modify('-1 day'),
                dateRangeEnd: $arrivedAt,
                policyDomain: $domain->domain,
                policyAdkim: DmarcAlignment::Relaxed,
                policyAspf: DmarcAlignment::Relaxed,
                policyP: DmarcPolicy::None,
                policySp: null,
                policyPct: 100,
                rawXml: '<feedback/>',
                processedAt: $arrivedAt,
            );

            $em->persist($report);
        }

        $domain->firstReportAt = $now->modify(sprintf('-%d days', $daysAgo + 13));

        return $domain;
    }

    /**
     * Stamps a recent successful central-inbox poll, which is what entitles the
     * command to treat a quiet domain as the customer's problem.
     */
    private function provePipelineHealthy(EntityManagerInterface $em): void
    {
        $now = $this->getService(\Psr\Clock\ClockInterface::class)->now();

        $status = new IngestionSourceStatus(
            id: Uuid::uuid7(),
            source: IngestionSource::CentralInbox,
        );
        $status->recordSuccess($now->modify('-2 minutes'));

        $em->persist($status);
    }

    private function findAlertFor(EntityManagerInterface $em, MonitoredDomain $domain, AlertType $type): ?Alert
    {
        return $this->allAlertsFor($em, $domain, $type)[0] ?? null;
    }

    /**
     * @return list<Alert>
     */
    private function allAlertsFor(EntityManagerInterface $em, MonitoredDomain $domain, AlertType $type): array
    {
        $em->clear();

        /** @var list<Alert> $alerts */
        $alerts = $em->getRepository(Alert::class)->findBy([
            'monitoredDomain' => $domain->id,
            'type' => $type,
        ]);

        return $alerts;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('sendvery:ingestion:check-health'));
    }
}
