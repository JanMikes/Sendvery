<?php

declare(strict_types=1);

namespace App\Value;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
}
