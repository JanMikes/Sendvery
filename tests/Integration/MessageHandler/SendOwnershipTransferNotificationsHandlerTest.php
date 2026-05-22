<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Entity\User;
use App\Message\SendOwnershipTransferNotifications;
use App\MessageHandler\SendOwnershipTransferNotificationsHandler;
use App\Services\IdentityProvider;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class SendOwnershipTransferNotificationsHandlerTest extends WebTestCase
{
    public function testSendsOneEmailToEachSideOfTheTransfer(): void
    {
        self::createClient(); // boot the kernel + mail collector

        $em = $this->getService(EntityManagerInterface::class);
        $identityProvider = $this->getService(IdentityProvider::class);
        $handler = $this->getService(SendOwnershipTransferNotificationsHandler::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Ownership Mail Test',
            slug: 'ownership-mail-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $previousOwner = new User(
            id: $identityProvider->nextIdentity(),
            email: 'previous-'.bin2hex(random_bytes(4)).'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $newOwner = new User(
            id: $identityProvider->nextIdentity(),
            email: 'new-'.bin2hex(random_bytes(4)).'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($previousOwner);
        $em->persist($newOwner);
        $em->flush();

        ($handler)(new SendOwnershipTransferNotifications(
            teamId: $team->id,
            newOwnerUserId: $newOwner->id,
            previousOwnerUserId: $previousOwner->id,
        ));

        self::assertEmailCount(2);

        $messages = self::getMailerMessages();
        $recipients = [];
        $subjects = [];
        foreach ($messages as $message) {
            assert($message instanceof \Symfony\Component\Mime\Email);
            $recipients[] = $message->getTo()[0]->getAddress();
            $subjects[] = (string) $message->getSubject();
        }

        self::assertContains($newOwner->email, $recipients);
        self::assertContains($previousOwner->email, $recipients);

        self::assertTrue(
            (bool) array_filter($subjects, static fn (string $s) => str_contains($s, 'now the Owner')),
            'one email tells the new owner about their promotion',
        );
        self::assertTrue(
            (bool) array_filter($subjects, static fn (string $s) => str_contains($s, 'has been transferred')),
            'one email confirms to the previous owner that ownership moved',
        );
    }
}
