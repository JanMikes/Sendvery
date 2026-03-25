<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Alert;
use App\Exceptions\AlertNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class AlertRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): Alert
    {
        $alert = $this->entityManager->find(Alert::class, $id);

        if (null === $alert) {
            throw new AlertNotFound(sprintf('Alert with ID "%s" not found.', $id->toString()));
        }

        return $alert;
    }
}
