<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\MxPresetRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TASK-155 — registry refactored from shape-arrays to MxPreset / MxPresetRecord
 * DTOs. The Stimulus controller still consumes the JSON via
 * `data-mx-generator-presets-value` as an array of {key, label, records: [...]}
 * objects with nested {priority, host} records — JSON output MUST stay
 * byte-identical to the round-8 baseline.
 */
final class MxPresetRegistryTest extends TestCase
{
    #[Test]
    public function allReturnsNonEmptyListOfDtosWithNonEmptyRecords(): void
    {
        // The return-type declaration on `all(): list<MxPreset>` already
        // proves the DTO-ness statically — assert only that the registry is
        // populated AND every preset has at least one record (an empty
        // record list would silently break the generated MX output).
        $registry = new MxPresetRegistry();

        $entries = $registry->all();
        self::assertNotEmpty($entries);
        foreach ($entries as $entry) {
            self::assertNotEmpty($entry->records, sprintf('MX preset "%s" must carry at least one record — an empty record list breaks the generated MX output.', $entry->key));
        }
    }

    #[Test]
    public function jsonShapeStaysCompatibleWithRoundEightBaseline(): void
    {
        $registry = new MxPresetRegistry();
        $decoded = json_decode($registry->allAsJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded);

        foreach ($decoded as $entry) {
            self::assertIsArray($entry);
            self::assertSame(['key', 'label', 'records'], array_keys($entry), 'JSON preset shape must be {key, label, records} in that order — Stimulus consumes the array via property access.');
            self::assertIsArray($entry['records']);
            self::assertNotEmpty($entry['records']);
            foreach ($entry['records'] as $record) {
                self::assertSame(['priority', 'host'], array_keys($record), 'Nested record shape must be {priority, host} — same Stimulus dependency.');
            }
        }
    }

    #[Test]
    public function microsoftPresetCarriesYourTenantPlaceholderHost(): void
    {
        // The Stimulus controller substitutes `your-tenant` with the user-typed
        // slug at generate time. The placeholder MUST be exactly that literal
        // — a rename to "tenant-name" or similar would break the substitution.
        $registry = new MxPresetRegistry();
        $microsoft = null;
        foreach ($registry->all() as $entry) {
            if ('microsoft' === $entry->key) {
                $microsoft = $entry;

                break;
            }
        }

        self::assertNotNull($microsoft);
        self::assertCount(1, $microsoft->records);
        self::assertSame('your-tenant.mail.protection.outlook.com', $microsoft->records[0]->host, 'Microsoft 365 preset must carry the literal `your-tenant` placeholder host — the Stimulus controller rewrites this with the user-typed tenant slug at generate time.');
    }
}
