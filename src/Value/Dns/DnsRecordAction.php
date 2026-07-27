<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The literal verb the user performs in their DNS provider's UI.
 *
 * Cloudflare, Vercel and Netlify all lead their DNS panels with the operation
 * ("Add record" / "Edit"), not with the record's state, because "you have no
 * SPF record" does not tell anyone which button to press. Making the verb an
 * explicit, precomputed part of the model keeps that phrasing out of Twig and
 * consistent on every surface that renders a record.
 */
enum DnsRecordAction: string
{
    case AddNew = 'add_new';
    case EditExisting = 'edit_existing';
    case NothingToDo = 'nothing_to_do';

    public function label(): string
    {
        return match ($this) {
            self::AddNew => 'Add a new record',
            self::EditExisting => 'Edit the existing record',
            self::NothingToDo => 'Nothing to do',
        };
    }

    /**
     * Decided by whether a record already exists at the host we're targeting —
     * replacing a value and creating one from scratch are different tasks in
     * every DNS UI, and telling the user "add" when a conflicting record is
     * already there is how people end up with two SPF records.
     */
    public static function forCurrentValue(?string $currentValue): self
    {
        return null !== $currentValue && '' !== trim($currentValue)
            ? self::EditExisting
            : self::AddNew;
    }

    public function tone(): string
    {
        return match ($this) {
            self::AddNew => 'primary',
            self::EditExisting => 'warning',
            self::NothingToDo => 'success',
        };
    }
}
