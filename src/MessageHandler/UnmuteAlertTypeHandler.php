<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\UnmuteAlertType;
use App\Repository\MutedAlertRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UnmuteAlertTypeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MutedAlertRepository $mutedAlertRepository,
    ) {
    }

    public function __invoke(UnmuteAlertType $message): void
    {
        $muted = $this->mutedAlertRepository->get($message->mutedAlertId);

        $this->entityManager->remove($muted);
    }
}
