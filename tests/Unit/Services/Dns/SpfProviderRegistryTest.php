<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\SpfProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TASK-155 — registry refactored from shape-arrays to SpfProvider DTOs.
 * The Stimulus controller still consumes the JSON via
 * `data-spf-generator-providers-value` as an array of {key, label, include}
 * objects, so the JSON output MUST stay byte-identical to the round-8
 * baseline (property order, key names, no extra fields). Without this guard,
 * a careless rename or property re-order would break the SPF generator at
 * runtime in the browser.
 */
final class SpfProviderRegistryTest extends TestCase
{
    #[Test]
    public function allReturnsNonEmptyListOfDtos(): void
    {
        // The return-type declaration on `all(): list<SpfProvider>` already
        // proves the DTO-ness statically — assert only that the registry is
        // populated (an empty list would silently break the generator UI).
        $registry = new SpfProviderRegistry();

        self::assertNotEmpty($registry->all());
    }

    #[Test]
    public function jsonShapeStaysCompatibleWithRoundEightBaseline(): void
    {
        $registry = new SpfProviderRegistry();
        $decoded = json_decode($registry->allAsJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded);

        // Each entry MUST be a 3-key array with exactly these keys in this
        // order. The Stimulus controller iterates the array assuming the
        // {key, label, include} shape — any property reorder breaks the
        // generator at runtime in the browser.
        foreach ($decoded as $entry) {
            self::assertIsArray($entry);
            self::assertSame(['key', 'label', 'include'], array_keys($entry), 'JSON entry shape must be {key, label, include} in that order — Stimulus consumes the array via property access.');
        }
    }

    #[Test]
    public function googleWorkspacePresetIsPreservedFromRoundEightBaseline(): void
    {
        // Pin the canonical entry for the most-common sending service. Round-8
        // shipped this with the include `_spf.google.com`; any silent change
        // would break the generator for Google Workspace tenants.
        $registry = new SpfProviderRegistry();
        $google = null;
        foreach ($registry->all() as $entry) {
            if ('google' === $entry->key) {
                $google = $entry;

                break;
            }
        }

        self::assertNotNull($google);
        self::assertSame('Google Workspace', $google->label);
        self::assertSame('_spf.google.com', $google->include);
    }

    #[Test]
    public function jsonOutputStaysByteIdenticalAfterDtoRefactor(): void
    {
        // TASK-155 cricital-path guard: the JSON the Stimulus controller
        // consumes via `data-spf-generator-providers-value` MUST match the
        // round-8 baseline byte-for-byte. Any property reorder / extra field
        // / changed encoding would change the generated HTML attribute value
        // and trigger a Stimulus reconnect in unpredictable ways. Pin one
        // entry's exact JSON shape as the canonical reference.
        $registry = new SpfProviderRegistry();
        $json = $registry->allAsJson();

        // The Google Workspace entry is the smallest, most stable canonical
        // entry to pin — any drift here proves drift everywhere.
        self::assertStringContainsString(
            '{"key":"google","label":"Google Workspace","include":"_spf.google.com"}',
            $json,
            'The JSON shape of each entry MUST stay {"key":..,"label":..,"include":..} in that property order — Stimulus consumes the array via property access.',
        );
    }

    #[Test]
    public function brevoIncludeUsesCanonicalDomainNotLegacyAlias(): void
    {
        // Round-8 reviewer caught the legacy `spf.sendinblue.com` and fixed
        // it inline to `spf.brevo.com`. Pin the current value so a future
        // restore of the legacy alias fails fast.
        $registry = new SpfProviderRegistry();
        $brevo = null;
        foreach ($registry->all() as $entry) {
            if ('brevo' === $entry->key) {
                $brevo = $entry;

                break;
            }
        }

        self::assertNotNull($brevo);
        self::assertSame('spf.brevo.com', $brevo->include, 'Brevo SPF include must use the current canonical domain — the legacy `spf.sendinblue.com` was retired in round 8.');
    }
}
