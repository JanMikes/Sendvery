<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exceptions\CannotRemoveTeamOwner;
use App\Message\RemoveTeamMember;
use App\Repository\TeamMembershipRepository;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Removes a teammate. Refuses to remove the Owner — that requires an
 * explicit ownership transfer first (see TransferTeamOwnershipHandler) so a
 * team can never end up ownerless.
 */
#[AsMessageHandler]
final readonly class RemoveTeamMemberHandler
{
    public function __construct(
        private TeamMembershipRepository $membershipRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RemoveTeamMember $message): void
    {
        $membership = $this->membershipRepository->get($message->membershipId);

        if (TeamRole::Owner === $membership->role) {
            throw new CannotRemoveTeamOwner('Transfer ownership first, then remove the previous owner.');
        }

        $this->entityManager->remove($membership);
    }
}
