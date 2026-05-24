<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * Classifies how a single monitored domain's published DMARC `rua=` tag is
 * configured today (TASK-100). Drives scenario-aware ingestion recommendations
 * across the dashboard overview, the per-domain detail page, and the report
 * ingestion matrix.
 *
 * The three cases are mutually exclusive by construction:
 * - `NoRecord`         — no DMARC record (or no `rua=` tag) was discovered on
 *                        the last DNS check. The user must publish one before
 *                        anything else can ingest aggregate reports.
 * - `PointsAtSendvery` — at least one `rua=` address belongs to Sendvery
 *                        (the configured central inbox or any `@sendvery.com`
 *                        address). DNS is doing the work; mailbox CTAs are
 *                        suppressed for this domain.
 * - `PointsAtExternal` — `rua=` exists but points at an address the team owns
 *                        on a third-party domain. The user has two equivalent
 *                        next steps: connect that inbox so we can poll it, or
 *                        repoint DMARC to Sendvery.
 */
enum RuaScenario: string
{
    case NoRecord = 'no_record';
    case PointsAtSendvery = 'points_at_sendvery';
    case PointsAtExternal = 'points_at_external';
}
