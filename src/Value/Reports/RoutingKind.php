<?php

declare(strict_types=1);

namespace App\Value\Reports;

enum RoutingKind
{
    case Routed;
    case Quarantined;
    case Ignored;
}
