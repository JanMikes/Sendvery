<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Three-state verdict for "is this domain set up correctly?" — drives the
 * severity glyph + tone on the `/app/domains` cards, the headline severity
 * on the `/app/domains/{id}` banner, and the bucket counts on the `/app`
 * HealthSummary card.
 *
 * Classification logic lives in {@see \App\Services\DomainHealthClassifier}
 * (TASK-098). The enum itself is intentionally a plain enum — keeping
 * classification out of value-objects lets every surface depend on the same
 * service and avoids re-creating the green-on-list / yellow-on-detail
 * divergence the original `fromOverview()` static caused.
 */
enum DomainHealthFilter: string
{
    case Healthy = 'healthy';
    case Attention = 'attention';
    case Unverified = 'unverified';
}
