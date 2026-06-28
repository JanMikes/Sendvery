<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * Who/what triggered a managed-DMARC policy change — recorded on every audit
 * row so the dashboard "Recent changes" panel can attribute each step:
 *  - Manual: the customer used the policy selector.
 *  - Guided: the customer clicked the one-click "advance" button.
 *  - AutoRamp: the scheduled auto-ramp cron tightened the policy.
 *  - Rollback: a safety rail loosened the policy after a regression.
 *  - DowngradeFreeze: a plan downgrade froze the ramp (policy stays put).
 */
enum PolicyChangeSource: string
{
    case Manual = 'manual';
    case Guided = 'guided';
    case AutoRamp = 'auto_ramp';
    case Rollback = 'rollback';
    case DowngradeFreeze = 'downgrade_freeze';
}
