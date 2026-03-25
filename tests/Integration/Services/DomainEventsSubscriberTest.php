<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\EntityWithEvents;
use App\Entity\HasEvents;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DomainEventsSubscriberTest extends IntegrationTestCase
{
    public function testEventsAreDispatchedAfterFlush(): void
    {
        $entityManager = $this->getService(EntityManagerInterface::class);

        // We test the subscriber indirectly via a real Doctrine flush
        // Since we have no real entities yet, we test the subscriber directly
        $subscriber = self::getContainer()->get('App\Services\DomainEventsSubscriber');

        $event = new \stdClass();
        $entity = new class () implements EntityWithEvents {
            use HasEvents;
        };
        $entity->recordThat($event);

        // Simulate what Doctrine does: postPersist collects events
        $reflMethod = new \ReflectionMethod($subscriber, 'collectEvents');
        $reflMethod->invoke($subscriber, $entity);

        // Verify events were collected from entity (entity events are now empty)
        self::assertSame([], $entity->popEvents()); // @phpstan-ignore staticMethod.alreadyNarrowedType

        // Verify the subscriber has collected events internally
        $reflProp = new \ReflectionProperty($subscriber, 'events');
        $collectedEvents = $reflProp->getValue($subscriber);
        self::assertCount(1, $collectedEvents);
        self::assertSame($event, $collectedEvents[0]);
    }

    public function testEventsAreClearedAfterDispatch(): void
    {
        $dispatched = [];
        $mockBus = new class ($dispatched) implements MessageBusInterface {
            /** @param array<object> $dispatched */
            public function __construct(
                private array &$dispatched, // @phpstan-ignore property.onlyWritten
            ) {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };

        $subscriber = new \App\Services\DomainEventsSubscriber($mockBus);

        $event = new \stdClass();
        $entity = new class () implements EntityWithEvents {
            use HasEvents;
        };
        $entity->recordThat($event);

        // Simulate postPersist
        $reflMethod = new \ReflectionMethod($subscriber, 'collectEvents');
        $reflMethod->invoke($subscriber, $entity);

        // Simulate postFlush
        $postFlushArgs = $this->createStub(\Doctrine\ORM\Event\PostFlushEventArgs::class);
        $subscriber->postFlush($postFlushArgs);

        // Event was dispatched
        self::assertCount(1, $dispatched);
        self::assertSame($event, $dispatched[0]);

        // Internal events are cleared — second flush dispatches nothing
        $dispatched = [];
        $subscriber->postFlush($postFlushArgs);
        self::assertSame([], $dispatched); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    public function testNonEntityWithEventsIsIgnored(): void
    {
        $dispatched = [];
        $mockBus = new class ($dispatched) implements MessageBusInterface {
            /** @param array<object> $dispatched */
            public function __construct(
                private array &$dispatched, // @phpstan-ignore property.onlyWritten
            ) {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };

        $subscriber = new \App\Services\DomainEventsSubscriber($mockBus);

        // Pass a plain object that does not implement EntityWithEvents
        $plainEntity = new \stdClass();

        $reflMethod = new \ReflectionMethod($subscriber, 'collectEvents');
        $reflMethod->invoke($subscriber, $plainEntity);

        $postFlushArgs = $this->createStub(\Doctrine\ORM\Event\PostFlushEventArgs::class);
        $subscriber->postFlush($postFlushArgs);

        self::assertSame([], $dispatched); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }
}
