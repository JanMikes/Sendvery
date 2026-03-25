<?php

declare(strict_types=1);

namespace App;

use App\Message\PollMailbox;
use App\Repository\MailboxConnectionRepository;
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

        return $schedule;
    }
}
