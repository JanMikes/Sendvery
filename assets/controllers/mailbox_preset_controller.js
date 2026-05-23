import { Controller } from '@hotwired/stimulus';

/*
 * Drives the provider-preset dropdown on the mailbox-setup wizard.
 *
 * Values:
 *   presets — JSON map keyed by preset key with { host, port, encryption,
 *             requiresAppPassword }. Computed in PHP via
 *             MailboxProviderPreset::presetsJson().
 *
 * Targets:
 *   select     — the <select> the user picks a preset from.
 *   host       — host <input>.
 *   port       — port <input>.
 *   encryption — encryption <select>.
 *   banner     — the yellow app-password explainer (hidden by default,
 *                shown when the picked preset has requiresAppPassword).
 *
 * "custom" never changes any field — the user fills it in manually.
 * Unknown keys are a no-op so the controller stays graceful if the
 * markup and the JSON drift apart.
 */
export default class extends Controller {
    static values = {
        presets: Object,
    };

    static targets = ['select', 'host', 'port', 'encryption', 'banner'];

    presetChanged() {
        const key = this.selectTarget.value;

        if (key === 'custom') {
            this.bannerTarget.hidden = true;

            return;
        }

        const preset = this.presetsValue[key];

        if (!preset) {
            return;
        }

        this.hostTarget.value = preset.host;
        this.portTarget.value = String(preset.port);
        this.encryptionTarget.value = preset.encryption;

        this.bannerTarget.hidden = !preset.requiresAppPassword;
    }
}
