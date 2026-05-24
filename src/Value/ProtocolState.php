<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Per-protocol setup state surfaced by {@see \App\Services\DomainSetupStatusResolver}.
 * Drives the per-row checklist render on the domain detail page (TASK-080) and
 * — aggregated across the four protocols — the headline tone on the status
 * banner (TASK-067).
 */
enum ProtocolState: string
{
    case Configured = 'configured';
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Unknown = 'unknown';
}
