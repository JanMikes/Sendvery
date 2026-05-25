<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\MxPreset;
use App\Value\MxPresetRecord;

/**
 * Canonical MX record sets for the major mailbox providers, used by the
 * public MX-generator UI. The Microsoft preset uses a `your-tenant`
 * placeholder host that the Stimulus controller rewrites client-side with
 * the user-supplied tenant slug.
 *
 * TASK-155: entries are MxPreset value objects rather than shape arrays
 * (per CLAUDE.md "objects over arrays"). `allAsJson()` emits byte-identical
 * JSON to the round-8 array shape via JsonSerializable property order.
 */
final readonly class MxPresetRegistry
{
    /**
     * @return list<MxPreset>
     */
    public function all(): array
    {
        return [
            new MxPreset('google', 'Google Workspace', [
                new MxPresetRecord(1,  'ASPMX.L.GOOGLE.COM'),
                new MxPresetRecord(5,  'ALT1.ASPMX.L.GOOGLE.COM'),
                new MxPresetRecord(5,  'ALT2.ASPMX.L.GOOGLE.COM'),
                new MxPresetRecord(10, 'ALT3.ASPMX.L.GOOGLE.COM'),
                new MxPresetRecord(10, 'ALT4.ASPMX.L.GOOGLE.COM'),
            ]),
            new MxPreset('microsoft', 'Microsoft 365', [
                new MxPresetRecord(0, 'your-tenant.mail.protection.outlook.com'),
            ]),
            new MxPreset('protonmail', 'Proton Mail', [
                new MxPresetRecord(10, 'mail.protonmail.ch'),
                new MxPresetRecord(20, 'mailsec.protonmail.ch'),
            ]),
            new MxPreset('fastmail', 'Fastmail', [
                new MxPresetRecord(10, 'in1-smtp.messagingengine.com'),
                new MxPresetRecord(20, 'in2-smtp.messagingengine.com'),
            ]),
            new MxPreset('zoho', 'Zoho Mail', [
                new MxPresetRecord(10, 'mx.zoho.com'),
                new MxPresetRecord(20, 'mx2.zoho.com'),
                new MxPresetRecord(50, 'mx3.zoho.com'),
            ]),
        ];
    }

    /**
     * JSON payload for the Stimulus controller's
     * `data-mx-generator-presets-value` attribute.
     */
    public function allAsJson(): string
    {
        return json_encode($this->all(), JSON_THROW_ON_ERROR);
    }
}
