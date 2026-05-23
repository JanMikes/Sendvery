<?php

declare(strict_types=1);

namespace App\Value;

/**
 * TASK-006 — whitelist of tool-result micro-form sources. Persisted verbatim
 * into `beta_signup.source` so we can later split conversion analytics per
 * tool (e.g. "SPF results convert at 8%, DMARC at 12%"). Any new tool result
 * page that wants the micro-form must register a case here — the submission
 * endpoint rejects unknown source slugs.
 */
enum ToolNotifySource: string
{
    case Spf = 'spf-result';
    case Dkim = 'dkim-result';
    case Dmarc = 'dmarc-result';
    case Mx = 'mx-result';
    case EmailAuth = 'email-auth-result';
    case Blacklist = 'blacklist-result';
    case DomainHealth = 'domain-health-result';
}
