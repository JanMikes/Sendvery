<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Classifies the kind of trouble a single {@see \App\Entity\MailboxConnection}
 * is in for the per-mailbox health advisor card (TASK-094). The three cases
 * are deliberately mutually exclusive — the {@see \App\Services\MailboxHealthAdvisor}
 * picks one (or returns null for healthy) so the UI never has to merge or
 * prioritise two badges on the same surface.
 */
enum MailboxHealthSeverity: string
{
    case BrokenCredentials = 'broken_credentials';
    case SilentForTooLong = 'silent_for_too_long';
    case QuarantineDominant = 'quarantine_dominant';
}
