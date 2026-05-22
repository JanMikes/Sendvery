<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by `PlanGatedAiInsightsService::explainReport` when the team has
 * burned its monthly on-demand quota. Carries `used` and `limit` so the
 * UI can render an accurate "X of Y used this month" message.
 */
final class AiQuotaExceeded extends \DomainException
{
    public function __construct(
        public readonly int $used,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'On-demand AI quota exceeded: %d of %d used this month.',
            $used,
            $limit,
        ));
    }
}
