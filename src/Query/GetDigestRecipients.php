<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\Connection;

/**
 * Team members who have asked for the weekly digest.
 *
 * Shared by the send handler and by `sendvery:digest:send-all --preview`, so a
 * preview can tell you the digest you are admiring would reach nobody — a team
 * whose members all turned the digest off is not a rendering problem and looks
 * identical to a healthy one on screen.
 */
final readonly class GetDigestRecipients
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function forTeam(string $teamId): array
    {
        /** @var list<string> $emails */
        $emails = $this->database->executeQuery(
            'SELECT u.email
             FROM "user" u
             JOIN team_membership tm ON tm.user_id = u.id
             WHERE tm.team_id = :teamId
               AND u.email_digest_enabled = true',
            ['teamId' => $teamId],
        )->fetchFirstColumn();

        return $emails;
    }
}
