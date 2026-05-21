<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Spatie\Dns\Dns;
use Spatie\Dns\Records\Record;
use Spatie\Dns\Support\Domain;

/**
 * Test-environment replacement for Spatie\Dns\Dns. Returns no records for every
 * query, so checkers see "not configured" without hitting the network. Aliased
 * via config/services.php under when@test.
 *
 * Tests that need scripted DNS data (positive matches, simulated failures) build
 * a configured Dns themselves via App\Tests\Unit\Services\Dns\StubDns and inject
 * it directly into the checker under test.
 */
final class FakeDns extends Dns
{
    /**
     * @param Domain|string            $search
     * @param int|string|array<string> $types
     *
     * @return array<int, Record>
     */
    public function getRecords($search = '', $types = DNS_ALL): array
    {
        return [];
    }
}
