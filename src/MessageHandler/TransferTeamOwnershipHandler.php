<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exceptions\CannotTransferOwnership;
use App\Message\TransferTeamOwnership;
use App\Repository\TeamMembershipRepository;
use App\Value\TeamRole;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Moves the Owner role between two existing members of the same team. The
 * previous owner becomes an Admin so they keep elevated rights without
 * leaving the team entirely.
 *
 * Refuses if:
 *   - the new-owner user isn't already a member;
 *   - the current-owner user isn't actually the current owner;
 *   - the two ids are the same (no-op).
 */
#[AsMessageHandler]
final readonly class TransferTeamOwnershipHandler
{
    public function __construct(
        private TeamMembershipRepository $membershipRepository,
    ) {
    }

    public function __invoke(TransferTeamOwnership $message): void
    {
        if ($message->currentOwnerUserId->equals($message->newOwnerUserId)) {
            throw new CannotTransferOwnership('You cannot transfer ownership to yourself.');
        }

        $currentOwnership = $this->membershipRepository->findMembership(
            $message->currentOwnerUserId,
            $message->teamId,
        );
        if (null === $currentOwnership || TeamRole::Owner !== $currentOwnership->role) {
            throw new CannotTransferOwnership('Only the current Owner can transfer ownership.');
        }

        $incoming = $this->membershipRepository->findMembership(
            $message->newOwnerUserId,
            $message->teamId,
        );
        if (null === $incoming) {
            throw new CannotTransferOwnership('The person you want to make Owner must already be a member of the team.');
        }

        $incoming->role = TeamRole::Owner;
        $currentOwnership->role = TeamRole::Admin;
    }
}
