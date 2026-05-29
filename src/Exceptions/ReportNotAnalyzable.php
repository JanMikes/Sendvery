<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by the AI orchestrator when a report can't be turned into facts —
 * it isn't visible to the team, or it vanished mid-request. Throwing (rather
 * than returning a placeholder string) keeps the caching decorator from
 * persisting a "not found" sentinel under the report's immutable cache key, and
 * keeps the quota gate from charging for a non-answer (both happen only after
 * the inner call returns).
 */
final class ReportNotAnalyzable extends \RuntimeException
{
}
