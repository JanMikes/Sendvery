<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\EntityWithEvents;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DomainEventsSubscriber
{
    /** @var array<object> */
    private array $events = [];

    public function __construct(
        #[Target('event_bus')]
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collectEvents($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collectEvents($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->collectEvents($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $events = $this->events;
        $this->events = [];

        foreach ($events as $event) {
            $this->messageBus->dispatch($event);
        }
    }

    private function collectEvents(object $entity): void
    {
        if ($entity instanceof EntityWithEvents) {
            foreach ($entity->popEvents() as $event) {
                $this->events[] = $event;
            }
        }
    }
}
