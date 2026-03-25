<?php

declare(strict_types=1);

namespace App\Services;

use Ramsey\Uuid\UuidInterface;

final class TeamContext
{
    private ?UuidInterface $currentTeamId = null;

    public function getCurrentTeamId(): ?UuidInterface
    {
        return $this->currentTeamId;
    }

    public function setCurrentTeamId(UuidInterface $teamId): void
    {
        $this->currentTeamId = $teamId;
    }
}
