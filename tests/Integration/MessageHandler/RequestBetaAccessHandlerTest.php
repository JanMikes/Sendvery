<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\BetaAccessRequest;
use App\Message\RequestBetaAccess;
use App\MessageHandler\RequestBetaAccessHandler;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class RequestBetaAccessHandlerTest extends IntegrationTestCase
{
    public function testPersistsRequestEntity(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = self::getContainer()->get(RequestBetaAccessHandler::class);
        assert($handler instanceof RequestBetaAccessHandler);

        $id = Uuid::uuid7();
        $handler(new RequestBetaAccess(
            requestId: $id,
            email: 'request-'.$id->toString().'@example.com',
            name: 'Alex',
            company: 'Globex',
            requestedPlan: SubscriptionPlan::Business,
            domainCount: 42,
            message: 'Looking forward to it',
            source: 'pricing',
        ));
        $em->flush();

        $entity = $em->find(BetaAccessRequest::class, $id);
        self::assertNotNull($entity);
        self::assertSame('Alex', $entity->name);
        self::assertSame('Globex', $entity->company);
        self::assertSame(SubscriptionPlan::Business, $entity->requestedPlan);
        self::assertSame(42, $entity->domainCount);
        self::assertSame('Looking forward to it', $entity->message);
    }

    public function testPersistsWithNullableFieldsOmitted(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = self::getContainer()->get(RequestBetaAccessHandler::class);
        assert($handler instanceof RequestBetaAccessHandler);

        $id = Uuid::uuid7();
        $handler(new RequestBetaAccess(
            requestId: $id,
            email: 'minimal-'.$id->toString().'@example.com',
            name: 'Solo',
            company: null,
            requestedPlan: SubscriptionPlan::Personal,
            domainCount: null,
            message: null,
            source: 'request-access',
        ));
        $em->flush();

        $entity = $em->find(BetaAccessRequest::class, $id);
        self::assertNotNull($entity);
        self::assertNull($entity->company);
        self::assertNull($entity->domainCount);
        self::assertNull($entity->message);
    }
}
