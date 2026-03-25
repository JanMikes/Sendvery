<?php

declare(strict_types=1);

namespace App;

use App\Message\CheckDomainDns;
use App\Message\PollMailbox;
use App\Message\SendWeeklyDigest;
use App\Repository\MailboxConnectionRepository;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private MailboxConnectionRepository $connectionRepository,
        private Connection $database,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        foreach ($this->connectionRepository->findActiveConnections() as $connection) {
            $schedule->add(
                RecurringMessage::every('15 minutes', new PollMailbox(
                    connectionId: $connection->id,
                )),
            );
        }

        $domainIds = $this->database->executeQuery(
            'SELECT id FROM monitored_domain ORDER BY created_at',
        )->fetchFirstColumn();

        foreach ($domainIds as $domainId) {
            $schedule->add(
                RecurringMessage::cron('0 3 * * *', new CheckDomainDns(
                    domainId: Uuid::fromString($domainId),
                )),
            );
        }

        // Weekly digest: Monday at 9:00 AM for each active team
        $teamIds = $this->database->executeQuery(
            'SELECT DISTINCT t.id FROM team t
             JOIN team_membership tm ON tm.team_id = t.id
             JOIN "user" u ON u.id = tm.user_id
             WHERE u.onboarding_completed_at IS NOT NULL',
        )->fetchFirstColumn();

        foreach ($teamIds as $teamId) {
            $schedule->add(
                RecurringMessage::cron('0 9 * * 1', new SendWeeklyDigest(
                    teamId: Uuid::fromString($teamId),
                )),
            );
        }

        return $schedule;
    }
}
