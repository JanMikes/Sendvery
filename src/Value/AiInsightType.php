<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The five kinds of AI insight Sendvery persists in `ai_insight`. The value is
 * part of the durable cache key, so renaming a case is a data migration.
 */
enum AiInsightType: string
{
    case ReportExplanation = 'report_explanation';
    case WeeklyDigest = 'weekly_digest';
    case AnomalyExplanation = 'anomaly_explanation';
    case Remediation = 'remediation';
    case SenderLabel = 'sender_label';
}
