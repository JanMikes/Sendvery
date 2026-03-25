<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Team;
use App\Entity\User;
use App\Repository\TeamMembershipRepository;
use App\Value\TeamRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Team>
 */
final class TeamVoter extends Voter
{
    public const string VIEW = 'TEAM_VIEW';
    public const string EDIT = 'TEAM_EDIT';
    public const string DELETE = 'TEAM_DELETE';
    public const string MANAGE_MEMBERS = 'TEAM_MANAGE_MEMBERS';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::EDIT,
        self::DELETE,
        self::MANAGE_MEMBERS,
    ];

    public function __construct(
        private readonly TeamMembershipRepository $membershipRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true) && $subject instanceof Team;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        assert($subject instanceof Team);

        $membership = $this->membershipRepository->findMembership($user->id, $subject->id);

        if ($membership === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::EDIT => in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true),
            self::DELETE => $membership->role === TeamRole::Owner,
            self::MANAGE_MEMBERS => in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true),
            default => false,
        };
    }
}
