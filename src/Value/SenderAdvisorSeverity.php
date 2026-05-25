<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Classifies the recommendation surfaced for a single {@see \App\Entity\KnownSender}
 * on the per-domain Sender Inventory page (TASK-092). Mutually exclusive with
 * the four branches of {@see \App\Services\SenderAuthorizationAdvisor}: a row
 * either gets a "make a decision" nudge (Authorize / Revoke), a "we're still
 * watching" hint (Monitor), or nothing at all (None) — never two simultaneously.
 */
enum SenderAdvisorSeverity: string
{
    case RecommendAuthorize = 'recommend_authorize';
    case RecommendRevoke = 'recommend_revoke';
    case Monitor = 'monitor';
    case None = 'none';
}
