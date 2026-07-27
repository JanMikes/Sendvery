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
 *
 * Intentionally three cases — there is no "awaiting first report" verdict.
 * "We have no pass-rate data yet" is a property of the *data* (a null
 * `DomainOverviewResult::$passRate`) rendered by the shared `pass_rate_stat`
 * Twig macro, not a fourth health state: adding a case here would demand a
 * fourth filter chip, glyph tone, `HealthSummaryResolver` bucket and SQL
 * predicate for a state that resolves itself within a day. See
 * {@see \App\Services\DomainHealthClassifier::awaitingFirstReportVerdict()}.
 */
enum DomainHealthFilter: string
{
    case Healthy = 'healthy';
    case Attention = 'attention';
    case Unverified = 'unverified';
}
