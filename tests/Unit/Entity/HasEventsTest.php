<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EntityWithEvents;
use App\Entity\HasEvents;
use PHPUnit\Framework\TestCase;

final class HasEventsTest extends TestCase
{
    public function testRecordThatStoresEvents(): void
    {
        $entity = $this->createEntityWithEvents();
        $event = new \stdClass();

        $entity->recordThat($event);

        $events = $entity->popEvents();
        self::assertCount(1, $events);
        self::assertSame($event, $events[0]);
    }

    public function testPopEventsClearsEvents(): void
    {
        $entity = $this->createEntityWithEvents();
        $entity->recordThat(new \stdClass());

        $entity->popEvents();
        $events = $entity->popEvents();

        self::assertSame([], $events);
    }

    public function testMultipleEventsReturnedInOrder(): void
    {
        $entity = $this->createEntityWithEvents();
        $first = new \stdClass();
        $first->name = 'first';
        $second = new \stdClass();
        $second->name = 'second';
        $third = new \stdClass();
        $third->name = 'third';

        $entity->recordThat($first);
        $entity->recordThat($second);
        $entity->recordThat($third);

        $events = $entity->popEvents();
        self::assertCount(3, $events);
        self::assertSame($first, $events[0]);
        self::assertSame($second, $events[1]);
        self::assertSame($third, $events[2]);
    }

    public function testPopEventsReturnsEmptyArrayWhenNoEvents(): void
    {
        $entity = $this->createEntityWithEvents();

        self::assertSame([], $entity->popEvents());
    }

    private function createEntityWithEvents(): EntityWithEvents
    {
        return new class implements EntityWithEvents {
            use HasEvents;
        };
    }
}
