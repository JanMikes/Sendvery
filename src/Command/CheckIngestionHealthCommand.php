<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Query\GetDomainReportCadence;
use App\Repository\IngestionSourceStatusRepository;
use App\Results\DomainReportCadenceResult;
use App\Services\IdentityProvider;
use App\Services\IngestionSilenceEvaluator;
use App\Value\AlertType;
use App\Value\IngestionSource;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Raises an alert for any domain whose DMARC reports have stopped arriving, and
 * clears it when they resume.
 *
 * THE GAP THIS CLOSES: the "no reports have arrived" check was only ever
 * evaluated while `monitored_domain.first_report_at` was NULL, so it became
 * unreachable the moment a domain's first report landed. A domain that reported
 * every day for a year and then went silent produced nothing at all. For a
 * product whose promise is monitoring, its own monitoring stopping was the one
 * event it could not see.
 *
 * ORDER OF PROOF — this is the important part. Before any domain is called
 * quiet, the command establishes that OUR side works. If the central inbox
 * poller has not provably succeeded recently, every domain in the system looks
 * silent for exactly the same reason, and that reason is us. In that state the
 * command alerts nobody and says so. Telling a customer "your reports stopped,
 * check your rua= tag" while our own poller is stuck is how users get sent to
 * fix DNS that was correct all along.
 *
 * De-duplicated by alert lifecycle rather than by a timestamp: one unresolved
 * ReportsStopped alert per domain, resolved automatically when reports resume.
 * A daily cron restating the same unresolved silence every morning is how an
 * alert becomes something people filter to a folder.
 */
#[AsCommand(
    name: 'sendvery:ingestion:check-health',
    description: 'Alert on domains whose DMARC reports have stopped arriving, once our own pipeline is proven healthy.',
)]
final class CheckIngestionHealthCommand extends Command
{
    public function __construct(
        private readonly GetDomainReportCadence $cadenceQuery,
        private readonly IngestionSilenceEvaluator $evaluator,
        private readonly IngestionSourceStatusRepository $sourceStatusRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityProvider $identityProvider,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'ignore-pipeline-health',
            null,
            InputOption::VALUE_NONE,
            'Evaluate domains even when our own ingestion is unproven. For local runs and drills only — never for the cron.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        if (!$input->getOption('ignore-pipeline-health') && !$this->isOurPipelineProvenHealthy($now)) {
            $io->warning(
                'Skipped: our own ingestion pipeline is not proven healthy, so every domain would look silent for a reason that is ours. No alerts raised.',
            );

            return Command::SUCCESS;
        }

        $raised = 0;
        $cleared = 0;

        foreach ($this->cadenceQuery->forAllDomains() as $cadence) {
            $domain = $this->entityManager->find(MonitoredDomain::class, Uuid::fromString($cadence->domainId));

            if (!$domain instanceof MonitoredDomain) {
                continue;
            }

            $existing = $this->unresolvedAlertFor($domain);

            if ($this->evaluator->isSilent($cadence, $now)) {
                if (null !== $existing) {
                    continue;
                }

                $this->entityManager->persist($this->buildAlert($domain, $cadence, $now));
                ++$raised;

                continue;
            }

            // Reports resumed. Leaving the alert open would make the alerts
            // page a list of problems that fixed themselves, which is how a
            // page stops being read.
            if (null !== $existing) {
                $existing->resolve($now);
                ++$cleared;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Ingestion health checked. %d domain(s) newly silent, %d resumed.', $raised, $cleared));

        return Command::SUCCESS;
    }

    /**
     * Absent evidence is not evidence of health. A source with no row at all —
     * a fresh deployment, or a poller that has never once succeeded — answers
     * false here on purpose.
     */
    private function isOurPipelineProvenHealthy(\DateTimeImmutable $now): bool
    {
        $status = $this->sourceStatusRepository->find(IngestionSource::CentralInbox);

        return null !== $status && $status->isProvenHealthyAt($now);
    }

    private function unresolvedAlertFor(MonitoredDomain $domain): ?Alert
    {
        $alert = $this->entityManager->getRepository(Alert::class)->findOneBy([
            'monitoredDomain' => $domain->id,
            'type' => AlertType::ReportsStopped,
            'resolvedAt' => null,
        ]);

        return $alert instanceof Alert ? $alert : null;
    }

    private function buildAlert(
        MonitoredDomain $domain,
        DomainReportCadenceResult $cadence,
        \DateTimeImmutable $now,
    ): Alert {
        $silentDays = max(1, (int) floor(($now->getTimestamp() - $cadence->lastReportAt->getTimestamp()) / 86400));

        return new Alert(
            id: $this->identityProvider->nextIdentity(),
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::ReportsStopped,
            severity: AlertType::ReportsStopped->defaultSeverity(),
            title: sprintf('DMARC reports stopped for %s', $domain->domain),
            message: sprintf(
                'The last DMARC report for %s arrived %d day%s ago, and reports had been averaging about one every %s. '
                .'Our own ingestion is running normally, so the change is at the reporting end. '
                .'The usual causes are an edited or removed rua= tag, or the domain simply no longer sending mail — '
                .'if you meant to stop, nothing here needs fixing.',
                $domain->domain,
                $silentDays,
                1 === $silentDays ? '' : 's',
                $this->describeCadence($cadence),
            ),
            data: [
                'last_report_at' => $cadence->lastReportAt->format(\DateTimeInterface::ATOM),
                'silent_days' => $silentDays,
                'observed_median_gap_seconds' => $cadence->medianGapSeconds,
                'threshold_seconds' => $this->evaluator->silenceThresholdSeconds($cadence),
            ],
            createdAt: $now,
        );
    }

    /**
     * Reads back the domain's OWN measured rhythm, so the alert explains why
     * this particular silence is abnormal for this particular domain rather
     * than quoting a global constant the owner has no reason to accept.
     */
    private function describeCadence(DomainReportCadenceResult $cadence): string
    {
        if (null === $cadence->medianGapSeconds) {
            return 'once we had only seen a single report';
        }

        $hours = (int) round($cadence->medianGapSeconds / 3600);

        if ($hours < 1) {
            return 'less than an hour';
        }

        if ($hours < 48) {
            return sprintf('%d hour%s', $hours, 1 === $hours ? '' : 's');
        }

        $days = (int) round($hours / 24);

        return sprintf('%d days', $days);
    }
}
