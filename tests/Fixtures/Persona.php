<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;

final readonly class Persona
{
    public function __construct(
        public User $user,
        public Team $team,
        public TeamMembership $membership,
        public ?MonitoredDomain $domain = null,
    ) {
    }
}
