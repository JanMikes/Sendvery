<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The four DNS-record categories the dashboard health page surfaces and the
 * {@see \App\Services\Dns\DnsRecordRecommender} reasons over (TASK-095).
 * Mirrors {@see \App\Value\DnsCheckType} one-for-one — they're not unified
 * because DnsCheckType is the persistence/check tag (used in queries and
 * messages) while this enum is the UI/recommendation tag (used in templates
 * and result DTOs). Keeping the two layers' enums distinct lets either side
 * evolve without rippling through the other.
 */
enum DnsRecordCategory: string
{
    case Spf = 'spf';
    case Dkim = 'dkim';
    case Dmarc = 'dmarc';
    case Mx = 'mx';
}
