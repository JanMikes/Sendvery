<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The single highest-value next step a team should take on the dashboard
 * overview. Picked by NextActionResolver and surfaced on the overview page
 * as a tinted call-to-action card.
 */
enum NextAction: string
{
    case AddDomain = 'add_domain';
    case VerifyDns = 'verify_dns';
    case WaitForReports = 'wait_for_reports';
    case ReviewAlerts = 'review_alerts';
    case ConnectMailbox = 'connect_mailbox';
    case AllHealthy = 'all_healthy';
}
