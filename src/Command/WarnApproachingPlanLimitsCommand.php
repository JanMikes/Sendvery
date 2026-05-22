<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TeamMembershipRepository;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use App\Value\SubscriptionPlan;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends a single "approaching plan limit" email to the owner of any team
 * that crossed 80% on at least one of: domain cap, seat cap, monthly
 * report cap, or monthly AI quota.
 *
 * De-dup: stamps `team.plan_warning_at`. The Upgrade/Downgrade/SetTeamPlan
 * handlers clear this column on plan change, which is the natural reset
 * point — a new plan deserves a fresh round of warnings if it's tight.
 * Without a plan change, one email per team per plan window is enough.
 */
#[AsCommand(
    name: 'sendvery:plan-limits:warn-approaching',
    description: 'Email team owners when usage crosses 80% of any plan cap (domains, seats, reports, AI quota).',
)]
final class WarnApproachingPlanLimitsCommand extends Command
{
    private const float THRESHOLD = 0.8;

    public function __construct(
        private readonly Connection $database,
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamMembershipRepository $membershipRepository,
        private readonly PlanEnforcement $enforcement,
        private readonly PlanLimits $limits,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $teams = $this->database
            ->executeQuery(
                'SELECT id, plan, plan_warning_at
                 FROM team
                 WHERE plan_warning_at IS NULL',
            )
            ->fetchAllAssociative();

        $notified = 0;
        $now = $this->clock->now();

        foreach ($teams as $row) {
            $plan = SubscriptionPlan::tryFrom((string) $row['plan']);
            if (null === $plan || SubscriptionPlan::Unlimited === $plan) {
                continue;
            }

            $teamId = Uuid::fromString((string) $row['id']);
            $teamIdString = $teamId->toString();

            $reasons = $this->triggeredReasons($teamIdString, $plan);
            if ([] === $reasons) {
                continue;
            }

            $ownership = $this->membershipRepository->findOwnerForTeam($teamId);
            if (null === $ownership) {
                // Orphan team — log via output and move on; nothing to email.
                $io->warning(sprintf('Team %s has no owner — skipping warning.', $teamIdString));

                continue;
            }

            $this->mailer->send($this->buildEmail($ownership->user->email, $ownership->team->name, $reasons));

            $ownership->team->planWarningAt = $now;
            ++$notified;
        }

        $this->entityManager->flush();

        if (0 === $notified) {
            $io->info('No teams crossed an 80% threshold this run.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Notified %d team owner(s) of approaching plan limits.', $notified));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function triggeredReasons(string $teamId, SubscriptionPlan $plan): array
    {
        $reasons = [];

        $domainCount = $this->enforcement->getDomainCount($teamId);
        $domainCap = $this->limits->getMaxDomains($plan);
        if ($this->crossed($domainCount, $domainCap)) {
            $reasons[] = sprintf('%d of %d domains used', $domainCount, $domainCap);
        }

        $memberCount = $this->enforcement->getTeamMemberCount($teamId);
        $memberCap = $this->limits->getMaxTeamMembers($plan);
        if ($this->crossed($memberCount, $memberCap)) {
            $reasons[] = sprintf('%d of %d team members', $memberCount, $memberCap);
        }

        $reportCount = $this->enforcement->getMonthlyReportCount($teamId);
        $reportCap = $this->limits->getMaxReportsPerMonth($plan);
        if ($this->crossed($reportCount, $reportCap)) {
            $reasons[] = sprintf('%d of %d monthly reports parsed', $reportCount, $reportCap);
        }

        if ($plan->hasAi()) {
            $aiUsed = $this->enforcement->getOnDemandAiUsage($teamId);
            $aiCap = $this->limits->getOnDemandAiQuota($plan);
            if ($this->crossed($aiUsed, $aiCap)) {
                $reasons[] = sprintf('%d of %d AI explanations used this month', $aiUsed, $aiCap);
            }
        }

        return $reasons;
    }

    private function crossed(int $used, int $cap): bool
    {
        // PHP_INT_MAX caps for Unlimited would never trip anyway — guard
        // against division-by-zero on a 0 cap (shouldn't happen but cheap).
        if ($cap <= 0) {
            return false;
        }

        return ($used / $cap) >= self::THRESHOLD;
    }

    /**
     * @param list<string> $reasons
     */
    private function buildEmail(string $recipient, string $teamName, array $reasons): Email
    {
        $body = sprintf("Hi,\n\nYour Sendvery team \"%s\" is approaching one or more plan limits:\n\n", $teamName);
        foreach ($reasons as $reason) {
            $body .= " • {$reason}\n";
        }
        $body .= "\nUpgrade your plan if you'd like more headroom: https://sendvery.com/app/settings/billing\n\n— Sendvery";

        return (new Email())
            ->to($recipient)
            ->subject('Approaching your Sendvery plan limits')
            ->text($body);
    }
}
