<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitoredDomain;
use App\Events\ManagedDmarcBecameReady;
use App\Message\AdvanceDmarcPolicy;
use App\Message\PauseAutoRamp;
use App\Message\ScheduleAutoRampAdvance;
use App\Message\SetDmarcPolicy;
use App\Query\GetDomainReadinessSignals;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\CloudflareDnsClient;
use App\Services\Dns\DmarcRampReadinessEvaluator;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Value\AlertType;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\PolicyChangeSource;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Daily, idempotent auto-drive (auto-ramp) sweep (DEC-058). For each managed
 * domain whose team still holds the entitlement: re-evaluate readiness, trip
 * safety rails first (pause on lost CNAME or regression; roll back at an
 * enforcing tier), then schedule the next tightening 48h out and execute due
 * advances after a fresh re-check. Guided (auto-drive OFF) domains get a one-time
 * "ready to advance" nudge. Per-domain try/catch so one domain can't abort the run.
 *
 * "A fresh re-check" is literal: a domain whose advance is DUE gets a live
 * `_dmarc` CNAME lookup before anything is evaluated, because tightening is the
 * one action here that can bounce real mail. It runs only for due domains — at
 * most twice in a domain's lifetime — so the sweep stays one DNS query per
 * advance, not per managed domain.
 */
#[AsCommand(
    name: 'sendvery:dmarc:auto-ramp',
    description: 'Safely auto-advance managed DMARC policies (none -> quarantine -> reject) with readiness gates and rollback',
)]
final class AutoRampDmarcCommand extends Command
{
    private const string ADVANCE_NOTICE = '+48 hours';
    private const int READY_NOTICE_DEDUP_DAYS = 30;

    public function __construct(
        private readonly CloudflareDnsClient $cloudflareClient,
        private readonly Connection $database,
        private readonly MonitoredDomainRepository $domainRepository,
        private readonly GetDomainReadinessSignals $readinessSignals,
        private readonly DmarcRampReadinessEvaluator $evaluator,
        private readonly ManagedDmarcCnameChecker $cnameChecker,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->cloudflareClient->isConfigured()) {
            $io->info('Cloudflare credentials not configured — skipping auto-ramp.');

            return Command::SUCCESS;
        }

        $now = $this->clock->now();
        $processed = 0;

        foreach ($this->eligibleManagedDomainIds() as $id) {
            try {
                $this->processDomain(Uuid::fromString($id), $now);
                ++$processed;
            } catch (\Throwable $e) {
                \Sentry\captureException($e);
                $io->warning(sprintf('Auto-ramp failed for domain %s: %s', $id, $e->getMessage()));
            }
        }

        $io->success(sprintf('Auto-ramp sweep complete: %d managed domain(s) processed.', $processed));

        return Command::SUCCESS;
    }

    private function processDomain(\Ramsey\Uuid\UuidInterface $domainId, \DateTimeImmutable $now): void
    {
        $domain = $this->domainRepository->get($domainId);
        $teamId = $domain->team->id;

        // A due advance is the only irreversible-in-practice step in this sweep:
        // once `p=reject` is published, mail that fails alignment is bounced, not
        // quarantined. So the stored verification is refreshed from live DNS
        // BEFORE readiness is evaluated, rather than trusted from whenever the
        // last nightly sweep happened to run.
        //
        // Refreshing first (instead of re-checking after the evaluator has
        // already passed) is what makes both directions honest: a live CNAME
        // whose nightly refresh was skipped is re-stamped and may proceed, and a
        // CNAME that vanished since the last sweep is cleared and trips the rail
        // below. `markCnameVerified()` ignores an inconclusive lookup, so a
        // transient resolver blip leaves the stored timestamp alone and the
        // evaluator's freshness gate decides.
        if ($domain->autoRampEnabled
            && null === $domain->autoRampPausedAt
            && $this->advanceIsDue($domain, $now)
        ) {
            $domain->markCnameVerified($this->cnameChecker->verify($domain->domain), $now);
        }

        $readiness = $this->evaluator->evaluate(
            $domain,
            $this->readinessSignals->forDomain($domain->id, [$teamId]),
        );

        // Guided mode (auto-drive off): nudge once when newly eligible.
        if (!$domain->autoRampEnabled) {
            if ($readiness->eligibleForNextTier
                && null !== $readiness->recommendedNextPolicy
                && !$this->hasRecentReadyAlert($domain->id->toString())
            ) {
                $this->commandBus->dispatch(new ManagedDmarcBecameReady(
                    $domain->id,
                    $teamId,
                    $domain->domain,
                    $readiness->recommendedNextPolicy->p,
                ));
            }

            return;
        }

        // Already paused — leave the policy live, do nothing.
        if (null !== $domain->autoRampPausedAt) {
            return;
        }

        // 1. Safety rails first. A lost CNAME freezes the ramp.
        if (!$readiness->cnameVerified) {
            $this->commandBus->dispatch(new PauseAutoRamp($domain->id, 'CNAME is no longer verified'));

            return;
        }

        // Regression: an authorized sender is failing. At an enforcing tier that
        // means real mail is being blocked — roll back AND pause (loosening is
        // instantly safe). At monitoring nothing is blocked, so just pause.
        if ($readiness->regressionDetected) {
            $this->rollBackIfEnforcing($domain, $teamId->toString(), $readiness->currentStage);
            $this->commandBus->dispatch(new PauseAutoRamp($domain->id, 'Alignment regressed — authorized mail started failing'));

            return;
        }

        // 2. A scheduled advance is due — execute only if STILL ready.
        if ($this->advanceIsDue($domain, $now)) {
            if ($readiness->eligibleForNextTier) {
                $this->commandBus->dispatch(new AdvanceDmarcPolicy($domain->id, $teamId->toString(), null, PolicyChangeSource::AutoRamp));
            } else {
                $this->commandBus->dispatch(new PauseAutoRamp($domain->id, 'Readiness regressed before the scheduled advance'));
            }

            return;
        }

        // 3. Newly eligible with no pending schedule — schedule the advance 48h out.
        if ($readiness->eligibleForNextTier && null === $domain->autoRampScheduledAdvanceAt) {
            $nextStage = $readiness->currentStage->next();
            if (null !== $nextStage && AutoRampStage::Complete !== $nextStage) {
                $this->commandBus->dispatch(new ScheduleAutoRampAdvance($domain->id, $nextStage, $now->modify(self::ADVANCE_NOTICE)));
            }
        }
    }

    private function advanceIsDue(MonitoredDomain $domain, \DateTimeImmutable $now): bool
    {
        return null !== $domain->autoRampScheduledAdvanceAt && $now >= $domain->autoRampScheduledAdvanceAt;
    }

    private function rollBackIfEnforcing(MonitoredDomain $domain, string $teamId, AutoRampStage $currentStage): void
    {
        if (AutoRampStage::Monitoring === $currentStage) {
            return;
        }

        // Monitoring is the only stage with no previous, and it's handled above —
        // so an enforcing stage always has a looser tier to roll back to.
        $previous = $currentStage->previous();
        assert(null !== $previous);

        $target = $previous->targetPolicy($domain->currentManagedPolicy());
        $this->commandBus->dispatch(new SetDmarcPolicy(
            $domain->id,
            $teamId,
            null,
            $target->p,
            $target->sp,
            $target->pct,
            PolicyChangeSource::Rollback,
        ));
    }

    /** @return list<string> */
    private function eligibleManagedDomainIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(
            "SELECT md.id
             FROM monitored_domain md
             JOIN team t ON t.id = md.team_id
             WHERE md.dmarc_setup_mode = 'managed_cname'
               AND t.plan <> 'free'
             ORDER BY md.created_at",
        )->fetchFirstColumn();

        return $ids;
    }

    private function hasRecentReadyAlert(string $domainId): bool
    {
        $count = $this->database->executeQuery(
            'SELECT COUNT(*) FROM alert
             WHERE monitored_domain_id = :domainId
               AND type = :type
               AND created_at > NOW() - (:days || \' days\')::interval',
            [
                'domainId' => $domainId,
                'type' => AlertType::ManagedDmarcReady->value,
                'days' => self::READY_NOTICE_DEDUP_DAYS,
            ],
        )->fetchOne();

        return (int) $count > 0;
    }
}
