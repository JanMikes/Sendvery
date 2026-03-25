<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KnownSender;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class KnownSenderRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): KnownSender
    {
        $sender = $this->entityManager->find(KnownSender::class, $id);

        if (null === $sender) {
            throw new \RuntimeException(sprintf('Known sender with ID "%s" not found.', $id->toString()));
        }

        return $sender;
    }
}
